import { execFileSync } from "child_process";
import * as fs from "fs";
import * as path from "path";
import type { Page } from "@playwright/test";

/**
 * Fixture customer account seeded by the QA exploration pass (see
 * $MCODE_DIR/fe_testing/qa-report.md). No env var carries these — the
 * target lifecycle's env_vars.json only exposes admin credentials — so this
 * is the one place a plaintext fixture password lives. It is a throwaway
 * local-sandbox DB account, not a production secret.
 */
export const FIXTURE_CUSTOMER = {
  email: "qatagtester@example.com",
  password: "QaTagTest123!",
};

/** Product QA used for the tag form, still present in the seeded DB. */
export const FIXTURE_PRODUCT_PATH = "/qa-tag-test-product.html";

/** Logs in via the storefront login form using role-based queries only —
 * the page has a duplicate `id="send2"` button elsewhere (header mini-login
 * widget), so bare #id selectors are ambiguous (see qa-report.md). */
export async function loginAsCustomer(page: Page, email: string, password: string) {
  await page.goto("/customer/account/login/");
  await page.getByRole("textbox", { name: "Email*" }).fill(email);
  await page.getByRole("textbox", { name: "Password*" }).fill(password);
  await page.getByRole("button", { name: "Sign In" }).click();
  // Successful login round-trips through an intermediate
  // /customer/account/login/referer/<base64>/ URL before landing on the
  // dashboard, so wait for the final destination rather than the first
  // navigation event.
  await page.waitForURL(/\/customer\/account\/?(\?.*)?$/, { timeout: 15000 });
}

interface DbConfig {
  host: string;
  user: string;
  password: string;
  name: string;
}

function readDbConfig(): DbConfig {
  const mcodeDir = process.env.MCODE_DIR;
  if (!mcodeDir) {
    throw new Error("MCODE_DIR is not set; cannot locate lifecycle env_vars.json");
  }
  const envVarsPath = path.join(mcodeDir, "lifecycle", "target", "env_vars.json");
  const raw = JSON.parse(fs.readFileSync(envVarsPath, "utf-8"));
  const env = raw.environment_variables ?? {};
  return {
    host: env.DB_HOST ?? "127.0.0.1",
    user: env.DB_USER ?? "magento",
    password: env.DB_PASSWORD ?? "magento",
    name: env.DB_NAME ?? "magento",
  };
}

/** Runs a single SQL statement against the target app's MySQL DB via the
 * `mysql` CLI and returns stdout (tab-separated, header row included). Used
 * to confirm the tag form's real server-side side effects (or lack thereof)
 * beyond what's directly observable in the DOM. */
export function queryDb(sql: string): string {
  const cfg = readDbConfig();
  return execFileSync(
    "mysql",
    [`-h${cfg.host}`, `-u${cfg.user}`, `-p${cfg.password}`, cfg.name, "-N", "-e", sql],
    { encoding: "utf-8" }
  );
}

export function countTagsNamed(name: string): number {
  const escaped = name.replace(/'/g, "''");
  const out = queryDb(`SELECT COUNT(*) FROM tag WHERE name='${escaped}';`);
  return parseInt(out.trim(), 10);
}
