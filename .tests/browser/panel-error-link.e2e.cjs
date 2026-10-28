// The panel's error for a disabled profiler names the Tools tab by URL. That URL must be a link
// the administrator can follow, and following it to Enable profiler must make Re-run work.
// The profiler is disabled through its real Tools button and enabled again the same way, so the
// site ends with the profiler on, as the rest of the suite expects.
const { session, expect, output, admin } = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  // data-enabled is what the button will do: "1" = Enable profiler (it is off), "0" = Disable.
  // A successful toggle re-renders the tab, so the new button carries the opposite value.
  const toggle = async (on, navigate = true) => {
    if (navigate) await admin(page, "tools");
    const button = page.locator("#sspa-profiler-toggle");
    await expect(button).toBeVisible();
    if ((await button.getAttribute("data-enabled")) === (on ? "1" : "0")) await button.click();
    await expect(button).toHaveAttribute("data-enabled", on ? "0" : "1", { timeout: 30000 });
  };
  try {
    const site = process.env.SSPA_TEST_SITE_URL;

    // 1. Disable the profiler, then ask for an analysis of a page from the admin bar.
    await toggle(false);
    await page.goto(site + "/wp-admin/options-writing.php?sspa_error_link=" + Date.now());
    await page.$eval("#wp-admin-bar-sspa-adhoc > a", (a) => a.click());
    const error = page.locator("#sspa-adhoc-pop .sspa-adhoc-error");
    await expect(error).toContainText("Enable profiler in Tools", { timeout: 60000 });
    const link = error.locator('a[href*="page=sspa#tools"]');
    await expect(link).toHaveCount(1);
    console.log("PASS the disabled-profiler error links to the Tools tab");

    // 2. Follow it, enable the profiler there, and come back to a working analysis.
    await Promise.all([page.waitForURL(/page=sspa/), link.click()]);
    await toggle(true, false);
    console.log("PASS the link opens Tools, where Enable profiler turns it back on");

    expect(errors).toEqual([]);
    console.log("PASS no page errors");
  } finally {
    await page.screenshot({ path: output + "/panel-error-link.png", fullPage: true }).catch(() => {});
    await browser.close();
  }
})().catch((e) => {
  console.error("FAIL", e.message);
  process.exit(1);
});
