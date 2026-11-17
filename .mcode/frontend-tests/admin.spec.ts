import { test, expect } from "@playwright/test";

const PASSWORD = process.env.ADMIN_PASSWORD || "Admin123456!";

test.describe("Admin with Magento_Tag enabled", () => {
  test("admin login page renders form", async ({ page }) => {
    await page.goto("/index.php/admin");
    await expect(page.getByRole("textbox", { name: "Username" })).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Password" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Sign in" })).toBeVisible();
  });

  test("admin can log in; dashboard loads and has no Tag menu entry", async ({ page }) => {
    await page.goto("/index.php/admin");
    await page.getByRole("textbox", { name: "Username" }).fill("admin");
    await page.getByRole("textbox", { name: "Password" }).fill(PASSWORD);
    await page.getByRole("button", { name: "Sign in" }).click();
    await expect(page.getByRole("heading", { level: 1, name: "Dashboard" })).toBeVisible({ timeout: 60000 });
    await page.waitForLoadState("networkidle");
    const dont = page.getByRole("button", { name: "Don't Allow" });
    if (await dont.isVisible().catch(() => false)) await dont.click();
    const menu = page.locator("#nav");
    for (const item of ["Dashboard", "Sales", "Catalog", "Customers", "Marketing", "Content", "Reports", "Stores", "System"]) {
      await expect(menu.getByText(item, { exact: true }).first()).toBeVisible();
    }
    // Milestone adds no admin UI: no tag menu entries.
    await expect(menu.getByText(/\btags?\b/i)).toHaveCount(0);
  });
});
