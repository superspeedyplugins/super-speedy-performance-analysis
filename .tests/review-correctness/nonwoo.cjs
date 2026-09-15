const { session, admin, response, expect, json, site, output } = require('../browser/fleet-context.cjs');
(async () => {
  const { browser, page } = await session();
  try {
    expect(json('echo wp_json_encode(class_exists("WooCommerce"));')).toBe(false);
    await admin(page, 'traffic');
    await expect(page.locator('#sspa-traffic-start')).toBeEnabled();
    page.once('dialog', d => d.accept());
    await page.locator('#sspa-traffic-start').click();
    expect(json('echo wp_json_encode(SSPA_Traffic_Collection::active());')).toBeFalsy();
    await page.locator('#sspa-traffic-confirm').check();
    const started = response(page, 'sspa_traffic_start');
    await page.locator('#sspa-traffic-start').click();
    expect((await (await started).json()).success).toBe(true);
    const collection = json('echo wp_json_encode(SSPA_Traffic_Collection::active());');
    expect(Number(collection.id)).toBeGreaterThan(0);
    const visitor = await browser.newContext();
    const guest = await visitor.newPage();
    const count = () => json(`global $wpdb;echo wp_json_encode((int)$wpdb->get_var('SELECT COUNT(*) FROM '.SSPA_Schema::table('traffic_events').' WHERE collection_id=${Number(collection.id)}'));`);
    const before = count();
    await guest.goto(site + '/?review_request=observed');
    expect(count()).toBeGreaterThan(before);
    await page.screenshot({path: output + '/nonwoo-running.png', fullPage: true});
    console.log('PASS non-WooCommerce browser requires consent, starts real collection and records anonymous traffic');
  } finally { await browser.close(); }
})().catch(e => { console.error(e); process.exit(1); });
