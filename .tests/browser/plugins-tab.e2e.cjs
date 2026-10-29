// Plugins tab: the Measure button fits, a finished Plugin Impact Analysis leaves a summary on
// screen (and again on the next visit until it is seen), and the explorer controls sort and
// filter the table. Runs one real Plugin Impact Analysis of zz-slow-shop, which
// prepare-plugins-tab.php plants: 120 ms on shop and product-cat, nothing on home.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.SSPA_PLAYWRIGHT_MODULE || '../node_modules/playwright');

(async () => {
    const site = process.env.SSPA_E2E_URL;
    const out = process.env.SSPA_E2E_OUT;
    assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
    const browser = await chromium.launch({ headless: true });
    let failed = false;
    try {
        const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(site + '/wp-login.php');
        await page.waitForFunction(() => document.activeElement?.id === 'user_login');
        await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
        await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
        await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);

        const pluginsTab = '#sspa_main .tab-contents[data-tab="plugins"]';
        const table = pluginsTab + ' #sspa-attrib-table';
        const openPlugins = async query => {
            await page.goto(site + '/wp-admin/admin.php?page=sspa' + (query || '') + '#plugins');
            await page.locator(table).waitFor({ state: 'visible', timeout: 60000 });
        };

        // 1. Measure button text on one line.
        await openPlugins();
        const button = page.locator(table + ' .sspa-measure-plugin').first();
        await button.waitFor();
        const lines = await button.evaluate(el => {
            const range = document.createRange();
            range.selectNodeContents(el);
            return new Set([...range.getClientRects()].map(rect => Math.round(rect.top))).size;
        });
        await button.screenshot({ path: out + '/measure-button.png' });
        assert.equal(lines, 1, 'the Measure button text must sit on one line, found ' + lines);
        console.log('PASS Measure button text is on one line');

        // 2. A real Plugin Impact Analysis from Overview, picking zz-slow-shop only.
        await page.goto(site + '/wp-admin/admin.php?page=sspa#overview');
        await page.locator('#sspa-run-deep').click();
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-pluginpick[value="zz-slow-shop"]').check({ timeout: 30000 });
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-measure-start').click();
        await page.locator('#sspa-runner').waitFor({ state: 'visible', timeout: 30000 });
        const summary = page.locator('#sspa-runner .sspa-impact-summary');
        await summary.waitFor({ state: 'visible', timeout: 300000 });
        await page.waitForTimeout(5000);
        assert.ok(await summary.isVisible(), 'the summary must still be on screen 5 s after the run finished');
        const text = await summary.innerText();
        await page.screenshot({ path: out + '/summary.png' });
        assert.match(text, /zz-slow-shop/, 'the summary names the measured plugin');
        assert.match(text, /adds \d+ms typically/, 'the summary gives the verdict in the table\'s wording');
        console.log('PASS the finished run stays on screen with its summary: ' + text.replace(/\s+/g, ' ').slice(0, 160));

        // 3. Coming back: the unseen summary reopens; View marks it seen and opens Plugins.
        await page.goto(site + '/wp-admin/admin.php?page=sspa#overview');
        await summary.waitFor({ state: 'visible', timeout: 30000 });
        console.log('PASS the unseen summary reopens on the next visit');
        await page.locator('#sspa-runner .sspa-impact-summary-view').click();
        await page.locator(pluginsTab).waitFor({ state: 'visible' });
        assert.equal(new URL(page.url()).hash, '#plugins', 'View opens the Plugins tab');
        assert.ok(!(await page.locator('#sspa-runner').isVisible()), 'View closes the summary');
        await page.waitForTimeout(1500); // the seen marker is saved
        await page.goto(site + '/wp-admin/admin.php?page=sspa#overview');
        await page.locator('#sspa_main .tab-contents[data-tab="overview"]').waitFor({ state: 'visible' });
        await page.waitForTimeout(3000);
        assert.ok(!(await summary.isVisible()), 'a seen summary does not reopen');
        console.log('PASS View opens Plugins and a seen summary stays closed');

        // 4. Explorer: default sort, page filter, sort by name, show filter, URL state.
        await openPlugins();
        const slugs = () => page.$$eval(table + ' tbody tr[data-component]:not([hidden])', rows => rows.map(row => row.dataset.component));
        assert.equal((await slugs())[0], 'zz-slow-shop', 'the measured slow plugin sorts first by default');
        console.log('PASS default order: measured impact, largest first');

        const cell = () => page.locator(table + ' tbody tr[data-component="zz-slow-shop"] .sspa-impact-col').innerText();
        await page.locator('#sspa-plugins-page').selectOption('home');
        await page.locator(table + '[data-page="home"]').waitFor({ timeout: 30000 });
        assert.match(await cell(), /no measurable impact/i, 'on home the plugin has no measurable impact');
        await page.locator('#sspa-plugins-page').selectOption('shop');
        await page.locator(table + '[data-page="shop"]').waitFor({ timeout: 30000 });
        assert.match(await cell(), /adds \d+ms/, 'on shop the plugin adds time');
        await page.screenshot({ path: out + '/plugins-shop.png', fullPage: true });
        console.log('PASS the page filter shows that page\'s figures');

        await page.locator('#sspa-plugins-sort').selectOption('name');
        const byName = await slugs();
        assert.deepEqual(byName, [...byName].sort((a, b) => a.localeCompare(b)), 'sort by name is alphabetical');
        await page.locator('#sspa-plugins-show').selectOption('unmeasured');
        assert.ok(!(await slugs()).includes('zz-slow-shop'), 'Needs measuring hides the measured plugin');
        assert.match(await page.locator('#sspa-plugins-count').innerText(), /Showing \d+ of \d+/);
        console.log('PASS sort by name and the Needs measuring filter');

        await page.reload();
        await page.locator(table + '[data-page="shop"]').waitFor({ timeout: 60000 });
        assert.equal(await page.locator('#sspa-plugins-sort').inputValue(), 'name');
        assert.equal(await page.locator('#sspa-plugins-show').inputValue(), 'unmeasured');
        console.log('PASS a reload restores page, sort and filter from the URL');

        const compare = page.locator(pluginsTab + ' .sspa-plugins-compare a[href^="https://superspeedy.org/"]');
        assert.ok(await compare.count() >= 1, 'the tab links to the public figures on superspeedy.org');
        console.log('PASS superspeedy.org comparison link: ' + await compare.first().getAttribute('href'));

        // 5. Page-scoped run from the admin-bar panel on the shop page: the summary heads the
        //    panel and links to the Plugins tab filtered to that page.
        await page.goto(site + '/?post_type=product');
        // The node sits in a hover-revealed submenu; a DOM click runs its real handler.
        await page.$eval('#wp-admin-bar-sspa-adhoc > a', a => a.click());
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-measure').click({ timeout: 120000 });
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-pluginpick[value="zz-slow-shop"]').check({ timeout: 30000 });
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-measure-start').click();
        const panelSummary = page.locator('#sspa-adhoc-pop .sspa-adhoc-impact-summary');
        await panelSummary.waitFor({ state: 'visible', timeout: 300000 });
        // The panel profiles the address as an ad-hoc page (url-<hash>), not as the shop key.
        const verdict = (await panelSummary.innerText()).match(/zz-slow-shop adds \d+ms on (url-[0-9a-f]+)/);
        assert.ok(verdict, 'the panel summary gives the verdict for this page');
        const viewHref = await panelSummary.locator('.sspa-impact-summary-view').getAttribute('href');
        assert.ok(viewHref.endsWith('page=sspa&sspa_page=' + verdict[1] + '#plugins'), 'the panel links to the Plugins tab filtered to this page: ' + viewHref);
        await page.screenshot({ path: out + '/panel-summary.png' });
        await page.goto(viewHref);
        await page.locator(table + '[data-page="' + verdict[1] + '"]').waitFor({ timeout: 60000 });
        console.log('PASS the page-scoped run summarises in the panel and its link opens Plugins on that page');

        assert.deepEqual(errors, [], 'no JavaScript errors');
        console.log('PASS no JavaScript errors');
    } catch (error) {
        failed = true;
        console.error('FAIL ' + (error && error.message ? error.message : error));
    } finally {
        await browser.close();
    }
    process.exit(failed ? 1 : 0);
})();
