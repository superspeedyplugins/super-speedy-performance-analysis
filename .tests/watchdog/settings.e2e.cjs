// The update watchdog's screens in a real browser, after .tests/watchdog/run.sh on the same site.
// Proves: the alert's Compare link opens Compare with exactly that pair selected; the Compare run
// table marks the watchdog's runs with the check's verdict; the alert's Markdown link downloads
// the run's report; the settings in Advanced Compare settings save and survive a reload, and a
// bad address is refused with its reason and changes nothing.
const {chromium, expect} = require('@playwright/test');
const {execFileSync} = require('node:child_process');
const site = process.env.SSPA_E2E_URL;
const wpPath = process.env.SSPA_TEST_SITE_DIR;
if (!site || !wpPath) throw Error('SSPA_E2E_URL and SSPA_TEST_SITE_DIR are required');
const wp = code => execFileSync(process.env.SSPA_TEST_REAL_WP || 'wp', ['eval', code, '--path=' + wpPath, '--url=' + site], {encoding: 'utf8'}).trim().split('\n').pop();
const ok = label => console.log('PASS: ' + label);

async function closeBrowser(browser) {
  await Promise.race([browser.close(), new Promise(resolve => setTimeout(resolve, 30000))]);
  const proc = browser.process && browser.process();
  if (proc && proc.exitCode === null) proc.kill('SIGKILL');
}

(async () => {
  // The slow-update check from run.sh: its alert is the newest regressed verdict.
  const entry = JSON.parse(wp('foreach (get_option("sspa_watchdog_log", array()) as $e) { if ("regressed" === $e["verdict"]) { echo wp_json_encode($e); break; } }') || 'null');
  if (!entry) throw Error('no regressed watchdog check on this site: run .tests/watchdog/run.sh first');
  const before = entry.baseline_run_id;
  const after = entry.runs.pages;
  const browser = await chromium.launch();
  try {
    const context = await browser.newContext({viewport: {width: 1280, height: 900}, acceptDownloads: true});
    const page = await context.newPage();
    await page.goto(site + '/wp-login.php');
    await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
    await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
    await Promise.all([page.waitForURL(url => !url.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);

    // The Compare link from the email.
    await page.goto(site + '/wp-admin/admin.php?page=sspa&sspa_compare_before=' + before + '&sspa_compare_after=' + after + '#compare');
    const panel = page.locator('#sspa_main .tab-contents[data-tab="compare"]');
    await expect(panel).toHaveAttribute('data-sspa-tab-loaded', '1', {timeout: 30000});
    await expect(page.locator('#sspa-history-before')).toHaveValue(String(before));
    await expect(page.locator('#sspa-history-after')).toHaveValue(String(after));
    ok('the alert\'s Compare link opens Compare with #' + before + ' before and #' + after + ' after');
    await expect(page.locator('#sspa-history-comparison')).toContainText(/shop/i);
    ok('and the comparison of that pair is shown');

    // History marks the watchdog's runs.
    const row = panel.locator('tr', {has: page.locator('a.sspa-history-run-link[data-run-id="' + after + '"]')});
    await expect(row.locator('.sspa-watchdog-badge')).toContainText('Update watchdog: 2 regressions');
    ok('the run table marks the watchdog run with its verdict');
    if (entry.runs.confirm) {
      const confirmRow = panel.locator('tr', {has: page.locator('a.sspa-history-run-link[data-run-id="' + entry.runs.confirm + '"]')});
      await expect(confirmRow.locator('.sspa-watchdog-badge')).toContainText('Update watchdog re-measurement');
      ok('and the re-measurement as a re-measurement');
    }

    // The Markdown link from the email.
    // Fetched by the logged-in browser (the test machine's Node cannot resolve *.localhost).
    const download = await page.evaluate(async url => {
      const r = await fetch(url, {credentials: 'same-origin'});
      return {status: r.status, disposition: r.headers.get('content-disposition') || '', text: await r.text()};
    }, site + '/wp-admin/admin-post.php?action=sspa_watchdog_markdown&kind=run&id=' + after);
    expect(download.status).toBe(200);
    expect(download.disposition).toContain('attachment');
    expect(download.text).toContain('shop');
    ok('the alert\'s Markdown link downloads the run\'s report');

    // Settings: open Advanced Compare settings, change, save, reload.
    await page.locator('#sspa-advanced-settings > summary').click();
    const box = page.locator('#sspa-watchdog-settings');
    await expect(box).toBeVisible();
    await box.locator('[name="threshold_ms"]').fill('200');
    await box.locator('[name="weekly_day"]').selectOption('friday');
    await box.locator('[name="recipients"]').fill('not-an-address');
    await box.locator('.sspa-watchdog-save').click();
    await expect(box.locator('.sspa-watchdog-feedback')).toContainText('is not an email address');
    expect(wp('echo SSPA_Watchdog::setting("threshold_ms");')).toBe('150');
    ok('a bad address is refused with its reason and nothing is saved');
    await box.locator('[name="recipients"]').fill('');
    await box.locator('.sspa-watchdog-save').click();
    await expect(box.locator('.sspa-watchdog-feedback')).toContainText('Watchdog settings saved.');
    await page.reload();
    await expect(panel).toHaveAttribute('data-sspa-tab-loaded', '1', {timeout: 30000});
    await page.locator('#sspa-advanced-settings > summary').click();
    await expect(box.locator('[name="threshold_ms"]')).toHaveValue('200');
    await expect(box.locator('[name="weekly_day"]')).toHaveValue('friday');
    const weekly = wp('$n = wp_next_scheduled("sspa_watchdog_weekly"); echo $n ? strtolower(wp_date("l", $n)) : "none";');
    expect(weekly).toBe('friday');
    ok('the settings save, survive a reload, and the weekly check moves to Friday');
    await expect(box.locator('.sspa-watchdog-last')).toContainText('Last check:');
    ok('the settings show the last check');

    // Back to the defaults run.sh expects.
    await box.locator('[name="threshold_ms"]').fill('150');
    await box.locator('[name="weekly_day"]').selectOption('monday');
    await box.locator('.sspa-watchdog-save').click();
    await expect(box.locator('.sspa-watchdog-feedback')).toContainText('Watchdog settings saved.');
    console.log('PASS: settings journey');
  } finally {
    await closeBrowser(browser);
  }
})().catch(error => {
  console.log('FAIL: ' + (error && error.message ? error.message.split('\n')[0] : error));
  process.exitCode = 1;
});
