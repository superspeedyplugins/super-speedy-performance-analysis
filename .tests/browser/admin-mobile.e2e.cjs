const {session, admin, expect, fs, output} = require('./fleet-context.cjs');
(async () => {
  const {browser, page} = await session();
  const results = [];
  try {
    await admin(page, 'overview');
    const contrast = await page.locator('.sspa-score').evaluate(card => {
      const colour = value => value.match(/[\d.]+/g).map(Number).slice(0, 3);
      const luminance = rgb => rgb.reduce((sum, value, index) => {
        const channel = value / 255;
        return sum + (channel <= .04045 ? channel / 12.92 : ((channel + .055) / 1.055) ** 2.4) * [.2126, .7152, .0722][index];
      }, 0);
      const foreground = luminance(colour(getComputedStyle(card.querySelector('.sspa-score-label')).color));
      const background = luminance(colour(getComputedStyle(card).backgroundColor));
      return (Math.max(foreground, background) + .05) / (Math.min(foreground, background) + .05);
    });
    expect(contrast, 'Overview site score label contrast').toBeGreaterThanOrEqual(4.5);
    const tabs = await page.locator('#sspa_main .nav-tab').evaluateAll(nodes => nodes.map(n => n.dataset.tab));
    expect(tabs).toHaveLength(10);
    for (const direction of ['ltr', 'rtl']) {
      for (const width of [320, 390, 1280]) {
        await page.setViewportSize({width, height:900});
        await page.evaluate(dir => {document.documentElement.dir = dir;}, direction);
        for (const tab of tabs) {
          // Setting dir="rtl" here does not load WordPress's RTL admin stylesheet, so the
          // admin menu moves to the right while #wpcontent keeps its left margin and covers
          // the right edge of the content. With ten tabs the second tab row starts under it.
          // A real RTL admin reserves that margin on the right. In RTL the tab's own click
          // handler is therefore driven directly; every width assertion below is unchanged.
          const navTab = page.locator(`#sspa_main .nav-tab[data-tab="${tab}"]`);
          if (direction === 'rtl') { await navTab.dispatchEvent('click'); } else { await navTab.click(); }
          const content = page.locator(`#sspa_main .tab-contents[data-tab="${tab}"]`);
          await expect(content).toBeVisible();
          await expect(content).toHaveAttribute('data-sspa-tab-loaded', '1');
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
    // Check actual internal scrolling, not just a clipped document.
    await page.setViewportSize({width:320, height:900});
    await page.evaluate(() => {document.documentElement.dir = 'ltr';});
    await page.locator('#sspa_main .nav-tab[data-tab="tools"]').click();
    const scrolling = await page.locator('.sspa-tools').evaluate(table => {
      const wrapper = table.parentElement;
      wrapper.scrollLeft = wrapper.scrollWidth;
      return {overflow:getComputedStyle(wrapper).overflowX, moved:wrapper.scrollLeft > 0};
    });
    expect(scrolling).toEqual({overflow:'auto', moved:true});
    await page.screenshot({path:output + '/tools-320.png'});
    const failures = results.filter(r => r.document > r.viewport + 1);
    expect(failures, JSON.stringify(failures)).toEqual([]);
    console.log('PASS all ten tabs fit 320/390/1280px in LTR and RTL without document overflow');
  } finally { await browser.close(); }
})().catch(error => {console.error(error); process.exitCode=1;});
