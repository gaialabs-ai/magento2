/**
 * Flow 3 — Tag Product List (M2+ scope, /tag/product/list)
 *
 * QA report finding:
 *   GET /tag/product/list → HTTP 404 — Magento standard "Whoops, our bad..." page.
 *   h1 text: "Whoops, our bad..."
 *
 * This is an INTENTIONAL 404 for Milestone 1:
 *   Origin (magento-lts): Mage_Tag_ProductController::listAction returns a rendered
 *   product grid filtered by tag.
 *   Target (M1): Controller/Product/ListAction.php is Milestone 2 scope; not yet
 *   implemented. 404 is the correct and expected behaviour.
 *
 * Verdict in QA report pair_key "tag-product-list-404": diff — intentional.
 */

import { test, expect } from "@playwright/test";

test.describe("Tag Product List — intentional 404 in M1", () => {
  test("GET /tag/product/list returns 404 (not yet implemented in M1)", async ({
    page,
  }) => {
    // The route is unregistered in M1 — Magento serves its standard 404 page.
    const response = await page.goto("/tag/product/list");
    // QA report: response.status() is 404
    expect(response?.status()).toBe(404);
  });

  test("404 page shows Magento standard error content", async ({ page }) => {
    await page.goto("/tag/product/list");
    // QA report: h1 text "Whoops, our bad..."
    // Actual DOM: <h1 class="page-title"><span class="base">Whoops, our bad...</span></h1>
    const errorHeading = page.locator("h1.page-title span.base");
    await expect(errorHeading).toBeVisible();
    await expect(errorHeading).toHaveText(/Whoops/i);
  });

  test("404 page retains the Luma store header and navigation", async ({
    page,
  }) => {
    await page.goto("/tag/product/list");
    // QA report: "Store navigation is intact (header, footer)"
    await expect(page.locator("input#search")).toBeVisible();
  });
});
