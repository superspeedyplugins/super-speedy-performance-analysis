// Issue 10. When the server cannot reach its own pages, an analysis started from the admin
// screen must still complete, driven by the administrator's browser, and store the same
// evidence as a loopback run. The loopback failure here is real: the retained browser fixture
// refuses the server's own profiler requests at the HTTP layer while a flag is set, exactly as
// a host firewall would, and ordinary browser access is untouched. Nothing forces the transport.
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
  // This transport test starts with PA's shim; the preceding QM agreement case retains QM.
  // Establish the declared fixture at entry, preserving any displaced vendor file.
  wp("eval", `
    $active = SSPA_Run_Controller::active_run_id();
    if ($active) SSPA_Run_Controller::cancel($active);
    deactivate_plugins('query-monitor/query-monitor.php', true);
    if (SSPA_Helper_Files::dropin_is_stale_qm()) {
      $result = SSPA_Helper_Files::replace_stale_qm_dropin();
      if (is_wp_error($result)) WP_CLI::error($result->get_error_message());
    }
    SSPA_Helper_Files::ensure_installed();
    if ('ours' !== SSPA_Helper_Files::dropin_status()) WP_CLI::error('Loopback fixture requires PA db.php');
  `);
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  try {
    wp("option", "update", "sspa_fleet_browser_transport", "0");
    wp("option", "update", "sspa_fleet_loopback_blocked", "1");
    wp("cache", "flush");
    const probe = json(
      `echo wp_json_encode(SSPA_Run_Controller::loopback_preflight());`,
    );
    expect(probe.healthy).toBe(false);
    console.log("Real loopback failure:", probe.reason);
    const pluginsBefore = json(`echo wp_json_encode(get_option('active_plugins'));`);
    const helpersBefore = json(
      `echo wp_json_encode(array('mu' => md5_file(SSPA_Helper_Files::mu_path()), 'dropin' => SSPA_Helper_Files::dropin_status()));`,
    );

    await admin(page);
    const start = page.locator("#sspa-run-analysis");
    await expect(start).toBeVisible();
    const started = response(page, "sspa_start_run");
    await start.click();
    const reply = await (await started).json();
    expect(reply.success).toBe(true);
    const id = reply.data.run_id;
    expect(id).toBeGreaterThan(0);
    console.log("Fallback run", id, "transport", state(id).transport);
    expect(state(id).transport).toBe("browser");
    await expect(page.locator("#sspa-runner")).toBeVisible();
    await expect
      .poll(() => state(id).status, { timeout: 900000 })
      .toBe("done");
    const finished = state(id);
    expect(finished.done).toBeGreaterThan(0);
    // Same contract as a loopback run: one profile per job, every measured page carrying its
    // capture. The noise-floor "baseline" job is answered by the mu-loader before WordPress
    // runs and never has a capture, on either transport.
    const stored = json(
      `global $wpdb;$t=SSPA_Schema::table('profiles');echo wp_json_encode(array('profiles'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE run_id=${id}"),'measured'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE run_id=${id} AND page_key<>'baseline'"),'with_capture'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE run_id=${id} AND page_key<>'baseline' AND profile_blob IS NOT NULL"),'home_capture'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE run_id=${id} AND page_key='home' AND profile_blob IS NOT NULL"),'ok'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM $t WHERE run_id=${id} AND response_code=200 AND blocked_by IS NULL")));`,
    );
    expect(stored.profiles).toBe(finished.total);
    expect(stored.with_capture).toBe(stored.measured);
    expect(stored.home_capture).toBe(1);
    expect(stored.ok).toBeGreaterThan(0);
    console.log("Stored evidence", JSON.stringify(stored));
    const report = json(`echo wp_json_encode(SSPA_Report::build(${id}));`);
    expect(report.schema).toBeDefined();
    expect(Array.isArray(report.findings)).toBe(true);

    const pluginsAfter = json(`echo wp_json_encode(get_option('active_plugins'));`);
    const helpersAfter = json(
      `echo wp_json_encode(array('mu' => md5_file(SSPA_Helper_Files::mu_path()), 'dropin' => SSPA_Helper_Files::dropin_status()));`,
    );
    expect(pluginsAfter).toEqual(pluginsBefore);
    expect(helpersAfter).toEqual(helpersBefore);
    expect(helpersAfter.dropin).toBe("ours");

    wp("option", "update", "sspa_fleet_loopback_blocked", "0");
    wp("cache", "flush");
    const restored = json(
      `echo wp_json_encode(SSPA_Run_Controller::loopback_preflight());`,
    );
    expect(restored.healthy).toBe(true);
    await page.screenshot({
      path: output + "/loopback-fallback-complete.png",
      fullPage: true,
    });
    expect(errors).toEqual([]);
    console.log(
      "PASS real loopback failure falls back to browser transport with complete stored evidence and untouched plugin set",
    );
  } finally {
    wp("option", "update", "sspa_fleet_loopback_blocked", "0");
    await page.screenshot({
      path: output + "/loopback-fallback-final.png",
      fullPage: true,
    });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
