"""
Functional tests for Magento\\Tag\\Model\\Tag::saveRelation()'s approve/reactivate/reject
state machine, driven through the real tag/index/save HTTP endpoint.

This milestone ships no admin UI to approve/disable a tag or deactivate a relation
(that is Milestone 2/3 territory per MILESTONE.md) - the only live HTTP surface for the
Tag module is the single add-tag POST endpoint, which always creates brand-new tags as
STATUS_PENDING. To exercise the APPROVED/DISABLED branches of saveRelation() against
real behavior, we seed the tag's precondition state (status, pre-existing relations)
directly in the real DB - standing in for "an admin already approved/disabled this tag
via a UI that ships in a later milestone" - and then drive the actual behavior under
test through a genuine HTTP POST, verifying the resulting DB state.
"""
import conftest as c


def test_saverelation_approved_tag_with_no_existing_relation_creates_relation(logged_in, new_product, seeded_tags):
    """Approved tag + product never tagged before -> relation created immediately (ADD_STATUS_SUCCESS path)."""
    session, form_key, customer = logged_in
    tag_name = f"{c.NAMESPACE}_approved_new_{c.unique_suffix()}"
    tag_id = c.insert_tag_direct(tag_name, status=1, first_customer_id=customer["id"])
    seeded_tags.append(tag_id)

    assert len(c.fetch_relations_for_tag(tag_id)) == 0

    resp = c.post_add_tag(session, form_key, tag_name, new_product["id"])
    assert resp.status_code == 302

    relations = c.fetch_relations_for_tag(tag_id)
    assert len(relations) == 1
    assert relations[0]["customer_id"] == customer["id"]
    assert relations[0]["product_id"] == new_product["id"]
    assert relations[0]["active"] == 1
    # Approved tags are not demoted/promoted by saveRelation(); status stays approved.
    assert c.fetch_tag(tag_id)["status"] == 1


def test_saverelation_approved_tag_already_linked_by_other_customer_creates_new_relation_exist_branch(
    logged_in, second_customer, new_product, seeded_tags
):
    """Approved tag already linked to the product (by customer A) -> customer B tagging the same
    product with the same tag still gets their own relation row created (ADD_STATUS_EXIST bucket,
    which legacy uses for "someone already tagged this product with this tag", not "no-op")."""
    session_a, form_key_a, customer_a = logged_in
    tag_name = f"{c.NAMESPACE}_approved_exist_{c.unique_suffix()}"
    tag_id = c.insert_tag_direct(tag_name, status=1, first_customer_id=customer_a["id"])
    seeded_tags.append(tag_id)

    resp_a = c.post_add_tag(session_a, form_key_a, tag_name, new_product["id"])
    assert resp_a.status_code == 302
    assert c.fetch_relation(tag_id, customer_a["id"], new_product["id"]) is not None

    session_b, form_key_b = c.login_session(second_customer)
    resp_b = c.post_add_tag(session_b, form_key_b, tag_name, new_product["id"])
    assert resp_b.status_code == 302

    relation_b = c.fetch_relation(tag_id, second_customer["id"], new_product["id"])
    assert relation_b is not None, "a second customer tagging an already-tagged product must still get their own relation"
    assert relation_b["active"] == 1

    all_relations = c.fetch_relations_for_tag(tag_id)
    assert len(all_relations) == 2


def test_saverelation_approved_tag_active_relation_is_left_unchanged(logged_in, new_product, seeded_tags):
    """Approved tag, relation already active for this exact customer/product -> resubmitting
    must not create a duplicate row or otherwise disturb the existing relation."""
    session, form_key, customer = logged_in
    tag_name = f"{c.NAMESPACE}_approved_noop_{c.unique_suffix()}"
    tag_id = c.insert_tag_direct(tag_name, status=1, first_customer_id=customer["id"])
    seeded_tags.append(tag_id)
    relation_id = c.insert_relation_direct(tag_id, customer["id"], new_product["id"], active=1)

    resp = c.post_add_tag(session, form_key, tag_name, new_product["id"])
    assert resp.status_code == 302

    relations = c.fetch_relations_for_tag(tag_id)
    assert len(relations) == 1, "an already-active relation must not be duplicated"
    assert relations[0]["tag_relation_id"] == relation_id
    assert relations[0]["active"] == 1


def test_saverelation_approved_tag_reactivates_inactive_relation(logged_in, new_product, seeded_tags):
    """Approved tag, relation exists but was previously deactivated -> resubmitting the same tag
    must reactivate the SAME relation row (not insert a new one)."""
    session, form_key, customer = logged_in
    tag_name = f"{c.NAMESPACE}_approved_reactivate_{c.unique_suffix()}"
    tag_id = c.insert_tag_direct(tag_name, status=1, first_customer_id=customer["id"])
    seeded_tags.append(tag_id)
    relation_id = c.insert_relation_direct(tag_id, customer["id"], new_product["id"], active=0)

    resp = c.post_add_tag(session, form_key, tag_name, new_product["id"])
    assert resp.status_code == 302

    relations = c.fetch_relations_for_tag(tag_id)
    assert len(relations) == 1, "reactivation must reuse the existing relation row, not insert a new one"
    assert relations[0]["tag_relation_id"] == relation_id
    assert relations[0]["active"] == 1


def test_saverelation_disabled_tag_with_no_relation_bumps_tag_to_pending_and_creates_relation(
    logged_in, new_product, seeded_tags
):
    """Disabled tag, no existing relation for anyone -> saveRelation() bumps the tag itself back
    to STATUS_PENDING and creates the relation (ADD_STATUS_NEW)."""
    session, form_key, customer = logged_in
    tag_name = f"{c.NAMESPACE}_disabled_norel_{c.unique_suffix()}"
    tag_id = c.insert_tag_direct(tag_name, status=-1, first_customer_id=customer["id"])
    seeded_tags.append(tag_id)

    resp = c.post_add_tag(session, form_key, tag_name, new_product["id"])
    assert resp.status_code == 302

    tag_after = c.fetch_tag(tag_id)
    assert tag_after["status"] == 0, "a disabled tag with no existing relation must be bumped back to STATUS_PENDING"

    relations = c.fetch_relations_for_tag(tag_id)
    assert len(relations) == 1
    assert relations[0]["customer_id"] == customer["id"]
    assert relations[0]["active"] == 1


def test_saverelation_disabled_tag_with_existing_relation_is_rejected(logged_in, new_product, seeded_tags):
    """Disabled tag, this customer already has an active relation to this product -> rejected:
    the tag must remain disabled and no relation state may change."""
    session, form_key, customer = logged_in
    tag_name = f"{c.NAMESPACE}_disabled_withrel_{c.unique_suffix()}"
    # Simulate: tag was approved/pending when the customer first tagged the product, then an
    # admin disabled the tag afterwards (that admin action is out of this milestone's scope, so
    # we seed the end-state directly).
    tag_id = c.insert_tag_direct(tag_name, status=1, first_customer_id=customer["id"])
    seeded_tags.append(tag_id)
    relation_id = c.insert_relation_direct(tag_id, customer["id"], new_product["id"], active=1)
    c.set_tag_status(tag_id, -1)

    resp = c.post_add_tag(session, form_key, tag_name, new_product["id"])
    assert resp.status_code == 302

    tag_after = c.fetch_tag(tag_id)
    assert tag_after["status"] == -1, "a rejected submission must not change the tag's disabled status"

    relations = c.fetch_relations_for_tag(tag_id)
    assert len(relations) == 1
    assert relations[0]["tag_relation_id"] == relation_id
    assert relations[0]["active"] == 1, "a rejected submission must not alter the existing relation"
