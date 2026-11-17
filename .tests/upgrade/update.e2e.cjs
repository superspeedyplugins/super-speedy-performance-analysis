// The Plugins-screen update an administrator does, in a real browser. Run by .tests/upgrade/run.sh.
// Logs in, asks the update checker to check (its own "Check for updates" row link), then presses
// the row's "update now" and waits for WordPress's own result text. Prints JSON lines only; the
// shell script judges them.
const { chromium } = require('@playwright/test');
const site = process.env.SSPA_E2E_URL;
const out = process.env.SSPA_FLEET_OUTPUT;
const label = process.env.UPGRADE_LABEL || 'run';
(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  const say = (o) => console.log(JSON.stringify(o));
  // Evidence only: a slow screenshot must never decide the result.
  const shot = (name) => page.screenshot({ path: out + '/' + label + '-' + name + '.png', timeout: 15000 }).catch(() => {});
  try {
    await page.goto(site + '/wp-login.php');
    await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
    await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
    await Promise.all([page.waitForURL((u) => !u.pathname.includes('wp-login.php')), page.locator('#wp-submit').click()]);
    await page.goto(site + '/wp-admin/plugins.php');
    const row = page.locator('tr[data-slug="super-speedy-performance-analysis"], tr[data-plugin^="super-speedy-performance-analysis/"]').first();
    const check = row.locator('a', { hasText: /Check for updates/i });
    say({ step: 'check-link', present: (await check.count()) > 0 });
    if (await check.count()) {
      await Promise.all([page.waitForLoadState('load'), check.first().click()]);
      await page.goto(site + '/wp-admin/plugins.php');
    }
    await shot('1-before');
    const updateRow = page.locator('tr.plugin-update-tr[data-plugin^="super-speedy-performance-analysis"]').first();
    const notice = (await updateRow.count()) ? (await updateRow.innerText()).replace(/\s+/g, ' ').trim() : '';
    say({ step: 'update-notice', text: notice });
    if (process.env.UPGRADE_VIA === 'update-core') {
      // Dashboard > Updates: tick the plugin, press Update Plugins; WordPress runs it in update.php.
      await page.goto(site + '/wp-admin/update-core.php');
      const box = page.locator('input[name="checked[]"][value^="super-speedy-performance-analysis"]');
      say({ step: 'updates-screen-listed', present: (await box.count()) > 0 });
      if (!(await box.count())) return;
      await box.first().evaluate((el) => { el.checked = true; });
      await Promise.all([page.waitForURL(/update-core\.php\?action=do-plugin-upgrade/, { timeout: 60000 }), page.locator('#upgrade-plugins').evaluate((b) => b.click())]);
      const frame = page.frameLocator('iframe[title="Update progress"]');
      await frame.locator('body').filter({ hasText: /All updates have been completed|could not be|failed|error/i }).waitFor({ timeout: 300000 });
      say({ step: 'update-result', text: (await frame.locator('body').innerText()).replace(/\s+/g, ' ').trim().slice(0, 900) });
    } else {
    const link = updateRow.locator('a.update-link');
    if (!(await link.count())) { say({ step: 'update-now', present: false }); return; }
    // Playwright's actionability wait never settles on this screen (screenshots stall the same way),
    // so click through the DOM; WordPress's own delegated handler runs the update either way.
    await link.evaluate((a) => a.click());
    const message = updateRow.locator('.update-message');
    await page.waitForFunction((el) => /Updated!|Update failed|failed|latest version/i.test(el.textContent), await message.elementHandle(), { timeout: 180000 });
    say({ step: 'update-result', text: (await message.innerText()).replace(/\s+/g, ' ').trim() });
    }
    await shot('2-after-update');
    await page.goto(site + '/wp-admin/plugins.php');
    await shot('3-plugins-reloaded');
    const rows = await page.locator('#the-list tr[data-plugin*="performance-analysis"]').evaluateAll((trs) => trs.map((tr) => ({ plugin: tr.dataset.plugin, active: tr.classList.contains('active'), name: (tr.querySelector('.plugin-title strong') || {}).textContent })));
    say({ step: 'plugins-screen', rows });
    const pa = await page.goto(site + '/wp-admin/admin.php?page=sspa');
    say({ step: 'pa-screen', status: pa.status(), title: await page.title() });
  } catch (e) {
    say({ step: 'error', message: String(e && e.message || e).split('\n')[0] });
    await shot('error');
    process.exitCode = 1;
  } finally {
    await Promise.race([browser.close(), new Promise((r) => setTimeout(r, 30000))]);
  }
})();
