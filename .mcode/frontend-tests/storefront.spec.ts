import { test, expect } from "@playwright/test";

test.describe("Storefront with Magento_Tag enabled", () => {
  test("home page renders CMS content and header/footer", async ({ page }) => {
    const resp = await page.goto("/");
    expect(resp?.status()).toBe(200);
    await expect(page).toHaveTitle("Home page");
    await expect(page.getByText("CMS homepage content goes here.")).toBeVisible();
    await expect(page.getByPlaceholder("Search entire store here...")).toBeVisible();
    await expect(page.getByRole("link", { name: "Sign In" }).first()).toBeVisible();
    await expect(page.getByRole("link", { name: "Create an Account" }).first()).toBeVisible();
    await expect(page.getByRole("button", { name: "Subscribe" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Contact Us" })).toBeVisible();
  });

  test("customer login page renders form", async ({ page }) => {
    const resp = await page.goto("/index.php/customer/account/login/");
    expect(resp?.status()).toBe(200);
    await expect(page.getByRole("heading", { level: 1, name: "Customer Login" })).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Email" })).toBeVisible();
    await expect(page.getByRole("textbox", { name: "Password" })).toBeVisible();
    await expect(page.getByRole("checkbox", { name: "Show Password" })).toBeVisible();
    await expect(page.getByRole("button", { name: "Sign In" })).toBeVisible();
    await expect(page.getByRole("link", { name: "Forgot Your Password?" })).toBeVisible();
    await expect(page.getByRole("heading", { name: "New Customers" })).toBeVisible();
  });
});
