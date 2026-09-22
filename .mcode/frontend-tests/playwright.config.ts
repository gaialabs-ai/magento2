import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: ".",
  // Magento PHP server is slow (~9 s/page under single-worker load).
  // 90 s per test gives plenty of headroom for page loads + interactions.
  timeout: 90000,
  retries: 1,
  // Run sequentially so the single PHP worker is not saturated by parallel requests.
  workers: 1,
  expect: {
    // Default Playwright expect timeout is 5 s, which is too tight for a slow PHP
    // server. Set to 30 s to match actionTimeout.
    timeout: 30000,
  },
  use: {
    baseURL: "http://localhost:8080",
    headless: true,
    screenshot: "on",
    video: "retain-on-failure",
    trace: "retain-on-failure",
    // Generous navigation timeout consistent with the per-test timeout.
    navigationTimeout: 60000,
    actionTimeout: 30000,
  },
  reporter: [
    ["list"],
    [
      "json",
      {
        outputFile: `${process.env.MCODE_DIR}/fe_testing/playwright-results.json`,
      },
    ],
  ],
});
