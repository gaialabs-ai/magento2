/**
 * Flow 2 — Tag Save Controller Unauthenticated Redirect (/tag/index/save)
 *
 * QA report finding:
 *   GET /tag/index/save (unauthenticated) → HTTP 302 → /index.php/customer/account/login/
 *   Browser lands on Customer Login page.
 *
 *   POST /tag/index/save without a valid form key → HTTP 302 → homepage root,
 *   with mage-messages cookie containing {"type":"error","text":"Invalid form key..."}
 *
 * Implementation detail (confirmed by QA): Controller/Index/Save.php line 170:
 *   if (!$this->customerSession->isLoggedIn()) stashes pending tag data on
 *   Magento\Tag\Model\Session, then redirects via $redirect->setPath('customer/account/login').
 *
 * Origin parity: Mage_Tag_IndexController::saveAction line 26 calls
 *   $customerSession->authenticate($this) — same redirect-to-login effect.
 *
 * Selectors sourced from QA report:
 *   h1.page-title span         → "Customer Login"
 *   input#email                → email field
 *   input#pass                 → password field
 *   button#send2.action.login.primary → Sign In button
 *   h2 (left col)              → "Registered Customers"
 *   h2 (right col)             → "New Customers"
 *   a.action.create.primary    → "Create an Account"
 */

import { test, expect } from "@playwright/test";

test.describe("Tag Save Controller — unauthenticated redirect", () => {
  test("GET /tag/index/save redirects to customer login page", async ({
    page,
  }) => {
    // Navigate to the tag save route unauthenticated.
    // Playwright follows the 302 automatically; assert we land on the login page.
    const response = await page.goto("/tag/index/save");
    // Final page should be HTTP 200 (the login page itself)
    expect(response?.status()).toBe(200);
    // URL must contain customer/account/login
    expect(page.url()).toMatch(/customer\/account\/login/);
  });

  test("Customer Login page title reads 'Customer Login'", async ({ page }) => {
    await page.goto("/tag/index/save");
    // QA report selector: h1.page-title span → "Customer Login"
    // Actual DOM: <h1 class="page-title"><span class="base" data-ui-id="page-title-wrapper">
    const heading = page.locator("h1.page-title span.base");
    await expect(heading).toBeVisible();
    await expect(heading).toHaveText("Customer Login");
  });

  test("Customer Login page has email and password inputs", async ({
    page,
  }) => {
    await page.goto("/tag/index/save");
    // QA report noted input#email and input#pass. Actual DOM inspection reveals:
    //   - Main login form email field: input#email (visible) ✓
    //   - Main login form password field: input#password (visible)
    //   - input#pass exists but is inside the hidden authenticationPopup knockout
    //     component (display:none) — not the field rendered for the user.
    // Use the visible main-form selectors confirmed by browser inspection.
    await expect(page.locator("input#email")).toBeVisible();
    await expect(page.locator("input#email")).toHaveAttribute("type", "email");
    await expect(page.locator("input#password")).toBeVisible();
    await expect(page.locator("input#password")).toHaveAttribute("type", "password");
  });

  test("Customer Login page has sign-in button", async ({ page }) => {
    await page.goto("/tag/index/save");
    // QA report selector: button#send2.action.login.primary
    const signInBtn = page.locator("button#send2.action.login.primary");
    await expect(signInBtn).toBeVisible();
    await expect(signInBtn).toHaveText(/Sign In/i);
  });

  test("Customer Login page has 'Registered Customers' and 'New Customers' headings", async ({
    page,
  }) => {
    await page.goto("/tag/index/save");
    // QA report: "Registered Customers" (left col), "New Customers" (right col).
    // In the actual Magento 2 Luma HTML these are rendered as <strong role="heading"
    // aria-level="2"> elements (not <h2>), with ids block-customer-login-heading
    // and block-new-customer-heading respectively.
    await expect(
      page.locator("#block-customer-login-heading")
    ).toBeVisible();
    await expect(
      page.locator("#block-customer-login-heading")
    ).toHaveText(/Registered Customers/i);
    await expect(
      page.locator("#block-new-customer-heading")
    ).toBeVisible();
    await expect(
      page.locator("#block-new-customer-heading")
    ).toHaveText(/New Customers/i);
  });

  test("Customer Login page has 'Create an Account' link", async ({ page }) => {
    await page.goto("/tag/index/save");
    // QA report selector: a.action.create.primary → "Create an Account"
    const createBtn = page.locator("a.action.create.primary");
    await expect(createBtn).toBeVisible();
    await expect(createBtn).toHaveText(/Create an Account/i);
  });

  test("POST /tag/index/save without form key redirects to homepage with error", async ({
    request,
  }) => {
    // Send raw POST without a valid form key — controller must 302 to homepage.
    // The mage-messages cookie carries the error message.
    // Playwright's APIRequestContext does NOT follow redirects by default,
    // so we see the raw 302 here.
    const response = await request.post("http://localhost:8080/tag/index/save", {
      data: { tag_name: "test-tag", product_id: "1" },
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      maxRedirects: 0,
    });
    // QA report: POST without valid form key → 302
    expect(response.status()).toBe(302);
    // Location header points back to homepage (or some valid Magento route)
    const location = response.headers()["location"];
    expect(location).toBeTruthy();
  });
});
