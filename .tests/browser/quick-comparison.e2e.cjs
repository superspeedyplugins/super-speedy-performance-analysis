// Issue 17. After a plugin update is detected, the administrator is offered a quick
// comparison. This follows that offer through the real admin screen: a real change record
// against the retained update fixture plugin, the notice naming the baseline analysis, the
// "Run quick comparison" button, exactly one bounded spot run started by the page itself,
// and the History comparison that names the fixture as the component that changed.
const {
  wp,
  json,
  output,
  state,
  session,
  admin,
  expect,
} = require("./fleet-context.cjs");
const FIXTURE = "sspa-history-update-fixture/sspa-history-update-fixture.php";
(async () => {
  const { browser, page } = await session();
  const errors = [];
  page.on("pageerror", (e) => errors.push(e.message));
  try {
    // Clear the previous run's pending change set and make sure the fixture plugin exists
    // at version 1.2.0 for the Before analysis. Real APIs throughout.
    const prepared = json(`
      sspa_update_option('plugin_update_detection', true);
      SSPA_Change_Set::dismiss();
      $dir = WP_PLUGIN_DIR . '/sspa-history-update-fixture';
      wp_mkdir_p($dir);
      file_put_contents($dir . '/sspa-history-update-fixture.php', "<?php\\n/**\\n * Plugin Name: SSPA History Update Fixture\\n * Version: 1.2.0\\n */\\n");
      wp_clean_plugins_cache(true);
      if (!is_plugin_active('${FIXTURE}')) { activate_plugin('${FIXTURE}'); }
      $active = SSPA_Run_Controller::active_run_id();
      if ($active) { SSPA_Run_Controller::cancel($active); }
      $before = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => SSPA_History_Series::quick_comparison_page_keys(), 'user_id' => 1));
      if (is_wp_error($before)) { throw new RuntimeException($before->get_error_message()); }
      $deadline = time() + 240;
      do { SSPA_Run_Controller::process_batch($before); $s = SSPA_Run_Controller::status($before); }
      while ($s && in_array($s['status'], array('crawling', 'analysing'), true) && time() < $deadline);
      echo wp_json_encode(array('before' => (int) $before, 'status' => $s['status'], 'pages' => SSPA_History_Series::quick_comparison_page_keys()));
    `);
    expect(prepared.status).toBe("done");
    console.log("Before analysis", prepared.before, "pages", prepared.pages.join(","));

    // The update happens: the fixture's version changes and the real change hook records it.
    const recorded = json(`
      $dir = WP_PLUGIN_DIR . '/sspa-history-update-fixture';
      file_put_contents($dir . '/sspa-history-update-fixture.php', "<?php\\n/**\\n * Plugin Name: SSPA History Update Fixture\\n * Version: 1.3.0\\n */\\n");
      wp_clean_plugins_cache(true);
      SSPA_Change_Set::record('${FIXTURE}', 'updated', '1.2.0', '1.3.0');
      $pending = SSPA_Change_Set::pending(true);
      echo wp_json_encode(array('id' => $pending ? $pending['id'] : null, 'changes' => $pending ? array_keys($pending['changes']) : array()));
    `);
    expect(recorded.changes).toEqual(["sspa-history-update-fixture"]);
    const pluginsBefore = json(`echo wp_json_encode(get_option('active_plugins'));`);
    const helpersBefore = json(
      `echo wp_json_encode(array('mu' => md5_file(SSPA_Helper_Files::mu_path()), 'dropin' => SSPA_Helper_Files::dropin_status()));`,
    );

    // The notice on the real admin screen offers the comparison and names the baseline.
    await admin(page);
    const notice = page.locator(".notice-info", { hasText: "plugin change was detected" });
    await expect(notice).toBeVisible();
    await expect(notice).toContainText("This will compare with Analysis #" + prepared.before);
    const run = notice.getByRole("link", { name: "Run quick comparison" });
    await expect(run).toBeVisible();
    const runsBefore = json(
      `global $wpdb;echo wp_json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM ".SSPA_Schema::table('runs')));`,
    );
    await run.click();
    await page.waitForURL(/sspa_autospot=1/);

    // The page starts exactly one bounded spot run itself and follows it to completion.
    let id = 0;
    await expect
      .poll(() => {
        id = json(`echo wp_json_encode((int) SSPA_Run_Controller::active_run_id());`);
        if (!id) {
          id = json(
            `global $wpdb;echo wp_json_encode((int)$wpdb->get_var("SELECT id FROM ".SSPA_Schema::table('runs')." WHERE trigger_source='plugin_change' ORDER BY id DESC LIMIT 1"));`,
          );
        }
        return id;
      }, { timeout: 60000 })
      .toBeGreaterThan(prepared.before);
    console.log("Quick comparison run", id);
    await expect.poll(() => state(id).status, { timeout: 600000 }).toBe("done");
    const runsAfter = json(
      `global $wpdb;echo wp_json_encode((int)$wpdb->get_var("SELECT COUNT(*) FROM ".SSPA_Schema::table('runs')));`,
    );
    expect(runsAfter - runsBefore).toBe(1);
    const row = json(
      `$r = SSPA_Run_Controller::run_row(${id}); $c = json_decode((string) $r['share_context'], true); echo wp_json_encode(array('type' => $r['run_type'], 'trigger' => $r['trigger_source'], 'baseline' => (int) ($c['history_comparison']['baseline_run_id'] ?? 0), 'changes' => array_map(function ($x) { return $x['slug'] . ':' . $x['from_version'] . '>' . $x['to_version']; }, (array) ($c['history_comparison']['change_set']['changes'] ?? array())), 'pending' => SSPA_Change_Set::pending(true) ? 'still pending' : 'consumed'));`,
    );
    expect(row.type).toBe("spot");
    expect(row.trigger).toBe("plugin_change");
    expect(row.baseline).toBe(prepared.before);
    expect(row.changes).toEqual(["sspa-history-update-fixture:1.2.0>1.3.0"]);
    expect(row.pending).toBe("consumed");

    // The History comparison opens and names the fixture as the component that changed.
    // History's default comparison is the newest run against the one before it, which is
    // exactly the pair the quick comparison just produced.
    await page.goto(
      json(`echo wp_json_encode(admin_url('admin.php?page=sspa'));`) + "#history",
    );
    await page.waitForFunction(() => {
      const active = document.querySelector("#sspa_main .nav-tab-active");
      return active && active.dataset.tab === "history";
    });
    const comparison = page.locator(
      `#sspa_main section.sspa-history-comparison[data-before-run="${prepared.before}"][data-after-run="${id}"]`,
    );
    await expect(comparison).toBeVisible({ timeout: 60000 });
    await expect(comparison).toContainText(
      `Comparing point in time #${prepared.before} with #${id}`,
    );
    const setup = comparison.locator(".sspa-history-setup-changes").first();
    await expect(setup).toBeVisible();
    await setup.locator("summary").click();
    await expect(setup).toContainText("sspa-history-update-fixture");
    await expect(setup).toContainText("1.2.0");
    await expect(setup).toContainText("1.3.0");
    await page.screenshot({ path: output + "/quick-comparison-history.png", fullPage: true });

    const pluginsAfter = json(`echo wp_json_encode(get_option('active_plugins'));`);
    const helpersAfter = json(
      `echo wp_json_encode(array('mu' => md5_file(SSPA_Helper_Files::mu_path()), 'dropin' => SSPA_Helper_Files::dropin_status()));`,
    );
    expect(pluginsAfter).toEqual(pluginsBefore);
    expect(helpersAfter).toEqual(helpersBefore);
    expect(errors).toEqual([]);
    console.log(
      "PASS detected update offers a quick comparison, one bounded spot run completes and History names the changed component",
    );
  } finally {
    await page.screenshot({ path: output + "/quick-comparison-final.png", fullPage: true });
    await browser.close();
  }
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
