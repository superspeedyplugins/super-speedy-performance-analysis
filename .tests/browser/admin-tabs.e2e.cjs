const assert = require('node:assert/strict');
const { chromium } = require(process.env.SSPA_PLAYWRIGHT_MODULE || '../observatory/node_modules/playwright');

(async () => {
    const site = process.env.SSPA_E2E_URL;
    assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(site + '/wp-login.php');
        await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
        await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
        await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);
        const selected = async slug => {
            await page.waitForFunction(expected => {
                const active = [...document.querySelectorAll('#sspa_main .nav-tab-active')];
                const visible = [...document.querySelectorAll('#sspa_main .tab-contents')].filter(node => getComputedStyle(node).display !== 'none');
                return active.length === 1 && active[0].dataset.tab === expected && visible.length === 1 && visible[0].dataset.tab === expected;
            }, slug, { timeout: 5000 }).catch(() => assert.fail('URL navigation must select and display ' + slug));
            console.log('PASS URL selects ' + slug);
        };
        await page.goto(site + '/wp-admin/admin.php?page=sspa#history');
        await selected('history');
        await page.locator('.nav-tab[data-tab="overview"]').click();
        await selected('overview');
        await page.evaluate(() => { location.hash = 'history'; });
        await selected('history');
        await page.goBack();
        await selected('overview');
        await page.goForward();
        await selected('history');
        await page.reload();
        await selected('history');
        for (const fragment of ['', 'not-a-tab', '"] , div [data-tab="history']) {
            await page.evaluate(value => { location.hash = value; }, fragment);
            await selected('overview');
        }
        assert.deepEqual(errors, [], 'URL fragments must not cause JavaScript errors');
        console.log('PASS no JavaScript errors');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error(error); process.exitCode = 1; });
