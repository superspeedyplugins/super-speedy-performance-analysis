const path = require('node:path');
const {session, admin, expect, wp, output} = require('./fleet-context.cjs');
(async () => {
  const fixture = path.resolve(__dirname, '../fixtures/translation-runtime.php');
  wp('eval', `wp_mkdir_p(WPMU_PLUGIN_DIR); if (!copy(${JSON.stringify(fixture)}, WPMU_PLUGIN_DIR . '/sspa-translation-test.php')) throw new RuntimeException('Could not install translation fixture');`);
  const {browser, context, page} = await session();
  try {
    await context.addCookies([{name:'sspa_translation_test', value:'1', url:process.env.SSPA_TEST_SITE_URL}]);
    await admin(page, 'ajax');
    const ajax = page.locator('#sspa-ajax-profile');
    await expect(ajax.locator('label').filter({has:page.locator('input[name="label"]')})).toContainText('Nom de la fenêtre');
    await expect(ajax.getByRole('button', {name:'Comparer', exact:true})).toBeVisible();
    await page.locator('#sspa_main .nav-tab[data-tab="compare"]').click();
    await expect(page.locator('#sspa-history-compare')).toBeVisible();
    await expect(page.locator('.sspa-history-chart canvas')).toBeVisible();
    await expect.poll(() => page.locator('.sspa-history-chart').evaluate(node => node.sspaChart.getOption().legend[0].data)).toEqual(['Mesures précédentes', 'Mesures récentes']);
    await expect(page.locator('.sspa-history-chart-status')).toHaveText(/Graphique .+ chargé\./);
    const translatedChart = await page.evaluate(() => {
      const documentData = JSON.parse(document.querySelector('.sspa-history-chart-document').getAttribute('data-document'));
      const option = SSPAMeasurementChart.optionFor(documentData, '');
      return {legends: option.series.map(series => series.name), description: option.aria.description};
    });
    expect(translatedChart.legends).toEqual(['Mesures précédentes', 'Mesures actuelles', 'Médiane précédente', 'Médiane actuelle', 'Requêtes échouées']);
    expect(translatedChart.description).toMatch(/^Comparaison des mesures .+ précédentes et actuelles\.$/);
    const tooltipSafety = await page.evaluate(() => {
      const domain = 'super-speedy-performance-analysis';
      wp.i18n.setLocaleData({'Not measured': ['<img src=x onerror=alert(1)>']}, domain);
      const documentData = JSON.parse(document.querySelector('.sspa-history-chart-document').getAttribute('data-document'));
      const option = SSPAMeasurementChart.optionFor(documentData, '');
      const node = document.createElement('div');
      node.innerHTML = option.tooltip.formatter({seriesName: option.series[0].name, data: {value: ['home', null]}});
      return {text: node.textContent, markup: node.querySelectorAll('img,script').length};
    });
    expect(tooltipSafety.text).toContain('<img src=x onerror=alert(1)>');
    expect(tooltipSafety.markup).toBe(0);
    let pending;
    await page.route('**/admin-ajax.php', async route => {
      const data = new URLSearchParams(route.request().postData() || '');
      if (data.get('action') === 'sspa_history_compare') {pending = route; return;}
      return route.continue();
    });
    await page.locator('#sspa-history-compare').click();
    await expect(page.locator('#sspa-history-comparison')).toHaveText('Chargement de la comparaison…');
    await expect.poll(() => !!pending).toBe(true);
    await pending.abort('failed');
    await expect(page.locator('#sspa-history-comparison')).toHaveText('Comparaison impossible. Réessayez.');
    await page.screenshot({path:output + '/translated-comparison.png'});
    console.log('PASS PHP AJAX controls and JavaScript comparison loading/error messages render translated text');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
