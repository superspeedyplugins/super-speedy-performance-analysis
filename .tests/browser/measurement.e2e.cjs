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
    // Prior drop-in regressions deliberately retain an orphan Query Monitor fixture.
    // Exercise the real repair control before requesting destructive-query protection.
    const repair = page.locator("#sspa-replace-stale-dropin");
    if (await repair.count()) {
      const repaired = response(page, "sspa_replace_stale_dropin");
      await repair.click();
      expect((await (await repaired).json()).success).toBe(true);
      await page.reload();
      await expect(repair).toHaveCount(0);
    }
    const start = page.locator("#sspa-run-analysis");
    await expect(start).toBeVisible();
    wp("option", "update", "sspa_fleet_browser_transport", "0");
    const started = response(page, "sspa_start_run");
    await start.click();
    const reply = await (await started).json();
    expect(reply.success).toBe(true);
    const id = reply.data.run_id;
    expect(id).toBeGreaterThan(0);
    await expect(start).toBeDisabled();
    await expect(page.locator("#sspa-runner")).toBeVisible();
    await expect
      .poll(() => state(id).done, { timeout: 120000 })
      .toBeGreaterThan(0);
    expect(state(id).transport).toBe("loopback");
    await page.locator("#sspa-runner .sspa-runner-head").click();
    await expect(page.locator("#sspa-runner")).toHaveClass(/sspa-runner-min/);
    await page.locator('.nav-tab[data-tab="tools"]').click();
    await expect(page.locator("#sspa-runner")).toBeVisible();
    await page.reload();
    await expect(page.locator("#sspa-runner")).toBeVisible();
    await expect.poll(() => state(id).status, { timeout: 240000 }).toBe("done");
    const count = json(
      `global $wpdb;echo wp_json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM ".SSPA_Schema::table('profiles')." WHERE run_id=${id}"));`,
    );
    expect(count).toBe(state(id).total);
    await page.screenshot({
      path: output + "/measurement-browser-complete.png",
      fullPage: true,
    });
    wp("option", "update", "sspa_fleet_browser_transport", "0");
    await admin(page);
    await page.locator("#sspa-run-deep").click();
    const box = page.locator(
      '.sspa-adhoc-pluginpick[value="sspa-browser-fixture"]',
    );
    await expect(box).toBeVisible();
    await box.check();
    const deepStarted = response(page, "sspa_start_run");
    await page.locator(".sspa-adhoc-measure-start").click();
    const deepReply = await (await deepStarted).json();
    expect(deepReply.success, JSON.stringify(deepReply)).toBe(true);
    const deep = deepReply.data.run_id;
    await expect(page.locator("#sspa-runner")).toBeVisible();
    if (
      (await page.locator("#sspa-runner").getAttribute("class")).includes(
        "sspa-runner-min",
      )
    ) {
      await page.locator("#sspa-runner .sspa-runner-head").click();
    }
    await expect(page.locator("#sspa-runner-cancel")).toBeVisible();
    page.once("dialog", (d) => d.accept());
    const cancelled = response(page, "sspa_cancel_run");
    await page.locator("#sspa-runner-cancel").click();
    await cancelled;
    await expect.poll(() => state(deep).status).toBe("cancelled");
    await page.reload();
    await admin(page, "pages");
    await page
      .locator(".sspa-page-row")
      .filter({ hasText: "Home" })
      .first()
      .click();
    await page.locator("#sspa-adhoc-pop .sspa-adhoc-measure").click();
    await box.check();
    const restart = response(page, "sspa_start_run");
    await page.locator(".sspa-adhoc-measure-start").click();
    const restartReply = await (await restart).json();
    expect(restartReply.success, JSON.stringify(restartReply)).toBe(true);
    const completed = restartReply.data.run_id;
    await expect
      .poll(() => state(completed).status, { timeout: 240000 })
      .toBe("done");
    const impacts = json(
      `global $wpdb;$rows=$wpdb->get_results("SELECT plugin,page_key,delta_sql_ms,test_run_id FROM ".SSPA_Schema::table('plugin_impacts')." WHERE test_run_id=${completed}",ARRAY_A);if($wpdb->last_error){throw new RuntimeException($wpdb->last_error);}echo wp_json_encode($rows);`,
    );
    expect(impacts).toHaveLength(1);
    expect(impacts[0].plugin).toBe("sspa-browser-fixture");
    expect(impacts[0].page_key).toBe("home");
    expect(Number(impacts[0].delta_sql_ms)).toBeGreaterThan(40);
    console.log(
      "Completed measured impact",
      completed,
      JSON.stringify(impacts),
    );
    expect(errors).toEqual([]);
    console.log(
      "PASS normal baseline, actual captures, navigation/reload monitor, site impact cancellation and completed Home impact restart",
    );
  } finally {
    wp("option", "update", "sspa_fleet_browser_transport", "0");
    await page.screenshot({
      path: output + "/measurement-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
