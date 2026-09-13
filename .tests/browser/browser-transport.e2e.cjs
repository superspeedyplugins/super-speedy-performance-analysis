const {
  wp,
  json,
  output,
  state,
  session,
  admin,
  response,
  expect,
} = require("./fleet-context.cjs");
(async () => {
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  try {
    await admin(page);
    const start = page.locator("#sspa-run-analysis");
    await expect(start).toBeVisible();
    wp("option", "update", "sspa_fleet_browser_transport", "1");
    const started = response(page, "sspa_start_run");
    await start.click();
    const reply = await (await started).json();
    expect(reply.success).toBe(true);
    const id = reply.data.run_id;
    expect(id).toBeGreaterThan(0);
    console.log("Browser transport run", id);
    await expect(start).toBeDisabled();
    await expect(page.locator("#sspa-runner")).toBeVisible();
    await expect
      .poll(() => state(id).done, { timeout: 120000 })
      .toBeGreaterThan(0);
    expect(state(id).transport).toBe("browser");
    await page.locator("#sspa-runner .sspa-runner-head").click();
    await expect(page.locator("#sspa-runner")).toHaveClass(/sspa-runner-min/);
    await page.locator('.nav-tab[data-tab="tools"]').click();
    await expect(page.locator("#sspa-runner")).toBeVisible();
    await page.reload();
    await expect(page.locator("#sspa-runner")).toBeVisible();
    let priorProgress = "";
    await expect
      .poll(
        () => {
          const current = state(id);
          const progress = `${current.status} ${current.done}/${current.total}`;
          if (progress !== priorProgress) {
            console.log("Actual stored progress", id, progress);
            priorProgress = progress;
          }
          return current.status;
        },
        { timeout: 900000 },
      )
      .toBe("done");
    const count = json(
      `global $wpdb;echo wp_json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM ".SSPA_Schema::table('profiles')." WHERE run_id=${id}"));`,
    );
    expect(count).toBe(state(id).total);
    console.log(
      "Completed persisted profile count",
      count,
      JSON.stringify(state(id)),
    );
    await page.screenshot({
      path: output + "/measurement-browser-complete.png",
      fullPage: true,
    });
    wp("option", "update", "sspa_fleet_browser_transport", "0");
    expect(errors).toEqual([]);
    console.log(
      "PASS browser transport baseline, actual captures and navigation/reload monitor",
    );
  } finally {
    wp("option", "update", "sspa_fleet_browser_transport", "0");
    await page.screenshot({
      path: output + "/browser-transport-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
