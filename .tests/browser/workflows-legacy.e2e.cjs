const { session, admin, response, expect, json, output } = require('./fleet-context.cjs');
(async () => {
  const { browser, page } = await session();
  try {
    expect(json('echo wp_json_encode(\\Automattic\\WooCommerce\\Utilities\\OrderUtil::custom_orders_table_usage_is_enabled());')).toBe(false);
    await admin(page, 'workflows');
    await expect(page.locator('#sspa-workflow-object-type')).toBeVisible();
    await expect(page.locator('#sspa-workflow-object-id')).toHaveCount(0);
    await expect(page.locator('#sspa-workflow-suppress-mail')).toHaveCount(0);
    const statuses = [];
    await page.route('**/admin-ajax.php', async route => {
      const data = new URLSearchParams(route.request().postData() || '');
      if (data.get('action') === 'sspa_admin_save_finish') {
        const entry = json(`echo wp_json_encode(get_option(SSPA_Workflow_Analysis::TEMP_OPTION));`);
        if (entry) statuses.push(entry.type === 'order' ? json(`echo wp_json_encode(wc_get_order(${Number(entry.id)})->get_status());`) : json(`echo wp_json_encode(get_post_status(${Number(entry.id)}));`));
      }
      await route.continue();
    });
    for (const type of ['page', 'product', 'order']) {
      await page.locator('#sspa-workflow-object-type').selectOption(type);
      await expect(page.locator('#sspa-workflow-run')).toBeEnabled();
      if (type === 'product') await page.locator('#sspa-workflow-transport').selectOption('classic');
      if (type === 'order') await page.locator('#sspa-workflow-transport').selectOption('classic');
      const launch = response(page, 'sspa_workflow_launch');
      await page.locator('#sspa-workflow-run').click();
      const result = await (await launch).json();
      expect(result.success).toBe(true);
      expect(result.data.object_id).toBeGreaterThan(0);
      await expect(page.locator('#sspa-adhoc-pop')).toBeVisible({timeout:180000});
      await expect(page.locator('#sspa-workflow-status')).toContainText('Save profiled', {timeout:180000});
      const id = Number(result.data.object_id);
      expect(statuses[statuses.length - 1]).toBe(type === 'order' ? 'pending' : 'private');
      expect(json(`echo wp_json_encode(${type === 'order' ? `wc_get_order(${id})` : `get_post(${id})`});`)).toBeFalsy();
      await page.screenshot({path: output + '/workflow-' + type + '.png', fullPage:true});
      console.log('PASS: actual Workflows tab creates, saves and removes its own ' + type + ' via the editor frame');
      await page.keyboard.press('Escape');
    }
  } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode=1; });
