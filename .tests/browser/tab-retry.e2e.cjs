const {session, admin, expect, output} = require('./fleet-context.cjs');
(async () => {
  const {browser, page} = await session();
  try {
    await admin(page);
    let attempts = 0;
    await page.route('**/admin-ajax.php', async route => {
      const data = new URLSearchParams(route.request().postData() || '');
      if (data.get('action') !== 'sspa_render_tab' || data.get('tabs') !== 'tools') return route.continue();
      attempts++;
      if (attempts === 1) return route.abort('failed');
      if (attempts === 2) return route.fulfill({contentType:'application/json', body:JSON.stringify({success:false, data:'Fixture: tab access denied.'})});
      return route.continue();
    });
    await page.locator('#sspa_main .nav-tab[data-tab="tools"]').click();
    const panel = page.locator('#sspa_main .tab-contents[data-tab="tools"]');
    await expect(panel.getByRole('alert')).toContainText('Network request failed.');
    await expect(panel.locator('.sspa-tab-loading')).toHaveCount(0);
    expect(attempts).toBe(1);
    await panel.getByRole('button', {name:'Retry', exact:true}).click();
    await expect(panel.getByRole('alert')).toContainText('Fixture: tab access denied.');
    expect(attempts).toBe(2);
    await panel.getByRole('button', {name:'Retry', exact:true}).click();
    await expect(panel).toHaveAttribute('data-sspa-tab-loaded', '1');
    await expect(panel.locator('.sspa-tools')).toBeVisible();
    await expect(panel.getByRole('alert')).toHaveCount(0);
    expect(attempts).toBe(3);
    await page.screenshot({path:output + '/tab-retry.png'});
    console.log('PASS failed tab reports network and server errors; explicit retry loads the real Tools tab');
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
