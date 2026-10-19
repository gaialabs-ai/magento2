"""
Functional tests for the Magento_Tag "add tag to product" storefront flow
(POST tag/index/save -> Magento\\Tag\\Model\\TagManagement::addTagsToProduct() ->
Magento\\Tag\\Model\\Tag::saveRelation(), writing through the real declarative-schema
tables `tag` and `tag_relation`).

All requests go over real HTTP against the running storefront (PHP built-in server).
Customers/products are created through the app's own REST API. Login goes through the
real session-cookie based customer/account/loginPost form, exactly like a browser.
Results are verified by querying the real MySQL database the app itself writes to.
"""
import conftest as c


class TestAddTagHappyPath:
    def test_add_tag_happy_path_creates_pending_tag_and_active_relation(self, logged_in, new_product):
        session, form_key, customer = logged_in
        tag_name = f"{c.NAMESPACE}_happy_{c.unique_suffix()}"

        resp = c.post_add_tag(session, form_key, tag_name, new_product["id"])

        assert resp.status_code == 302

        tag = c.fetch_tag_by_name(tag_name)
        assert tag is not None, "tag row was not created"
        assert tag["status"] == 0, "a brand-new tag must default to STATUS_PENDING (0)"
        assert tag["first_customer_id"] == customer["id"]

        relation = c.fetch_relation(tag["tag_id"], customer["id"], new_product["id"])
        assert relation is not None, "tag_relation row was not created"
        assert relation["active"] == 1

    def test_add_tag_tokenizes_quoted_phrase_and_multiple_tokens(self, logged_in, new_product):
        session, form_key, customer = logged_in
        suffix = c.unique_suffix()
        t1, t2, t3 = f"{c.NAMESPACE}alpha{suffix}", f"{c.NAMESPACE} multi word {suffix}", f"{c.NAMESPACE}gamma{suffix}"
        raw = f"{t1} '{t2}' {t3}"

        resp = c.post_add_tag(session, form_key, raw, new_product["id"])
        assert resp.status_code == 302

        tag1 = c.fetch_tag_by_name(t1)
        tag2 = c.fetch_tag_by_name(t2)
        tag3 = c.fetch_tag_by_name(t3)
        assert tag1 is not None, "space-delimited token 1 was not tokenized into its own tag"
        assert tag2 is not None, "single-quoted multi-word phrase was not preserved as one tag"
        assert tag3 is not None, "space-delimited token 3 was not tokenized into its own tag"

        for tag in (tag1, tag2, tag3):
            relation = c.fetch_relation(tag["tag_id"], customer["id"], new_product["id"])
            assert relation is not None and relation["active"] == 1

    def test_add_tag_reuses_existing_tag_row_across_products(self, logged_in, new_product, second_product):
        session, form_key, customer = logged_in
        tag_name = f"{c.NAMESPACE}_reuse_{c.unique_suffix()}"

        resp1 = c.post_add_tag(session, form_key, tag_name, new_product["id"])
        assert resp1.status_code == 302
        tag_after_first = c.fetch_tag_by_name(tag_name)
        assert tag_after_first is not None
        tag_id = tag_after_first["tag_id"]

        resp2 = c.post_add_tag(session, form_key, tag_name, second_product["id"])
        assert resp2.status_code == 302

        # The same tag name must dedupe to the same tag row, not create a second one.
        all_tags_with_name = c.fetch_tag_by_name(tag_name)
        assert all_tags_with_name["tag_id"] == tag_id

        relation_on_second_product = c.fetch_relation(tag_id, customer["id"], second_product["id"])
        assert relation_on_second_product is not None
        assert relation_on_second_product["active"] == 1


class TestAddTagAuthAndValidation:
    def test_add_tag_unauthenticated_request_is_rejected(self, new_product):
        """Save::dispatch() now guards with customerSession->authenticate(), redirecting an
        unauthenticated submission straight to the login page (and stashing beforeAuthUrl to
        resume there) instead of silently dropping it. The form key must come from an
        uncached, personalized page (customer/account/) rather than the full-page-cached
        homepage ("/") - the homepage's embedded form_key is baked in at cache-generation
        time and is identical across unrelated anonymous sessions, which would make the
        request fail the framework's CSRF check before it even reaches Save::dispatch(),
        and would make this test pass for the wrong reason regardless of the auth guard."""
        anon_session = __import__("requests").Session()
        form_key = c.current_form_key(anon_session)
        tag_name = f"{c.NAMESPACE}_unauth_{c.unique_suffix()}"

        resp = c.post_add_tag(anon_session, form_key, tag_name, new_product["id"])

        assert resp.status_code == 302
        location = resp.headers.get("Location", "")
        assert "customer/account/login" in location, (
            f"an unauthenticated submission must redirect to the login page (to resume after "
            f"sign-in), not silently no-op; got Location={location!r}"
        )
        assert c.fetch_tag_by_name(tag_name) is None, "unauthenticated request must not create a tag"

    def test_add_tag_invalid_form_key_is_rejected(self, logged_in, new_product):
        session, _form_key, _customer = logged_in
        tag_name = f"{c.NAMESPACE}_badkey_{c.unique_suffix()}"

        resp = c.post_add_tag(session, "not-a-real-form-key", tag_name, new_product["id"])

        assert resp.status_code == 302
        assert c.fetch_tag_by_name(tag_name) is None, "an invalid form key must not be accepted"

    def test_add_tag_missing_tag_name_is_noop(self, logged_in, new_product):
        session, form_key, customer = logged_in

        resp = c.post_add_tag(session, form_key, "", new_product["id"])

        assert resp.status_code == 302
        # No tag could possibly have been created for an empty name; verify no relation
        # was written against this product either (relying on a fresh product fixture).
        conn = c.db_connect()
        try:
            with conn.cursor() as cur:
                cur.execute(
                    "SELECT COUNT(*) AS n FROM tag_relation WHERE product_id=%s AND customer_id=%s",
                    (new_product["id"], customer["id"]),
                )
                count = cur.fetchone()["n"]
        finally:
            conn.close()
        assert count == 0, "an empty tag name must not result in any relation being written"

    def test_add_tag_missing_product_param_is_noop(self, logged_in):
        session, form_key, customer = logged_in
        tag_name = f"{c.NAMESPACE}_noprod_{c.unique_suffix()}"

        resp = c.post_add_tag(session, form_key, tag_name, None)

        assert resp.status_code == 302
        assert c.fetch_tag_by_name(tag_name) is None, "a request with no product id must not create a tag"

    def test_add_tag_nonexistent_product_returns_not_found_and_writes_nothing(self, logged_in):
        session, form_key, customer = logged_in
        tag_name = f"{c.NAMESPACE}_ghost_{c.unique_suffix()}"
        nonexistent_product_id = 999999999

        resp = c.post_add_tag(session, form_key, tag_name, nonexistent_product_id)

        assert resp.status_code == 302
        assert c.fetch_tag_by_name(tag_name) is None, (
            "a non-existent product id must raise NoSuchEntityException before any tag is "
            "created (the product lookup happens before tokenization/persistence)"
        )


class TestAddTagBoundary:
    def test_add_tag_name_over_255_chars_is_truncated(self, logged_in, new_product):
        session, form_key, customer = logged_in
        long_name = f"{c.NAMESPACE}_" + ("z" * 280)
        assert len(long_name) > 255

        resp = c.post_add_tag(session, form_key, long_name, new_product["id"])
        assert resp.status_code == 302

        truncated_name = long_name[:255]
        tag = c.fetch_tag_by_name(truncated_name)
        assert tag is not None, "tag name longer than 255 chars must be truncated to 255 and still saved"
        assert len(tag["name"]) == 255

    def test_add_tag_duplicate_submission_is_idempotent(self, logged_in, new_product):
        session, form_key, customer = logged_in
        tag_name = f"{c.NAMESPACE}_dup_{c.unique_suffix()}"

        resp1 = c.post_add_tag(session, form_key, tag_name, new_product["id"])
        assert resp1.status_code == 302
        tag = c.fetch_tag_by_name(tag_name)
        assert tag is not None

        resp2 = c.post_add_tag(session, form_key, tag_name, new_product["id"])
        assert resp2.status_code == 302

        relations = c.fetch_relations_for_tag(tag["tag_id"])
        matching = [r for r in relations if r["customer_id"] == customer["id"] and r["product_id"] == new_product["id"]]
        assert len(matching) == 1, "resubmitting the same tag for the same product/customer must not duplicate the relation"
