import { test, expect } from "@playwright/test";
import {
  FIXTURE_CUSTOMER,
  FIXTURE_PRODUCT_PATH,
  loginAsCustomer,
  countTagsNamed,
} from "./helpers";

/**
 * E2E coverage for the Magento_Tag "proof of life" PDP slice (ported from
 * legacy Mage_Tag). Source of truth for selectors/flows/expected behavior is
 * the QA exploration report at $MCODE_DIR/fe_testing/qa-report.md.
 *
 * Scope, per the milestone spec and QA report:
 *  - A logged-in customer can submit a space/quote-delimited tag string via
 *    #addTagForm, the tokenizer splits on spaces but keeps single-quoted
 *    multi-word phrases intact, and a server-rendered success message with
 *    the correct token count is shown after the redirect-back.
 *  - A guest submitting the same form is redirected to the storefront login
 *    page (customer/account/login), matching legacy's
 *    customerSession->authenticate($this) guard in
 *    Mage_Tag_IndexController::saveAction() — nothing is persisted and no
 *    success message is shown for the unauthenticated request.
 *  - There is intentionally no on-page reflection of submitted tags (no tag
 *    chips/list, no "My Tags" page) — that read-side UI is out of scope for
 *    this milestone (deferred to Milestone 3), so these tests don't look
 *    for one.
 */

function uniqueToken(prefix: string): string {
  // Unique per test run so assertions on "N tag(s) accepted" and DB row
  // counts aren't polluted by tags left behind by earlier runs (the tag
  // table is never cleaned up between runs) or by concurrent test workers.
  return `${prefix}${Date.now()}${Math.floor(Math.random() * 10000)}`;
}

test.describe("Magento_Tag PDP form — logged-in customer", () => {
  test("submits space-separated tags plus a quoted multi-word phrase and sees a tokenized success message", async ({
    page,
  }) => {
    await loginAsCustomer(page, FIXTURE_CUSTOMER.email, FIXTURE_CUSTOMER.password);

    await page.goto(FIXTURE_PRODUCT_PATH);

    const form = page.locator("#addTagForm");
    await expect(form).toBeVisible();
    await expect(page.locator('label[for="productTagName"]')).toHaveText(/Add Your Tags/);

    const wordA = uniqueToken("alpha");
    const wordB = uniqueToken("beta");
    const phrase = `${uniqueToken("gamma")} ${uniqueToken("delta")}`; // multi-word quoted phrase
    const tagInput = form.locator("#productTagName");
    await tagInput.fill(`${wordA} '${phrase}' ${wordB}`);

    await form.getByRole("button", { name: "Add Tags" }).click();

    // Full page POST + redirect back to the same PDP (no AJAX/SPA state).
    await page.waitForURL(new RegExp(FIXTURE_PRODUCT_PATH.replace(".", "\\.") + "$"));

    // Exactly 3 tokens parsed: wordA, the quoted two-word phrase (kept
    // intact, not split on its internal space), and wordB.
    await expect(
      page.getByText("3 tag(s) have been accepted for moderation.")
    ).toBeVisible();
    await expect(page.locator(".message-success")).toBeVisible();

    // Fresh server-rendered form after the full-page reload — no leftover
    // client-side state to assert against, just an empty input.
    await expect(page.locator("#productTagName")).toHaveValue("");

    // Confirm the quoted phrase was tokenized as ONE tag server-side, not
    // split into two, and that all three distinct tokens were persisted.
    expect(countTagsNamed(wordA)).toBe(1);
    expect(countTagsNamed(wordB)).toBe(1);
    expect(countTagsNamed(phrase)).toBe(1);
  });

  test("re-submitting an already-pending tag still reports it as newly accepted, not a duplicate", async ({
    page,
  }) => {
    // Per Tag::saveRelation()'s ported state machine, a tag in
    // STATUS_PENDING always returns ADD_STATUS_NEW regardless of whether
    // the relation already exists — the "already added" message only fires
    // for approved tags, which aren't reachable in this flow without admin
    // moderation. See qa-report.md.
    await loginAsCustomer(page, FIXTURE_CUSTOMER.email, FIXTURE_CUSTOMER.password);
    await page.goto(FIXTURE_PRODUCT_PATH);

    const repeatTag = uniqueToken("repeat");
    const form = page.locator("#addTagForm");

    await form.locator("#productTagName").fill(repeatTag);
    await form.getByRole("button", { name: "Add Tags" }).click();
    await page.waitForURL(new RegExp(FIXTURE_PRODUCT_PATH.replace(".", "\\.") + "$"));
    await expect(
      page.getByText("1 tag(s) have been accepted for moderation.")
    ).toBeVisible();

    // Submit the exact same tag name again while it's still pending.
    await form.locator("#productTagName").fill(repeatTag);
    await form.getByRole("button", { name: "Add Tags" }).click();
    await page.waitForURL(new RegExp(FIXTURE_PRODUCT_PATH.replace(".", "\\.") + "$"));
    await expect(
      page.getByText("1 tag(s) have been accepted for moderation.")
    ).toBeVisible();

    // Only one `tag` row should exist for this name — the second submit
    // reuses the existing pending tag rather than creating a duplicate.
    expect(countTagsNamed(repeatTag)).toBe(1);
  });
});

test.describe("Magento_Tag PDP form — guest (not logged in)", () => {
  test("redirects to login and persists nothing", async ({
    page,
  }) => {
    // Fresh, unauthenticated context (Playwright's default `page` fixture
    // has no storage state / cookies) — equivalent to a guest visitor.
    await page.goto(FIXTURE_PRODUCT_PATH);

    const form = page.locator("#addTagForm");
    await expect(form).toBeVisible();

    const guestTag = uniqueToken("guestattempt");
    await form.locator("#productTagName").fill(guestTag);
    await form.getByRole("button", { name: "Add Tags" }).click();

    // Matches legacy's customerSession->authenticate($this) guard: the
    // unauthenticated POST is bounced to the storefront login page instead
    // of being processed.
    await page.waitForURL(/\/customer\/account\/login/);
    expect(page.url()).toMatch(/customer\/account\/login/);

    // Nothing was written server-side for the guest's tag name.
    expect(countTagsNamed(guestTag)).toBe(0);
  });
});
