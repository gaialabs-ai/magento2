/**
 * Flow 1 — Storefront Homepage (/)
 *
 * QA report finding: Luma theme homepage loads with M2 header; no Magento_Tag UI
 * present — intentional for M1 (backend scaffolding only). M2/M3 milestones add
 * the tag widget and customer-account nav item.
 *
 * Selectors sourced from QA report:
 *   - Sign-in link:  li.authorization-link a  (text "Sign In")
 *   - Search input:  input#search
 *   - Cart count:    a.action.showcart span.counter.qty
 */

import { test, expect } from "@playwright/test";

test.describe("Storefront Homepage — Luma theme baseline", () => {
  test("page loads with HTTP 200 and correct title", async ({ page }) => {
    const response = await page.goto("/");
    expect(response?.status()).toBe(200);
    // QA report: document.title contains "Home page"
    await expect(page).toHaveTitle(/Home page/i);
  });

  test("search input is present and visible", async ({ page }) => {
    await page.goto("/");
    // QA report selector: input#search[placeholder="Search entire store here..."]
    const searchInput = page.locator("input#search");
    await expect(searchInput).toBeVisible();
    await expect(searchInput).toHaveAttribute(
      "placeholder",
      /Search entire store here/i
    );
  });

  test("sign-in link is present and points to customer login", async ({
    page,
  }) => {
    await page.goto("/");
    // QA report selector: li.authorization-link a  text "Sign In"
    const signInLink = page.locator("li.authorization-link a").first();
    await expect(signInLink).toBeVisible();
    await expect(signInLink).toHaveText(/Sign In/i);
    // QA report assertion: href matches *customer/account/login*
    const href = await signInLink.getAttribute("href");
    expect(href).toMatch(/customer\/account\/login/);
  });

  test("M1 adds no Magento_Tag-specific UI elements on homepage", async ({
    page,
  }) => {
    await page.goto("/");
    // M1 delivers backend scaffolding only — no tag widget, no popular-tags block.
    // Assert the tag-related elements that will appear in M2/M3 are NOT present.
    await expect(page.locator(".popular-tags")).not.toBeVisible();
    await expect(page.locator(".block-tags")).not.toBeVisible();
  });
});
