const {
  wp,
  json,
  output,
  session,
  admin,
  response,
  expect,
} = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  try {
    await admin(page, "pages");
    await page
      .locator(".sspa-page-row")
      .filter({ hasText: "Home" })
      .first()
      .click();
    const panel = page.locator("#sspa-adhoc-pop");
    await expect(panel.locator(".sspa-adhoc-rerun")).toBeVisible();
    wp("option", "update", "sspa_fleet_http_error", "1");
    const result = response(page, "sspa_adhoc_start");
    await panel.locator(".sspa-adhoc-rerun").click();
    const started = await (await result).json();
    expect(started.success).toBe(true);
    const runId = started.data.run_id;
    await expect(panel.locator(".sspa-adhoc-error")).toBeVisible({
      timeout: 60000,
    });
    await expect(panel.locator(".sspa-adhoc-error")).toContainText(
      "Request 1 was unsuccessful: HTTP 503.",
    );
    const rows = json(
      `global $wpdb;echo wp_json_encode($wpdb->get_results("SELECT samples FROM ".SSPA_Schema::table('profiles')." WHERE run_id=${runId}",ARRAY_A));`,
    );
    expect(rows.length).toBe(1);
    expect(
      JSON.parse(rows[0].samples).some((sample) => Number(sample.code) === 503),
    ).toBe(true);
    await page.screenshot({
      path: output + "/measured-http-error.png",
      fullPage: true,
    });
    console.log(
      "PASS real failed measured Home request presents an actionable error",
    );
  } finally {
    wp("option", "update", "sspa_fleet_http_error", "0");
    await page.screenshot({
      path: output + "/measurement-error-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
