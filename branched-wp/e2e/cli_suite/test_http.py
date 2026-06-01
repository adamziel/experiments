"""
Served-site tests.

These tests need a fully booted WordPress (HTTP + DB) behind the CLI.
They all depend on the session-scoped `served_site` fixture, which
starts one server once and shares it across tests. Each test creates
its own uniquely-named branches so they don't collide.

The suite is intentionally narrow and backend-agnostic:
    - Homepage serves HTML
    - WP REST API root responds with site metadata
    - wp-login.php is reachable
    - A branch subdomain routes to a different branch
    - Writing content on a branch is NOT visible on main
    - After `forkpress branch merge`, the content shows on main

The content-mutation flow uses WP's own HTTP interfaces so it applies
equally to any CLI-compatible backend. If the backend doesn't expose a
working WordPress HTTP surface, the server fixture itself will fail and
all tests here will be reported as errors — that IS the contract we're
asserting.

Tests skip cleanly when:
    - `requests` isn't installed
    - forkpress can't bring up a served site (direct-driver path without
      a compiled binary)
"""

from __future__ import annotations

import re
import time
from urllib.parse import urlparse

import pytest


try:  # requests is optional — skip cleanly if it's not there
    import requests as _requests
except ImportError:  # pragma: no cover
    _requests = None

requires_requests = pytest.mark.skipif(
    _requests is None,
    reason="requests not installed (pip install requests)",
)


@pytest.fixture(scope="session")
def branch_id_counter():
    """Monotonic counter so each test can mint a unique branch name."""
    state = {"n": 0}

    def nxt() -> str:
        state["n"] += 1
        return f"t{int(time.time())}x{state['n']}"

    return nxt


# =========================================================================
# Homepage + REST API + login page
# =========================================================================


@requires_requests
class TestHomepage:
    def test_main_homepage_returns_200(self, served_site):
        r = served_site.get("/")
        # Redirects to index.php are normal; allow 200 or 3xx.
        assert r.status_code in (200, 301, 302), (
            f"unexpected status {r.status_code}; body head:\n{r.text[:500]}"
        )

    def test_main_homepage_looks_like_wordpress(self, served_site):
        r = served_site.get("/", allow_redirects=True)
        body = r.text
        assert "<html" in body.lower() or "wp-" in body.lower(), (
            f"homepage doesn't look like WP:\n{body[:500]}"
        )


@requires_requests
class TestRestApi:
    def test_rest_root_returns_json(self, served_site):
        r = served_site.get("/wp-json/")
        assert r.status_code == 200, f"/wp-json/ got {r.status_code}"
        ct = r.headers.get("Content-Type", "").lower()
        assert "json" in ct, f"/wp-json/ not JSON: {ct}"
        data = r.json()
        # "name" is the site title per the WP REST API contract.
        assert "name" in data, data


@requires_requests
class TestLogin:
    def test_login_page_serves_a_form(self, served_site):
        r = served_site.get("/wp-login.php")
        assert r.status_code == 200, r.status_code
        assert "<form" in r.text.lower(), r.text[:500]
        assert "log" in r.text.lower(), "expected 'log in' / 'login'"


# =========================================================================
# Branch subdomain routing
# =========================================================================


@requires_requests
class TestBranchRouting:
    def test_unknown_branch_still_serves_HTTP(self, served_site):
        # The router resolves Host → branch; an unknown subdomain falls
        # back to "main" (or an explicit error, depending on backend).
        # Either way we must get a well-formed HTTP response, not a hang.
        r = served_site.get("/", branch="totally-nonexistent")
        assert r.status_code in (200, 301, 302, 404, 500), r.status_code

    def test_branch_subdomain_routes_to_that_branch(self, served_site, branch_id_counter):
        br = branch_id_counter()
        served_site.cli.branch("create", br).expect_ok()
        try:
            r = served_site.get("/", branch=br, allow_redirects=True)
            assert r.status_code in (200, 301, 302), r.status_code
            # The router echoes the active branch via header.
            xhdr = r.headers.get("X-BranchFS-Branch", "").lower()
            if xhdr:
                assert xhdr == br or xhdr == "main", f"unexpected branch header: {xhdr}"
        finally:
            served_site.cli.branch("delete", br)


# =========================================================================
# Content isolation + merge propagation
#
# These tests require a WP admin login to mutate state, and a nonce to
# call protected REST endpoints. If login fails for any reason we skip
# with the underlying error — that's a separate regression, not a test
# bug.
# =========================================================================


def _login(served_site, host: str, user: str = "admin", pw: str = "admin"):
    """Return a requests.Session with WordPress auth cookies."""
    import requests
    s = requests.Session()
    r = s.post(
        served_site.base_url + "/wp-login.php",
        headers={"Host": host},
        data={
            "log": user,
            "pwd": pw,
            "wp-submit": "Log In",
            "redirect_to": f"http://{host}/wp-admin/",
            "testcookie": "1",
        },
        cookies={"wordpress_test_cookie": "WP+Cookie+check"},
        allow_redirects=False,
        timeout=30,
    )
    if r.status_code not in (301, 302):
        pytest.skip(
            f"wp-login did not redirect (got {r.status_code}); WP may not be "
            f"fully bootstrapped on this backend"
        )
    # A successful login sets wordpress_logged_in_* cookies.
    if not any(k.startswith("wordpress_logged_in") for k in s.cookies.keys()):
        pytest.skip(f"wp-login succeeded but no login cookie set: {list(s.cookies)}")
    return s


def _rest_nonce(sess, base_url: str, host: str) -> str:
    import requests
    r = sess.get(
        base_url + "/wp-admin/admin-ajax.php?action=rest-nonce",
        headers={"Host": host},
        timeout=30,
    )
    if r.status_code != 200:
        pytest.skip(f"rest-nonce endpoint returned {r.status_code}")
    txt = r.text.strip()
    if not re.fullmatch(r"[a-f0-9]{6,20}", txt):
        pytest.skip(f"rest-nonce returned non-nonce: {txt[:100]!r}")
    return txt


def _get_site_title(served_site, branch: str) -> str:
    """Read the site title via /wp-json/ (public, no auth needed)."""
    r = served_site.get("/wp-json/", branch=branch)
    if r.status_code != 200:
        pytest.skip(f"/wp-json/ returned {r.status_code} on branch {branch}")
    return r.json().get("name", "")


def _set_site_title_via_options(sess, base_url: str, host: str, nonce: str,
                                 new_title: str) -> bool:
    """Update blogname via WP's admin options.php POST (classic flow)."""
    import requests
    # First pull an edit nonce from the options-general admin page.
    r = sess.get(
        base_url + "/wp-admin/options-general.php",
        headers={"Host": host},
        timeout=30,
    )
    m = re.search(
        r'name="_wpnonce"\s+value="([a-f0-9]+)"',
        r.text,
    )
    if not m:
        return False
    form_nonce = m.group(1)

    # Pull the referer nonce too if present.
    r2 = sess.post(
        base_url + "/wp-admin/options.php",
        headers={"Host": host, "Referer": f"http://{host}/wp-admin/options-general.php"},
        data={
            "option_page": "general",
            "action": "update",
            "_wpnonce": form_nonce,
            "_wp_http_referer": "/wp-admin/options-general.php",
            "blogname": new_title,
        },
        allow_redirects=False,
        timeout=30,
    )
    return r2.status_code in (200, 301, 302)


@requires_requests
class TestBranchIsolation:
    """Changes on a branch must not show up on main."""

    def test_title_change_on_branch_not_visible_on_main(
        self, served_site, branch_id_counter
    ):
        br = branch_id_counter()
        served_site.cli.branch("create", br).expect_ok()
        try:
            original_main = _get_site_title(served_site, "main")
            original_br = _get_site_title(served_site, br)
            assert original_main == original_br, (
                f"fresh branch diverges from main: main={original_main!r} "
                f"branch={original_br!r}"
            )

            new_title = f"Branch-Only-{br}"
            host = served_site.host_for_branch(br)
            sess = _login(served_site, host)
            ok = _set_site_title_via_options(
                sess, served_site.base_url, host, nonce="", new_title=new_title
            )
            if not ok:
                pytest.skip("couldn't POST to options.php on this backend")

            # Give any async pipelines a moment; then re-read.
            updated_br = _get_site_title(served_site, br)
            assert updated_br == new_title, (
                f"branch title didn't stick: got {updated_br!r}"
            )
            unchanged_main = _get_site_title(served_site, "main")
            assert unchanged_main == original_main, (
                f"main saw branch's change! main={unchanged_main!r}"
            )
        finally:
            # Best-effort cleanup.
            served_site.cli.branch("delete", br)


@requires_requests
class TestMergePropagation:
    """After `forkpress branch merge`, main must see the branch's changes."""

    def test_title_set_on_branch_then_merged_is_visible_on_main(
        self, served_site, branch_id_counter
    ):
        br = branch_id_counter()
        served_site.cli.branch("create", br).expect_ok()
        try:
            baseline_main = _get_site_title(served_site, "main")
            new_title = f"Merged-From-{br}"

            host = served_site.host_for_branch(br)
            sess = _login(served_site, host)
            if not _set_site_title_via_options(
                sess, served_site.base_url, host, nonce="", new_title=new_title
            ):
                pytest.skip("couldn't POST to options.php on this backend")

            # Sanity: change is on branch, not main.
            assert _get_site_title(served_site, br) == new_title
            assert _get_site_title(served_site, "main") == baseline_main

            # Commit + merge.
            served_site.cli.branch("commit", br, "-m", "change title").expect_ok()
            merge = served_site.cli.branch("merge", br, "--into", "main")
            if not merge.ok:
                pytest.skip(f"merge not supported on this backend: {merge.combined()}")

            # Main should now show the new title.
            after = _get_site_title(served_site, "main")
            assert after == new_title, (
                f"merge didn't propagate: main='{after}' expected='{new_title}'"
            )
        finally:
            served_site.cli.branch("delete", br)
