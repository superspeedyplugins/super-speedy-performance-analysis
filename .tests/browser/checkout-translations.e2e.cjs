const {session, admin, expect, wp, json} = require('./fleet-context.cjs');
(async () => {
  wp('eval', "wp_update_post(array('ID'=>(int)get_option('woocommerce_checkout_page_id'),'post_content'=>'<!-- wp:woocommerce/checkout /-->'));wp_cache_flush();");
  const orderCount = () => json("echo wp_json_encode(count(wc_get_orders(array('limit'=>-1,'return'=>'ids'))));");
  const before = orderCount();
  const {browser, page} = await session();
  try {
    await admin(page, 'workflows');
    await page.evaluate(() => {
      wp.i18n.setLocaleData({
        '': {'plural-forms': 'nplurals=2; plural=(n > 1);'},
        'Before this runs, here is exactly what it will do': ['Avant de commencer'],
        'Buy something and measure it': ['Acheter et mesurer'],
        'Cancel': ['Annuler'],
        'Close': ['Fermer'],
        'Let third-party integrations run': ['Autoriser les intégrations'],
        'Let webhooks fire': ['Autoriser les webhooks'],
        'Powered by %1$s · free from %2$s': ['Par %1$s · gratuit sur %2$s'],
        'Can send up to %d order email, depending on the status the order reaches': ['Jusqu’à %d message selon le statut', 'Jusqu’à %d messages selon le statut']
      }, 'super-speedy-performance-analysis');
    });
    await page.locator('.sspa-ck-open').first().click();
    const pop = page.locator('#sspa-adhoc-pop');
    await expect(pop).toBeVisible();
    // A retained real result opens first; ask the real preflight handler for a new disclosure.
    if (await pop.locator('.sspa-ck-preflight').count()) await pop.locator('.sspa-ck-preflight').click();
    await expect(pop.locator('.sspa-ck-h').first()).toHaveText('Avant de commencer');
    await expect(pop.locator('.sspa-ck-go')).toHaveText('Acheter et mesurer');
    await expect(pop.locator('.sspa-ck-cancel')).toHaveText('Annuler');
    await expect(pop.locator('.sspa-adhoc-close')).toHaveAttribute('aria-label', 'Fermer');
    await expect(pop.locator('label').filter({has:page.locator('.sspa-ck-integrations')})).toContainText('Autoriser les intégrations');
    await expect(pop.locator('label').filter({has:page.locator('.sspa-ck-webhooks')})).toContainText('Autoriser les webhooks');
    await expect(pop.locator('.sspa-adhoc-foot')).toContainText('Par Super Speedy Performance Analysis · gratuit sur superspeedyplugins.com');
    await expect(pop.locator('.sspa-ck-list')).toContainText(/Jusqu’à \d+ messages? selon le statut/);
    await expect(pop.locator('a[href="https://www.superspeedyplugins.com/"]')).toHaveCount(1);
    await pop.locator('.sspa-ck-cancel').click();
    await expect(pop).toBeHidden();
    expect(orderCount()).toBe(before);
    console.log('PASS actual checkout disclosure translates controls, complete plural messages and footer without altering DOM structure');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
