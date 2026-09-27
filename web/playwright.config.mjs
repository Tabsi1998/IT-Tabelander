import { devices } from "@playwright/test";
import { defineConfig } from "@playwright/test";

// The built and prerendered site (npm run build), served by `vite preview`
// with the same security policy as the backend. The e2e tests answer the API
// themselves (e2e/fixtures.js), so every state - no reviews, three, thirty -
// is reproducible without a backend.
const port = Number(process.env.WEB_E2E_PORT || 3011);
const chrome = devices["Desktop Chrome"];

export default defineConfig({
  testDir: "./e2e",
  timeout: 30_000,
  expect: { timeout: 8_000 },
  fullyParallel: true,
  workers: process.env.WEB_E2E_WORKERS ? Number(process.env.WEB_E2E_WORKERS) : 6,
  reporter: [["list"], ["html", { open: "never", outputFolder: "playwright-report" }]],
  use: {
    baseURL: `http://127.0.0.1:${port}`,
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
  },
  webServer: {
    command: `node node_modules/vite/bin/vite.js preview --host 127.0.0.1 --port ${port} --strictPort`,
    url: `http://127.0.0.1:${port}`,
    reuseExistingServer: false,
    timeout: 60_000,
  },
  // The four widths of #55: phone, tablet, laptop, large desktop.
  projects: [
    { name: "handy", use: { ...devices["Pixel 5"], viewport: { width: 390, height: 844 } } },
    { name: "tablet", use: { ...chrome, viewport: { width: 768, height: 1024 } } },
    { name: "laptop", use: { ...chrome, viewport: { width: 1280, height: 800 } } },
    { name: "desktop", use: { ...chrome, viewport: { width: 1440, height: 900 } } },
  ],
});
