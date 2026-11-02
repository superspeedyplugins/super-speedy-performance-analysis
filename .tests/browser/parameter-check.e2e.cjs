// The ad-click and campaign parameter check, driven as an administrator would: open the Cache
// performance tab, press "Check ad-click and campaign parameters", wait for it to finish and read
// the result at desktop and phone widths. Run by .tests/new-visitor-cache/run.sh journey (case 154)
// on tests-cache-blockers, whose fixtures make fbclid set _fbc (zz-click-cookie 2.3.4), and whose
// page cache ignores utm_*, bypasses gclid and keys every other parameter.
//
// Proves: the button is separate from "Check cache performance" and pressing it sends no cache
// check calls; the browser itself requests nothing off this site; progress text comes from
// wp.i18n; the result table has one row per parameter with fbclid first, naming _fbc and the
// plugin; the outcomes shown match the saved report; nothing overflows at 390px.
const {session, admin, expect, fs, output, site, json} = require('./fleet-context.cjs');

(async () => {
  const {browser, page} = await session();
  try {
    const origin = new URL(site).origin;
    const requests = [];
    page.on('request', request => {
      requests.push({url: request.url(), method: request.method(), type: request.resourceType(), post: request.postData() || ''});
    });

    await admin(page, 'cache');
    const panel = page.locator('#sspa_main .tab-contents[data-tab="cache"]');
    await expect(panel).toHaveAttribute('data-sspa-tab-loaded', '1', {timeout: 30000});
    const section = panel.locator('.sspa-parameter-check');
    const button = section.locator('.sspa-parameter-check-start');
    await expect(button).toBeVisible();
    await expect(button).toHaveText('Check ad-click and campaign parameters');
    const plan = await section.locator('.sspa-parameter-plan').innerText();
    expect(Number((plan.match(/up to (\d+) sequential GET requests/) || [])[1]), 'the request budget shown before the button').toBe(118);
    expect(plan).toContain('fbclid, gclid');
    await page.screenshot({path: output + '/parameters-before-1280.png', fullPage: true});

    await page.evaluate(() => {
      window.sspaParamTexts = [];
      wp.i18n.setLocaleData({'Testing how the cache keys each parameter': ['XX cache key XX']}, 'super-speedy-performance-analysis');
      const status = document.querySelector('#sspa_main .tab-contents[data-tab="cache"] .sspa-parameter-check-status');
      new MutationObserver(() => window.sspaParamTexts.push(status.textContent)).observe(status, {childList: true, characterData: true, subtree: true});
    });

    const started = requests.length;
    const rerendered = page.waitForResponse(r => r.url().includes('admin-ajax.php') && /action=sspa_render_tab.*tabs=cache/.test(decodeURIComponent(r.request().postData() || '')), {timeout: 600000});
    await button.click();
    await rerendered;
    const results = panel.locator('.sspa-parameter-results');
    await expect(results).toBeVisible({timeout: 30000});
    await expect(panel.locator('.sspa-parameter-check-start')).toBeEnabled({timeout: 30000});

    const sent = requests.slice(started);
    expect(sent.filter(r => new URL(r.url).origin !== origin), 'no request leaves this site').toEqual([]);
    expect(sent.filter(r => r.post.includes('action=sspa_cache_delivery_')), 'the parameter button starts no cache performance check').toEqual([]);
    const advances = sent.filter(r => r.post.includes('action=sspa_parameter_check_advance'));
    expect(advances.length, 'the browser advanced the check in bounded steps').toBeGreaterThan(1);
    const texts = await page.evaluate(() => window.sspaParamTexts);
    expect(texts.some(text => text.includes('XX cache key XX')), 'progress text comes from wp.i18n').toBe(true);

    const rows = await results.locator('tbody tr').evaluateAll(nodes => nodes.map(n => ({
      parameter: n.dataset.parameter, outcome: n.dataset.outcome, worst: n.classList.contains('sspa-parameter-worst'), text: n.innerText,
    })));
    expect(rows.length, 'one row per parameter').toBe(18);
    expect(rows[0].parameter, 'fbclid heads the list').toBe('fbclid');
    expect(rows[0].worst).toBe(true);
    expect(rows[0].text).toContain('_fbc');
    expect(rows[0].text).toContain('zz-click-cookie (test fixture) 2.3.4');
    expect(rows[0].text).toContain('Never served from cache');
    const report = json('echo wp_json_encode(SSPA_Parameter_Check::latest());');
    fs.writeFileSync(output + '/parameter-report.json', JSON.stringify(report, null, 2));
    for (const row of report.parameters) {
      expect(rows.find(r => r.parameter === row.parameter).outcome, row.parameter + ' shown as saved').toBe(row.cache.outcome);
    }
    expect(rows.find(r => r.parameter === 'utm_source').outcome).toBe('ignored');
    expect(rows.find(r => r.parameter === 'gclid').outcome).toBe('bypass');
    expect(rows.find(r => r.parameter === 'msclkid').outcome).toBe('separate_key');
    await expect(section.locator('.sspa-parameter-headline')).toContainText('fbclid makes zz-click-cookie (test fixture) 2.3.4 set _fbc');

    await page.screenshot({path: output + '/parameters-result-1280.png', fullPage: true});
    await page.setViewportSize({width: 390, height: 900});
    const overflow = await panel.evaluate(root => ({
      viewport: innerWidth,
      document: document.documentElement.scrollWidth,
      offenders: [...root.querySelectorAll('*')].filter(n => n.getClientRects().length && n.getBoundingClientRect().right > innerWidth + 1).slice(0, 8).map(n => ({tag: n.tagName, class: String(n.className), width: n.getBoundingClientRect().width}))
    }));
    expect(overflow.document, JSON.stringify(overflow)).toBeLessThanOrEqual(overflow.viewport + 1);
    await page.screenshot({path: output + '/parameters-result-390.png', fullPage: true});
    fs.writeFileSync(output + '/parameter-requests.json', JSON.stringify(sent.map(r => ({url: r.url, method: r.method, type: r.type, action: (r.post.match(/action=([a-z_]+)/) || [])[1] || ''})), null, 2));
    console.log('PASS parameter check: ' + rows.length + ' parameters, fbclid first (' + rows[0].outcome + '), ' + advances.length + ' advance calls, same-origin, wp.i18n progress text, 390px fits');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
