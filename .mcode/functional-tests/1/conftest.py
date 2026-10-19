"""
Shared fixtures/helpers for Magento_Tag backend functional tests.

These tests exercise the real, running `magento2` storefront (PHP built-in server on
$PORT, default 8082) and the real MySQL database behind it (DB_HOST/DB_NAME/DB_USER/
DB_PASSWORD env vars, defaulting to the lifecycle's local dev credentials). No mocks:
customers/products are created through the application's own REST API, storefront
login goes through the real customer/account/loginPost form (session cookie + form
key, exactly like a browser), and the tag add flow goes through the real
tag/index/save controller. Every piece of test data is namespaced with an "ftrun_"
prefix and removed in fixture teardown.
"""
import os
import re
import uuid
from contextlib import contextmanager

import pymysql
import pymysql.cursors
import pytest
import requests

BASE_URL = os.environ.get("MAGENTO_BASE_URL", f"http://127.0.0.1:{os.environ.get('PORT', '8082')}")
DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_USER = os.environ.get("DB_USER", "magento")
DB_PASSWORD = os.environ.get("DB_PASSWORD", "magento")
DB_NAME = os.environ.get("DB_NAME", "magento")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "Admin123!")

NAMESPACE = "ftrun"
TIMEOUT = 20


def unique_suffix() -> str:
    return uuid.uuid4().hex[:10]


def db_connect():
    return pymysql.connect(
        host=DB_HOST,
        user=DB_USER,
        password=DB_PASSWORD,
        database=DB_NAME,
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
    )


@contextmanager
def db_cursor():
    conn = db_connect()
    try:
        with conn.cursor() as cur:
            yield cur
    finally:
        conn.close()


def extract_form_key(html: str) -> str:
    m = re.search(r'name="form_key"[^>]*value="([^"]+)"', html)
    assert m, "could not find form_key hidden input in page HTML"
    return m.group(1)


def current_form_key(session: requests.Session) -> str:
    """Fetch a fresh page in the given session and pull the current form_key out of it."""
    r = session.get(f"{BASE_URL}/customer/account/", timeout=TIMEOUT)
    if r.status_code != 200 or 'name="form_key"' not in r.text:
        r = session.get(f"{BASE_URL}/", timeout=TIMEOUT)
    return extract_form_key(r.text)


def post_add_tag(session: requests.Session, form_key: str, tag_name, product_id):
    """POST to the real tag/index/save controller. Returns the raw (non-redirect-followed) response."""
    data = {"form_key": form_key}
    if tag_name is not None:
        data["productTagName"] = tag_name
    if product_id is not None:
        data["product"] = str(product_id)
    return session.post(
        f"{BASE_URL}/tag/index/save/",
        data=data,
        allow_redirects=False,
        timeout=TIMEOUT,
    )


@pytest.fixture(scope="session", autouse=True)
def app_health_check():
    """Confirm the real storefront is reachable before running any test in this session."""
    r = requests.get(f"{BASE_URL}/", timeout=TIMEOUT)
    assert r.status_code == 200, f"storefront homepage not healthy: {r.status_code}"


@pytest.fixture(scope="session")
def admin_token():
    r = requests.post(
        f"{BASE_URL}/rest/V1/integration/admin/token",
        json={"username": ADMIN_USER, "password": ADMIN_PASSWORD},
        timeout=TIMEOUT,
    )
    assert r.status_code == 200, r.text
    return r.json()


def _create_customer():
    suffix = unique_suffix()
    email = f"{NAMESPACE}_{suffix}@example.com"
    password = "FtRunPass123!"
    resp = requests.post(
        f"{BASE_URL}/rest/V1/customers",
        json={"customer": {"email": email, "firstname": "Ft", "lastname": "Run"}, "password": password},
        timeout=TIMEOUT,
    )
    assert resp.status_code == 200, resp.text
    customer_id = resp.json()["id"]
    return {"id": customer_id, "email": email, "password": password}, resp


def _delete_customer_data(customer_id):
    with db_cursor() as cur:
        cur.execute("DELETE FROM tag_relation WHERE customer_id=%s", (customer_id,))
        cur.execute("DELETE FROM tag WHERE first_customer_id=%s", (customer_id,))
        cur.execute("DELETE FROM customer_entity WHERE entity_id=%s", (customer_id,))


@pytest.fixture
def new_customer():
    """A fresh storefront customer created via the app's own public REST registration endpoint."""
    customer, _resp = _create_customer()
    yield customer
    _delete_customer_data(customer["id"])


@pytest.fixture
def second_customer():
    """A second, independent storefront customer (for multi-customer relation scenarios)."""
    customer, _resp = _create_customer()
    yield customer
    _delete_customer_data(customer["id"])


def login_session(customer):
    """Log a customer in through the real HTML login form, returning (session, form_key)."""
    session = requests.Session()
    r = session.get(f"{BASE_URL}/customer/account/login/", timeout=TIMEOUT)
    form_key = extract_form_key(r.text)
    r2 = session.post(
        f"{BASE_URL}/customer/account/loginPost/",
        data={
            "form_key": form_key,
            "login[username]": customer["email"],
            "login[password]": customer["password"],
        },
        timeout=TIMEOUT,
    )
    assert r2.status_code == 200
    fresh_key = current_form_key(session)
    return session, fresh_key


@pytest.fixture
def logged_in(new_customer):
    """(session, form_key, customer) for a logged-in customer."""
    session, form_key = login_session(new_customer)
    return session, form_key, new_customer


def _create_product(admin_token):
    suffix = unique_suffix()
    sku = f"{NAMESPACE}-prod-{suffix}"
    headers = {"Authorization": f"Bearer {admin_token}"}
    payload = {
        "product": {
            "sku": sku,
            "name": f"FT Run Product {suffix}",
            "price": 10.0,
            "status": 1,
            "visibility": 4,
            "type_id": "simple",
            "attribute_set_id": 4,
            "extension_attributes": {"stock_item": {"qty": 100, "is_in_stock": True}},
        }
    }
    r = requests.post(f"{BASE_URL}/rest/V1/products", json=payload, headers=headers, timeout=30)
    assert r.status_code == 200, r.text
    product_id = r.json()["id"]
    return {"id": product_id, "sku": sku}, headers


@pytest.fixture
def new_product(admin_token):
    """A fresh simple product created via the app's own Admin REST API."""
    product, headers = _create_product(admin_token)
    yield product
    requests.delete(f"{BASE_URL}/rest/V1/products/{product['sku']}", headers=headers, timeout=30)


@pytest.fixture
def seeded_tags():
    """Tracks tag_ids created directly via insert_tag_direct() in a test and deletes them
    (and any remaining tag_relation rows) in teardown. tag_relation rows that cascade-delete
    with their product (see new_product/second_product teardown) are already gone by then;
    this only needs to clean up the `tag` row itself plus any relations product teardown
    didn't reach (e.g. multi-customer scenarios)."""
    created = []
    yield created
    for tag_id in created:
        delete_tag_and_relations(tag_id)


@pytest.fixture
def second_product(admin_token):
    """A second, independent product."""
    product, headers = _create_product(admin_token)
    yield product
    requests.delete(f"{BASE_URL}/rest/V1/products/{product['sku']}", headers=headers, timeout=30)


# --- Direct DB helpers -------------------------------------------------------------
#
# The Tag module's foundation milestone ships no admin UI to approve/disable tags or
# to deactivate a relation (that is Milestone 2/3 territory) - the only HTTP surface
# in this milestone is the single add-tag POST endpoint. To exercise saveRelation()'s
# approve/reactivate/reject state-machine branches (which require tags already in an
# APPROVED/DISABLED state, or a previously-deactivated relation) we seed that
# precondition directly in the real DB, then drive the actual behavior under test
# through a real HTTP POST to tag/index/save and verify the resulting DB state.

def fetch_tag(tag_id):
    with db_cursor() as cur:
        cur.execute("SELECT * FROM tag WHERE tag_id=%s", (tag_id,))
        return cur.fetchone()


def fetch_tag_by_name(name):
    with db_cursor() as cur:
        cur.execute("SELECT * FROM tag WHERE name=%s", (name,))
        return cur.fetchone()


def fetch_relations_for_tag(tag_id):
    with db_cursor() as cur:
        cur.execute("SELECT * FROM tag_relation WHERE tag_id=%s ORDER BY tag_relation_id", (tag_id,))
        return cur.fetchall()


def fetch_relation(tag_id, customer_id, product_id):
    with db_cursor() as cur:
        cur.execute(
            "SELECT * FROM tag_relation WHERE tag_id=%s AND customer_id=%s AND product_id=%s",
            (tag_id, customer_id, product_id),
        )
        return cur.fetchone()


def insert_tag_direct(name, status, first_customer_id, first_store_id=1):
    with db_cursor() as cur:
        cur.execute(
            "INSERT INTO tag (name, status, first_customer_id, first_store_id) VALUES (%s, %s, %s, %s)",
            (name, status, first_customer_id, first_store_id),
        )
        return cur.lastrowid


def insert_relation_direct(tag_id, customer_id, product_id, store_id=1, active=1):
    with db_cursor() as cur:
        cur.execute(
            "INSERT INTO tag_relation (tag_id, customer_id, product_id, store_id, active, created_at) "
            "VALUES (%s, %s, %s, %s, %s, NOW())",
            (tag_id, customer_id, product_id, store_id, active),
        )
        return cur.lastrowid


def set_tag_status(tag_id, status):
    with db_cursor() as cur:
        cur.execute("UPDATE tag SET status=%s WHERE tag_id=%s", (status, tag_id))


def set_relation_active(relation_id, active):
    with db_cursor() as cur:
        cur.execute("UPDATE tag_relation SET active=%s WHERE tag_relation_id=%s", (active, relation_id))


def delete_tag_and_relations(tag_id):
    with db_cursor() as cur:
        cur.execute("DELETE FROM tag_relation WHERE tag_id=%s", (tag_id,))
        cur.execute("DELETE FROM tag WHERE tag_id=%s", (tag_id,))
