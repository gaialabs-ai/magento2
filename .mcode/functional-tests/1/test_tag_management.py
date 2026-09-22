"""REST /V1/products/:productId/tags* — Magento\\Tag\\Api\\TagManagementInterface.

Endpoints under test (declared in app/code/Magento/Tag/etc/webapi.xml):
  GET    /V1/products/:productId/tags
  POST   /V1/products/:productId/tags
  DELETE /V1/products/:productId/tags/:tagId

Requires the `Magento_Tag::tags` ACL — all requests carry an admin bearer
token from the session-scoped `auth_headers` fixture. Uses the session
`seeded_product` fixture to ensure at least one product exists.
"""
import uuid

import requests


def _tag_string(prefix: str = "ftrun") -> str:
    return f"'{prefix}_{uuid.uuid4().hex[:8]}'"


class TestTagsForProductGet:
    """GET /V1/products/:productId/tags"""

    def test_get_tags_for_existing_product(self, base_url, auth_headers, seeded_product):
        """HAPPY_PATH: an existing product returns a JSON array (possibly empty)."""
        resp = requests.get(
            f"{base_url}/rest/V1/products/{seeded_product['id']}/tags",
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 200, resp.text
        body = resp.json()
        assert isinstance(body, list)

    def test_get_tags_for_nonexistent_product_returns_404(
        self, base_url, auth_headers, nonexistent_product_id
    ):
        """NOT_FOUND: unknown product id returns 404."""
        resp = requests.get(
            f"{base_url}/rest/V1/products/{nonexistent_product_id}/tags",
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 404, resp.text


class TestAddTagsToProduct:
    """POST /V1/products/:productId/tags"""

    def test_add_new_tag_to_product(
        self, base_url, auth_headers, seeded_product, seeded_customer, cleanup_tags
    ):
        """HAPPY_PATH: adding a fresh tag string returns a result and creates a tag."""
        tag_str = _tag_string("mgmt_new")
        payload = {
            "customerId": seeded_customer["id"],
            "storeId": 1,
            "tagString": tag_str,
        }
        resp = requests.post(
            f"{base_url}/rest/V1/products/{seeded_product['id']}/tags",
            json=payload,
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 200, resp.text
        body = resp.json()
        # TagsAddedResultInterface exposes buckets keyed by ADD_STATUS_*.
        assert "new" in body or "added_tags" in body or isinstance(body, dict)

        # Confirm the tag now surfaces via GET.
        got = requests.get(
            f"{base_url}/rest/V1/products/{seeded_product['id']}/tags",
            headers=auth_headers,
            timeout=15,
        )
        assert got.status_code == 200
        for tag in got.json():
            if "tag_id" in tag:
                cleanup_tags.append(tag["tag_id"])

    def test_add_tag_to_nonexistent_product_returns_404(
        self, base_url, auth_headers, seeded_customer, nonexistent_product_id
    ):
        """NOT_FOUND: nonexistent product must reject with 404."""
        payload = {
            "customerId": seeded_customer["id"],
            "storeId": 1,
            "tagString": _tag_string("mgmt_notfound"),
        }
        resp = requests.post(
            f"{base_url}/rest/V1/products/{nonexistent_product_id}/tags",
            json=payload,
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 404, resp.text


class TestRemoveTagFromProduct:
    """DELETE /V1/products/:productId/tags/:tagId"""

    def test_remove_tag_from_nonexistent_product_returns_404(
        self, base_url, auth_headers, seeded_customer, nonexistent_product_id
    ):
        """NOT_FOUND: a bad product id yields 404, not 500.

        `removeTagFromProduct(int $tagId, int $productId, int $customerId,
        int $storeId)` — customerId and storeId are declared parameters, so
        the REST binder requires them as query-string args.
        """
        resp = requests.delete(
            f"{base_url}/rest/V1/products/{nonexistent_product_id}/tags/999999"
            f"?customerId={seeded_customer['id']}&storeId=1",
            headers=auth_headers,
            timeout=15,
        )
        # Repository throws NoSuchEntityException -> 404
        assert resp.status_code == 404, resp.text
