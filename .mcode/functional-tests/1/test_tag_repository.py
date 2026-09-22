"""REST /V1/tags/* — CRUD and search against Magento\\Tag\\Api\\TagRepositoryInterface.

Endpoints under test (declared in app/code/Magento/Tag/etc/webapi.xml):
  GET    /V1/tags/search
  POST   /V1/tags
  GET    /V1/tags/:tagId
  PUT    /V1/tags/:tagId
  DELETE /V1/tags/:tagId

Every request requires the `Magento_Tag::tags` ACL, so all tests authenticate
with an admin bearer token from the session-scoped `auth_headers` fixture.
"""
import uuid

import pytest
import requests


TAG_URL = "/rest/V1/tags"
SEARCH_URL = "/rest/V1/tags/search"


def _unique_name(prefix: str = "ftrun") -> str:
    return f"{prefix}_{uuid.uuid4().hex[:10]}"


class TestTagRepositorySearch:
    """GET /V1/tags/search — list tags with SearchCriteria."""

    def test_search_returns_items_key(self, base_url, auth_headers):
        """HAPPY_PATH: empty search returns 200 with items/total_count."""
        resp = requests.get(
            f"{base_url}{SEARCH_URL}?searchCriteria[pageSize]=1",
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 200, resp.text
        body = resp.json()
        assert "items" in body
        assert "total_count" in body
        assert isinstance(body["items"], list)

    def test_search_without_token_is_unauthorized(self, base_url):
        """AUTH: /V1/tags/search rejects requests missing the admin token."""
        resp = requests.get(
            f"{base_url}{SEARCH_URL}?searchCriteria[pageSize]=1",
            timeout=15,
        )
        assert resp.status_code == 401
        body = resp.json()
        assert "Magento_Tag::tags" in body.get("parameters", {}).get("resources", "")


class TestTagRepositoryCreate:
    """POST /V1/tags — create a new tag."""

    def test_create_tag_success(self, base_url, auth_headers, cleanup_tags):
        """HAPPY_PATH: POST /V1/tags with a fresh name returns 200 and echoes payload."""
        name = _unique_name("ft_create")
        payload = {
            "tag": {
                "name": name,
                "status": 1,  # STATUS_APPROVED
            }
        }
        resp = requests.post(
            f"{base_url}{TAG_URL}",
            json=payload,
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 200, resp.text
        body = resp.json()
        assert body["name"] == name
        assert body["status"] == 1
        assert isinstance(body["tag_id"], int) and body["tag_id"] > 0
        cleanup_tags.append(body["tag_id"])

    def test_create_tag_missing_body_is_400(self, base_url, auth_headers):
        """INVALID_INPUT: empty body should be rejected."""
        resp = requests.post(
            f"{base_url}{TAG_URL}",
            data="",
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 400


class TestTagRepositoryGetById:
    """GET /V1/tags/:tagId — fetch a single tag by id."""

    def test_get_tag_by_id_after_create(self, base_url, auth_headers, cleanup_tags):
        """HAPPY_PATH: create a tag, then fetch it by id."""
        name = _unique_name("ft_get")
        create_resp = requests.post(
            f"{base_url}{TAG_URL}",
            json={"tag": {"name": name, "status": 1}},
            headers=auth_headers,
            timeout=15,
        )
        assert create_resp.status_code == 200, create_resp.text
        tag_id = create_resp.json()["tag_id"]
        cleanup_tags.append(tag_id)

        get_resp = requests.get(
            f"{base_url}{TAG_URL}/{tag_id}",
            headers=auth_headers,
            timeout=15,
        )
        assert get_resp.status_code == 200, get_resp.text
        body = get_resp.json()
        assert body["tag_id"] == tag_id
        assert body["name"] == name

    def test_get_tag_not_found_returns_404(self, base_url, auth_headers):
        """NOT_FOUND: unknown tag id returns 404 with a NoSuchEntity message."""
        resp = requests.get(
            f"{base_url}{TAG_URL}/999999999",
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 404, resp.text
        body = resp.json()
        assert "message" in body


class TestTagRepositoryDelete:
    """DELETE /V1/tags/:tagId — delete a tag by id."""

    def test_delete_tag_success(self, base_url, auth_headers):
        """HAPPY_PATH: create-then-delete round trip."""
        name = _unique_name("ft_del")
        create = requests.post(
            f"{base_url}{TAG_URL}",
            json={"tag": {"name": name, "status": 1}},
            headers=auth_headers,
            timeout=15,
        )
        assert create.status_code == 200, create.text
        tag_id = create.json()["tag_id"]

        del_resp = requests.delete(
            f"{base_url}{TAG_URL}/{tag_id}",
            headers=auth_headers,
            timeout=15,
        )
        assert del_resp.status_code == 200, del_resp.text
        # Response is the boolean `true`.
        assert del_resp.json() is True

        # The tag should now be gone.
        get_resp = requests.get(
            f"{base_url}{TAG_URL}/{tag_id}",
            headers=auth_headers,
            timeout=15,
        )
        assert get_resp.status_code == 404

    def test_delete_nonexistent_tag_returns_404(self, base_url, auth_headers):
        """NOT_FOUND: deleting an unknown id returns 404."""
        resp = requests.delete(
            f"{base_url}{TAG_URL}/999999998",
            headers=auth_headers,
            timeout=15,
        )
        assert resp.status_code == 404


class TestTagRepositoryUpdate:
    """PUT /V1/tags/:tagId — update an existing tag."""

    def test_update_tag_changes_status(self, base_url, auth_headers, cleanup_tags):
        """HAPPY_PATH: create tag pending (0), PUT to approved (1)."""
        name = _unique_name("ft_upd")
        create = requests.post(
            f"{base_url}{TAG_URL}",
            json={"tag": {"name": name, "status": 0}},
            headers=auth_headers,
            timeout=15,
        )
        assert create.status_code == 200, create.text
        tag_id = create.json()["tag_id"]
        cleanup_tags.append(tag_id)

        update = requests.put(
            f"{base_url}{TAG_URL}/{tag_id}",
            json={"tag": {"tag_id": tag_id, "name": name, "status": 1}},
            headers=auth_headers,
            timeout=15,
        )
        assert update.status_code == 200, update.text
        assert update.json()["status"] == 1

        # Confirm via GET.
        got = requests.get(
            f"{base_url}{TAG_URL}/{tag_id}",
            headers=auth_headers,
            timeout=15,
        )
        assert got.status_code == 200
        assert got.json()["status"] == 1
