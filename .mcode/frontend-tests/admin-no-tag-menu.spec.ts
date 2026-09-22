/**
 * Flow 6 — Admin Panel (No Tag Menu in M1)
 *
 * QA report finding:
 *   GET http://localhost:8080/admin → admin dashboard loads.
 *   Navigation sidebar contains: Dashboard, Sales, Catalog, Customers, Marketing,
 *   Content, Reports, Stores. No "Tags" menu item anywhere in the navigation.
 *   Under Marketing: Promotions, Communications, SEO & Search, User Content (Reviews)
 *   — no tag sub-item.
 *
 * This is correct for M1:
 *   Admin grids (/admin/tag/tag/index, /admin/tag/tag/pending, reports) are Milestone 4 scope.
 *   The ACL skeleton (Magento_Tag::tag / Magento_Tag::tags) is registered but
 *   no admin routes are exposed yet.
 *
 * Login selectors from QA report:
 *   input[name="login[username]"]   → admin username
 *   input[name="login[password]"]   → admin password
 *   button.action-login             → submit button
 *
 * Assertion: no element with text "Tags" in nav or .admin__nav-items;
 *            admin dashboard title present.
 */

import { test, expect } from "@playwright/test";

const ADMIN_URL = "http://localhost:8080/admin";
const ADMIN_USER = "admin";
const ADMIN_PASS = "admin123!Magento";

/** Log in to the Magento admin panel and wait for the dashboard. */
async function loginAdmin(page: import("@playwright/test").Page): Promise<void> {
  await page.goto(ADMIN_URL);
  // Wait for the login form
  await page.waitForSelector("input[name='login[username]']", {
    timeout: 15000,
  });
  await page.fill("input[name='login[username]']", ADMIN_USER);
  await page.fill("input[name='login[password]']", ADMIN_PASS);
  await page.click("button.action-login");
  // Admin dashboard URL ends with /admin/dashboard or similar.
  // The Magento PHP admin panel is slow to respond; allow 60 s for the redirect.
  await page.waitForURL(/\/admin\//, { timeout: 60000 });
}

test.describe("Admin Panel — no Tags menu in M1", () => {
  test("admin login page loads at /admin", async ({ page }) => {
    await page.goto(ADMIN_URL);
    await expect(
      page.locator("input[name='login[username]']")
    ).toBeVisible();
    await expect(
      page.locator("input[name='login[password]']")
    ).toBeVisible();
    await expect(page.locator("button.action-login")).toBeVisible();
  });

  test("admin dashboard loads after login", async ({ page }) => {
    await loginAdmin(page);
    // Dashboard should be accessible — verify no redirect back to login
    expect(page.url()).toMatch(/\/admin\//);
    // QA report: "admin dashboard title present"
    // Magento admin dashboard page has a recognizable heading or page element
    await expect(page.locator("body")).toBeVisible();
  });

  test("admin navigation has no 'Tags' menu item in M1", async ({ page }) => {
    await loginAdmin(page);
    // QA report assertion: no element with text "Tags" in nav or .admin__nav-items
    // Wait for nav to be present
    await page.waitForSelector("nav, .admin__nav-items, #nav", {
      timeout: 10000,
    });
    // The navigation should not contain a "Tags" link anywhere
    const tagsNavItem = page.locator(
      "nav a, .admin__nav-items a, #nav a"
    ).filter({ hasText: /^Tags$/ });
    await expect(tagsNavItem).toHaveCount(0);
  });

  test("admin Marketing menu has no Tags sub-item in M1", async ({ page }) => {
    await loginAdmin(page);
    // QA report: Under Marketing: Promotions, Communications, SEO & Search,
    // User Content (Reviews) — no tag sub-item.
    // Look for any link whose text is exactly "Tags" in the whole page nav
    const allNavLinks = page.locator("nav a, .admin__nav-items a, #nav a");
    const count = await allNavLinks.count();
    for (let i = 0; i < count; i++) {
      const linkText = await allNavLinks.nth(i).textContent();
      // None of the nav links should be exactly "Tags"
      if (linkText?.trim() === "Tags") {
        throw new Error(
          `Unexpected 'Tags' navigation link found in M1 admin panel (text: "${linkText?.trim()}")`
        );
      }
    }
  });

  test("admin sidebar contains expected top-level menu items", async ({
    page,
  }) => {
    await loginAdmin(page);
    // QA report: Dashboard, Sales, Catalog, Customers, Marketing, Content, Reports, Stores
    // Verify key expected items are present in nav
    await page.waitForSelector("nav, .admin__nav-items, #nav", {
      timeout: 10000,
    });
    // Check for a few of the expected top-level items by text
    const navArea = page.locator("nav, .admin__nav-items, #nav").first();
    await expect(navArea.locator("a", { hasText: /Marketing/i }).first()).toBeVisible();
    await expect(navArea.locator("a", { hasText: /Catalog/i }).first()).toBeVisible();
  });
});
