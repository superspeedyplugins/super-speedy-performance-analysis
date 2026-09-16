const {session, expect, output} = require('./fleet-context.cjs');
(async () => {
  const {browser, page} = await session();
  try {
    await page.goto(process.env.SSPA_TEST_SITE_URL + '/wp-admin/admin.php?page=superspeedy');
    const link = page.getByRole('link', {name:'Plugin Impact Analysis', exact:true});
    await expect(link).toBeVisible();
    const bookmark = await link.getAttribute('href');
    await link.click();
    const selected = page.locator('#sspa_main .nav-tab-active');
    await expect(selected).toHaveAttribute('data-tab', 'plugins');
    await expect(page.locator('#sspa_main .tab-contents[data-tab="plugins"]')).toBeVisible();
    await page.reload();
    await expect(selected).toHaveAttribute('data-tab', 'plugins');
    await page.goto(bookmark);
    await expect(selected).toHaveAttribute('data-tab', 'plugins');
    await page.locator('#sspa_main .nav-tab[data-tab="history"]').click();
    await page.reload();
    await expect(selected).toHaveAttribute('data-tab', 'history');
    await page.screenshot({path:output + '/shared-link.png'});
    console.log('PASS real shared Plugin Impact link, reload and bookmark select Plugins; explicit fragment takes precedence');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
