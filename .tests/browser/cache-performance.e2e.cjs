// Cache performance tab, driven as an administrator would: open the tab, press "Check cache
// performance", wait for the result and read it at desktop and phone widths.
//
// Proves: every request the check sends from the browser stays on this site's origin; the
// browser sends exactly the declared number of page requests and server-leg calls; the
// result renders three audience rows and a per-page table; the progress text comes from
// wp.i18n (a locale entry set in the page replaces it); nothing overflows at 390px.
//
// SSPA_CACHE_EXPECT (optional JSON) names the expected audience headlines, for example
// {"anonymous":"cached"}; the nested-site runners set it from what the real cache does.
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
    await expect(page.locator('#sspa_main .nav-tab[data-tab="cache"]')).toHaveText('Cache performance');
    const tabOrder = await page.locator('#sspa_main .nav-tab').evaluateAll(nodes => nodes.map(n => n.dataset.tab));
    expect(tabOrder.slice(0, 2), 'Cache performance sits directly after Overview').toEqual(['overview', 'cache']);
    const button = panel.locator('.sspa-cache-delivery-start');
    await expect(button).toBeVisible();
    const plan = await panel.locator('.sspa-cache-request-plan').innerText();
    const declared = Number((plan.match(/(\d+) sequential GET requests/) || [])[1]);
    const targets = await panel.locator('.sspa-cache-delivery-targets li').count();
    expect(declared, 'the request count shown before the button').toBe(targets * 9);
    await page.screenshot({path: output + '/cache-before-1280.png', fullPage: true});

    // A locale entry for one progress message. If the script used a hard-coded English
    // string, the replacement would never appear.
    await page.evaluate(() => {
      window.sspaCacheStatusTexts = [];
      wp.i18n.setLocaleData({'Requesting pages as the logged-in test customer': ['XX customer leg XX']}, 'super-speedy-performance-analysis');
      const status = document.querySelector('#sspa_main .tab-contents[data-tab="cache"] .sspa-cache-delivery-status');
      new MutationObserver(() => window.sspaCacheStatusTexts.push(status.textContent)).observe(status, {childList: true, characterData: true, subtree: true});
    });

    const started = requests.length;
    // A retained site may already show an earlier result, so wait for THIS check to finish
    // and for the tab to be re-rendered from it.
    const completed = page.waitForResponse(r => r.url().includes('admin-ajax.php') && (r.request().postData() || '').includes('action=sspa_cache_delivery_complete'), {timeout: 300000});
    const rerendered = page.waitForResponse(r => r.url().includes('admin-ajax.php') && /action=sspa_render_tab.*tabs=cache/.test(decodeURIComponent(r.request().postData() || '')), {timeout: 300000});
    await button.click();
    const completion = await (await completed).json();
    expect(completion.success, 'the check completed: ' + JSON.stringify(completion.data)).toBe(true);
    await rerendered;
    await expect(panel.locator('.sspa-cache-delivery-start')).toBeEnabled({timeout: 30000});
    await expect(panel.locator('.sspa-cache-audiences')).toBeVisible({timeout: 30000});
    const sent = requests.slice(started);
    const offOrigin = sent.filter(r => new URL(r.url).origin !== origin);
    expect(offOrigin, 'no request leaves this site').toEqual([]);
    const pageFetches = sent.filter(r => r.type === 'fetch' && r.method === 'GET' && !r.url.includes('admin-ajax.php'));
    const probes = sent.filter(r => r.url.includes('admin-ajax.php') && r.post.includes('action=sspa_cache_delivery_server_probe'));
    expect(pageFetches.length, 'two browser requests per page').toBe(targets * 2);
    expect(probes.length, 'seven server requests per page').toBe(targets * 7);
    expect(pageFetches.length + probes.length).toBe(declared);
    const statusTexts = await page.evaluate(() => window.sspaCacheStatusTexts);
    expect(statusTexts.some(text => text.includes('XX customer leg XX')), 'progress text comes from wp.i18n').toBe(true);

    const audiences = await panel.locator('.sspa-cache-audience').evaluateAll(nodes => nodes.map(n => ({audience: n.dataset.audience, word: n.querySelector('.sspa-cache-audience-word').textContent.trim(), state: [...n.classList].find(c => c.startsWith('sspa-cache-state-')).replace('sspa-cache-state-', '')})));
    expect(audiences.map(a => a.audience)).toEqual(['anonymous', 'basket', 'customer']);
    await expect(panel.locator('.sspa-cache-results tbody tr')).toHaveCount(targets);
    const expected = process.env.SSPA_CACHE_EXPECT ? JSON.parse(process.env.SSPA_CACHE_EXPECT) : {};
    for (const [audience, state] of Object.entries(expected)) {
      expect(audiences.find(a => a.audience === audience).state, audience + ' headline').toBe(state);
    }
    // Overview is refreshed with the same result.
    const overview = await page.locator('#sspa_main .tab-contents[data-tab="overview"] .sspa-cache-overview').innerText();
    expect(overview).toContain('Visitors with a basket:');

    const offerLinks = await panel.locator('.sspa-cache-offer-link').evaluateAll(nodes => nodes.map(n => n.href));
    expect(offerLinks.length).toBeGreaterThan(0);
    for (const link of offerLinks) {
      const url = new URL(link);
      expect(url.hostname).toBe('www.superspeedyplugins.com');
      expect([...url.searchParams.keys()].sort()).toEqual(['utm_campaign', 'utm_medium', 'utm_source']);
      expect(url.searchParams.get('utm_source')).toBe('performance-analysis');
      expect(url.searchParams.get('utm_medium')).toBe('plugin');
      expect(link).not.toContain(new URL(site).hostname);
    }

    await page.screenshot({path: output + '/cache-result-1280.png', fullPage: true});
    await page.setViewportSize({width: 390, height: 900});
    const overflow = await panel.evaluate(root => ({
      viewport: innerWidth,
      document: document.documentElement.scrollWidth,
      offenders: [...root.querySelectorAll('*')].filter(n => n.getClientRects().length && n.getBoundingClientRect().right > innerWidth + 1).slice(0, 8).map(n => ({tag: n.tagName, class: String(n.className), width: n.getBoundingClientRect().width}))
    }));
    expect(overflow.document, JSON.stringify(overflow)).toBeLessThanOrEqual(overflow.viewport + 1);
    await page.screenshot({path: output + '/cache-result-390.png', fullPage: true});

    const report = json('echo wp_json_encode(SSPA_Cache_Delivery::latest());');
    fs.writeFileSync(output + '/cache-report.json', JSON.stringify(report, null, 2));
    fs.writeFileSync(output + '/cache-requests.json', JSON.stringify(sent.map(r => ({url: r.url, method: r.method, type: r.type, action: (r.post.match(/action=([a-z_]+)/) || [])[1] || ''})), null, 2));
    console.log('PASS cache check: ' + audiences.map(a => a.audience + '=' + a.state).join(', ') + '; ' + declared + ' same-origin requests; wp.i18n progress text; 390px fits');
  } finally {
    await browser.close();
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
