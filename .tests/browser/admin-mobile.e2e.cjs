const {session, admin, expect, fs, output} = require('./fleet-context.cjs');
(async () => {
  const {browser, page} = await session();
  const results = [];
  try {
    await admin(page, 'overview');
    const tabs = await page.locator('#sspa_main .nav-tab').evaluateAll(nodes => nodes.map(n => n.dataset.tab));
    expect(tabs).toHaveLength(9);
    for (const direction of ['ltr', 'rtl']) {
      for (const width of [320, 390, 1280]) {
        await page.setViewportSize({width, height:900});
        await page.evaluate(dir => {document.documentElement.dir = dir;}, direction);
        for (const tab of tabs) {
          await page.locator(`#sspa_main .nav-tab[data-tab="${tab}"]`).click();
          const content = page.locator(`#sspa_main .tab-contents[data-tab="${tab}"]`);
          await expect(content).toBeVisible();
          await expect(content.locator('.sspa-tab-loading')).toHaveCount(0);
          const sizes = await content.evaluate(root => ({
            viewport: innerWidth,
            document: document.documentElement.scrollWidth,
            offenders:[...root.querySelectorAll('*')].filter(n => n.getClientRects().length && n.getBoundingClientRect().right > innerWidth + 1).slice(0,8).map(n => ({tag:n.tagName, class:n.className, width:n.getBoundingClientRect().width}))
          }));
          results.push({direction, width, tab, ...sizes});
          console.log(JSON.stringify(results[results.length-1]));
        }
      }
    }
    fs.writeFileSync(output + '/admin-widths.json', JSON.stringify(results,null,2));
    const failures = results.filter(r => r.document > r.viewport + 1);
    expect(failures, JSON.stringify(failures)).toEqual([]);
    console.log('PASS all nine tabs fit 320/390/1280px in LTR and RTL without document overflow');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
