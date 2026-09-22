"""Shared fixtures for Magento_Tag functional tests.

Tests target http://localhost:8080 (the built-in `php -S` dev server started
by the lifecycle `run` script). The Tag REST endpoints declared in
`app/code/Magento/Tag/etc/webapi.xml` require the `Magento_Tag::tags` ACL
resource, which the anonymous user does not have — so every REST test
authenticates with an admin bearer token.
"""
import os
import subprocess
import uuid

import pytest
import requests


BASE_URL = os.environ.get("MAGENTO_BASE_URL", "http://localhost:8080")
ADMIN_USER = os.environ.get("ADMIN_USER", "admin")
ADMIN_PASSWORD = os.environ.get("ADMIN_PASSWORD", "admin123!Magento")

# Run-scoped namespace for any seeded artifacts we need to clean up.
RUN_NS = f"ftrun_{uuid.uuid4().hex[:8]}"

# DB connection details for direct-write seeding (mirrors env_vars.json).
DB_HOST = os.environ.get("DB_HOST", "127.0.0.1")
DB_PORT = os.environ.get("DB_PORT", "3306")
DB_NAME = os.environ.get("DB_NAME", "magento")
DB_USER = os.environ.get("DB_USER", "magento")
DB_PASSWORD = os.environ.get("DB_PASSWORD", "magento")


def _mysql(sql: str) -> str:
    """Run a MySQL statement using the lifecycle credentials. Returns stdout."""
    cmd = [
        "mysql",
        f"-h{DB_HOST}",
        f"-P{DB_PORT}",
        f"-u{DB_USER}",
        f"-p{DB_PASSWORD}",
        "-N", "-B", "-e", sql, DB_NAME,
    ]
    result = subprocess.run(cmd, capture_output=True, text=True, timeout=15)
    return result.stdout.strip()


@pytest.fixture(scope="session")
def base_url() -> str:
    return BASE_URL


@pytest.fixture(scope="session")
def admin_token() -> str:
    """Fetch a fresh admin bearer token from /rest/V1/integration/admin/token."""
    resp = requests.post(
        f"{BASE_URL}/rest/V1/integration/admin/token",
        json={"username": ADMIN_USER, "password": ADMIN_PASSWORD},
        timeout=15,
    )
    assert resp.status_code == 200, (
        f"Failed to fetch admin token: HTTP {resp.status_code} body={resp.text[:400]}"
    )
    # Endpoint returns a bare JSON string, i.e. "eyJ..."
    return resp.json()


@pytest.fixture(scope="session")
def auth_headers(admin_token) -> dict:
    return {
        "Authorization": f"Bearer {admin_token}",
        "Content-Type": "application/json",
        "Accept": "application/json",
    }


@pytest.fixture(autouse=True)
def _app_reachable(base_url):
    """Confirm the storefront homepage responds before every test."""
    resp = requests.get(base_url + "/", timeout=15, allow_redirects=False)
    assert resp.status_code in (200, 302), (
        f"Magento storefront not reachable: HTTP {resp.status_code}"
    )


@pytest.fixture
def cleanup_tags(auth_headers, base_url):
    """Collect tag IDs to delete at the end of a test (idempotent)."""
    to_delete: list[int] = []
    yield to_delete
    for tag_id in to_delete:
        try:
            requests.delete(
                f"{base_url}/rest/V1/tags/{tag_id}",
                headers=auth_headers,
                timeout=15,
            )
        except Exception:
            pass


@pytest.fixture(scope="session")
def seeded_product():
    """Ensure at least one product exists so /V1/products/:id/tags tests have a target.

    The DB is freshly seeded without any products. Create one row directly in
    `catalog_product_entity` (namespaced sku) and delete it in teardown. This
    keeps the test surface deterministic without depending on Magento's admin
    UI or product import flow.
    """
    sku = f"{RUN_NS}_prod"
    existing = _mysql(
        f"SELECT entity_id FROM catalog_product_entity WHERE sku = '{sku}' LIMIT 1;"
    )
    if existing:
        product_id = int(existing.splitlines()[0])
    else:
        # entity_type_id for catalog_product; attribute_set_id 4 is the default set.
        entity_type_id = _mysql(
            "SELECT entity_type_id FROM eav_entity_type "
            "WHERE entity_type_code = 'catalog_product' LIMIT 1;"
        )
        etid = int(entity_type_id) if entity_type_id else 4
        _mysql(
            "INSERT INTO catalog_product_entity "
            "(attribute_set_id, type_id, sku, has_options, required_options, created_at, updated_at) "
            f"VALUES (4, 'simple', '{sku}', 0, 0, NOW(), NOW());"
        )
        product_id = int(_mysql(
            f"SELECT entity_id FROM catalog_product_entity WHERE sku = '{sku}' LIMIT 1;"
        ))
    yield {"id": product_id, "sku": sku}
    _mysql(f"DELETE FROM catalog_product_entity WHERE sku = '{sku}';")


@pytest.fixture(scope="session")
def nonexistent_product_id() -> int:
    """Return a product id that is guaranteed to not exist."""
    row = _mysql("SELECT COALESCE(MAX(entity_id), 0) FROM catalog_product_entity;")
    return int(row) + 999999 if row else 999999


@pytest.fixture(scope="session")
def seeded_customer():
    """Ensure at least one customer exists so tag-management calls resolve.

    TagManagement::addTagsToProduct calls customerRepository->getById() and
    throws NoSuchEntity when the customer is missing. The DB is fresh so we
    seed a minimal customer_entity row (namespaced email) and clean it up.
    """
    email = f"{RUN_NS}@example.com"
    existing = _mysql(
        f"SELECT entity_id FROM customer_entity WHERE email = '{email}' LIMIT 1;"
    )
    if existing:
        customer_id = int(existing.splitlines()[0])
    else:
        _mysql(
            "INSERT INTO customer_entity (website_id, email, group_id, store_id, "
            "is_active, firstname, lastname, created_at, updated_at) "
            f"VALUES (1, '{email}', 1, 1, 1, 'FT', 'Runner', NOW(), NOW());"
        )
        customer_id = int(_mysql(
            f"SELECT entity_id FROM customer_entity WHERE email = '{email}' LIMIT 1;"
        ))
    yield {"id": customer_id, "email": email}
    _mysql(f"DELETE FROM customer_entity WHERE email = '{email}';")
