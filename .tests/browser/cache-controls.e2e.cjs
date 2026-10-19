const { wp, json, output, session, login, expect } = require('./fleet-context.cjs');
(async () => {
  const { browser, page } = await session();
  const site = process.env.SSPA_TEST_SITE_URL;
  const fs = require('node:fs');
  const path = require('node:path');
  const fixture = path.join(process.env.SSPA_TEST_SITE_DIR, 'wp-content/mu-plugins/sspa-cache-controls.php');
  fs.symlinkSync(path.resolve(__dirname, '../fixtures/cache-controls.php'), fixture);
  try {
    for (const mode of ['', 'success', 'refuse', 'throw']) {
      wp('option', 'update', 'sspa_cache_control_fixture', mode);
      wp('eval', "wp_cache_set('sspa_all_probe', 'present', 'sspa_journey', 3600);");
      await page.goto(site + '/wp-admin/index.php');
      await expect(page.locator('#wp-admin-bar-sspa-clear-ours')).toHaveCount(0);
      await expect(page.locator('#wp-admin-bar-sspa-flush-object-cache')).toHaveCount(1);
      await expect(page.locator('#wp-admin-bar-sspa-opcache')).toHaveCount(1);
      if (mode) {
        for (const id of ['purge-url', 'purge-all', 'bypass']) await expect(page.locator('#wp-admin-bar-sspa-' + id)).toHaveCount(1);
      }
      await page.locator('#wp-admin-bar-sspa-menu > a').hover();
      const control = page.locator('#wp-admin-bar-sspa-clear-all-caches > a');
      await expect(control).toHaveText('Clear all caches');
      await Promise.all([
        page.waitForResponse(r => r.url().includes('admin-post.php') && r.url().includes('do=clear_all_caches') && r.status() === 302),
        control.click(),
      ]);
      const notice = page.locator('.notice').filter({ hasText: 'Flushed the object cache.' });
      await expect(notice).toBeVisible();
      await expect(notice).toContainText('Reset OPcache.');
      const expected = !mode ? 'No page cache was detected, so nothing was purged.' : mode === 'success' ? 'Requested a whole page-cache purge from W3 Total Cache.' : mode === 'refuse' ? 'W3 Total Cache page cache purge failed: the backend refused the purge' : 'Page cache failed: Synthetic page-cache purge failure';
      await expect(notice).toContainText(expected);
      expect(json("echo wp_json_encode(wp_cache_get('sspa_all_probe', 'sspa_journey'));" )).toBe(false);
      if (mode) expect(json("echo wp_json_encode(get_option('sspa_cache_control_observed'));" )).toBe(false);
      await page.screenshot({path: output + '/cache-controls-' + (mode || 'none') + '.png', fullPage:true});
      console.log('PASS real admin-bar click, ordered cache flush, page-cache ' + (mode || 'absent') + ', OPcache and rendered result notice');
    }
    // A real CLI SAPI refusal, with no replacement opcache function or fabricated result.
    const refused = wp('eval', "$m=new ReflectionMethod('SSPA_Admin_Bar','reset_opcache'); echo $m->invoke(null);");
    expect(refused).toContain('OPcache refused');
    console.log('PASS real CLI OPcache refusal is reported');
    wp('eval', 'wp_set_current_user(1);');
    // Nonce and capability failures must not flush a planted key.
    for (const suffix of ['&_wpnonce=invalid', '']) {
      wp('eval', "wp_cache_set('sspa_all_probe', 'protected', 'sspa_journey', 3600);");
      await page.goto(site + '/wp-admin/admin-post.php?action=sspa_bar&do=clear_all_caches' + suffix);
      expect(json("echo wp_json_encode(wp_cache_get('sspa_all_probe', 'sspa_journey'));" )).toBe('protected');
    }
    console.log('PASS missing and invalid nonces reject the combined action before flushing');
    wp('eval', "$id=username_exists('sspa-cache-subscriber'); if(!$id) $id=wp_create_user('sspa-cache-subscriber','Synthetic-cache-control-21!','cache-subscriber@example.invalid'); if(is_wp_error($id)) WP_CLI::error($id->get_error_message()); $u=new WP_User($id); $u->set_role('subscriber'); wp_set_password('Synthetic-cache-control-21!',$id);");
    const restrictedContext = await browser.newContext();
    try {
      const restricted = await login(restrictedContext, 'sspa-cache-subscriber', 'Synthetic-cache-control-21!');
      await expect(restricted.locator('#wp-admin-bar-sspa-clear-all-caches')).toHaveCount(0);
      await restricted.goto(site + '/wp-admin/admin-post.php?action=sspa_bar&do=clear_all_caches');
      await expect(restricted.locator('body')).toContainText('You do not have permission to do that.');
      expect(json("echo wp_json_encode(wp_cache_get('sspa_all_probe', 'sspa_journey'));" )).toBe('protected');
      console.log('PASS subscriber capability refusal preserves cache and exposes no combined control');
    } finally { await restrictedContext.close(); }

  } finally {
    wp('option', 'delete', 'sspa_cache_control_fixture');
    fs.unlinkSync(fixture);
    await browser.close();
  }
})().catch(error => { console.error(error); process.exit(1); });
