// Overview "Top insights": the "Page analysis" link opens that page's profile panel in place, and
// the same href loaded directly (new tab, copied link) selects Pages and opens the same panel.
// Needs a completed analysis with a page-level finding: run case 144 first on the same site.
const assert = require('node:assert/strict');
const { chromium } = require(process.env.SSPA_PLAYWRIGHT_MODULE || '../observatory/node_modules/playwright');

(async () => {
    const site = process.env.SSPA_E2E_URL;
    assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
    const browser = await chromium.launch({ headless: true });
    let failed = false;
    try {
        const page = await browser.newPage({ viewport: { width: 1280, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(site + '/wp-login.php');
        await page.waitForFunction(() => document.activeElement?.id === 'user_login');
        await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
        await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
        await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);

        await page.goto(site + '/wp-admin/admin.php?page=sspa#overview');
        const link = page.locator('.sspa-insights .sspa-insight-profile').first();
        await link.waitFor({ timeout: 60000 });
        const open = page.locator('.sspa-insights .sspa-insight-open').first();
        assert.equal(await open.getAttribute('target'), '_blank');
        console.log('PASS the insight links to its measured page: ' + await open.getAttribute('href'));
        const href = await link.getAttribute('href');
        const id = await link.getAttribute('data-profile-id');
        await page.locator('.sspa-insights').first().screenshot({ path: process.env.SSPA_E2E_OUT + '/overview-insight-links.png' });
        await link.click();
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-rerun').first().waitFor({ timeout: 30000 });
        assert.match(page.url(), /#overview$/, 'a plain click opens the panel in place');
        console.log('PASS a click opens profile ' + id + ' in the panel, staying on Overview');
        await page.screenshot({ path: process.env.SSPA_E2E_OUT + '/overview-insight-panel.png', fullPage: false });

        await page.goto(href);
        await page.locator('#sspa-adhoc-pop .sspa-adhoc-rerun').first().waitFor({ timeout: 30000 });
        const active = await page.locator('#sspa_main .nav-tab-active').getAttribute('data-tab');
        assert.equal(active, 'pages', 'the deep link selects the Pages tab');
        assert.ok(!new URL(page.url()).searchParams.has('sspa_profile'), 'sspa_profile is consumed once');
        console.log('PASS the deep link ' + href + ' selects Pages and opens the panel');
        assert.deepEqual(errors, [], 'no page errors');
        console.log('PASS no JavaScript errors');
    } catch (error) {
        failed = true;
        console.log('FAIL ' + error.message);
    } finally {
        await browser.close();
    }
    process.exit(failed ? 1 : 0);
})();
