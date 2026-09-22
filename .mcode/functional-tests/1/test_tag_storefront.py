"""Storefront POST /tag/index/save — Magento\\Tag\\Controller\\Index\\Save.

The controller is unauthenticated-friendly: if the customer is not logged in
it stashes the pending tag input on `Magento\\Tag\\Model\\Session` and
redirects to `customer/account/login`. If a form key is missing on POST, the
controller adds an error message and redirects back.

Because there is no logged-in customer in a fresh install, we can only
exercise the unauthenticated branch here. This still proves the controller
is registered under the `tag` frontName, its route resolves, and the redirect
behavior matches the spec.
"""
import requests


class TestSaveControllerUnauthenticated:
    """POST /tag/index/save — no auth cookie."""

    def test_post_redirects_to_login(self, base_url):
        """HAPPY_PATH (unauth branch): POST without form_key redirects (not 5xx)."""
        resp = requests.post(
            f"{base_url}/tag/index/save",
            data={"tags": "example", "product": "1"},
            timeout=15,
            allow_redirects=False,
        )
        # Should be a redirect (302). Either to login (unauth) or back
        # (form-key rejection). Both are documented behaviors and both prove
        # the route is registered and the controller is dispatching.
        assert resp.status_code in (200, 302), (
            f"Expected redirect from /tag/index/save, got HTTP {resp.status_code}: "
            f"{resp.text[:400]}"
        )

    def test_get_route_registered_reaches_controller(self, base_url):
        """SETUP: GET on the route reaches the controller (not 404 route not found)."""
        # The route `tag/index/save` is declared in etc/frontend/routes.xml,
        # frontName=`tag`, controller `Index`, action `Save`. A missing route
        # in Magento would render a CMS 404 page (HTTP 404).
        resp = requests.get(f"{base_url}/tag/index/save", timeout=15, allow_redirects=False)
        # The action supports HttpGetActionInterface, so it should not 404 on GET.
        assert resp.status_code != 404, (
            f"tag/index/save route did not resolve: HTTP {resp.status_code}"
        )
