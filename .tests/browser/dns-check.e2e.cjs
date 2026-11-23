// Pro DNS check, as an administrator uses it: open Tools, press Check DNS, read the result, reload
// and see it again. Uses this server's own resolver, so the verdict is whatever this machine's DNS
// is; the journey asserts the screen, case 165 asserts the counting.
const assert = require('node:assert/strict');
const path = require('node:path');
const { chromium } = require(process.env.SSPA_PLAYWRIGHT_MODULE || '../observatory/node_modules/playwright');

(async () => {
    const site = process.env.SSPA_E2E_URL;
    assert.match(new URL(site).hostname, /^tests[-.].*\.localhost$/);
    const browser = await chromium.launch({ headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1400, height: 900 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(site + '/wp-login.php');
        await page.waitForFunction(() => document.activeElement?.id === 'user_login');
        await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
        await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
        await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);
        await page.goto(site + '/wp-admin/admin.php?page=sspa#tools');
        const button = page.locator('#sspa-dns-check .sspa-dns-check-run');
        await button.waitFor({ state: 'visible', timeout: 20000 });
        console.log('PASS Tools shows Check DNS');
        await button.click();
        await page.locator('#sspa-dns-check .sspa-dns-check-status').filter({ hasText: 'Checking' }).waitFor({ timeout: 5000 });
        console.log('PASS pressing it says the check is running');
        assert.equal(await page.locator('#sspa-dns-check .sspa-dns-results').count(), 0, 'no saved result before the click');
        // The section is replaced when the check finishes: the button is enabled again and the status is empty.
        await page.waitForFunction(() => document.querySelectorAll('#sspa-dns-check .sspa-dns-results tbody tr').length > 0
            && !document.querySelector('#sspa-dns-check .sspa-dns-check-run').disabled
            && document.querySelector('#sspa-dns-check .sspa-dns-check-status').textContent === '', null, { timeout: 40000 });
        const text = await page.locator('#sspa-dns-check').innerText();
        assert.match(text, /\d+ of 12 answered/);
        assert.match(text, /Healthy|Dropping lookups|One lookup lost|Slow|Not finished|Could not be reached/);
        assert.match(text, /Last checked/);
        console.log('PASS the result is shown in place: ' + text.split('\n').filter(l => /answered|Healthy|Dropping|Slow/.test(l)).join(' | '));
        await page.locator('#sspa-dns-check').screenshot({ path: path.join(process.env.SSPA_E2E_OUT, 'dns-check.png') });
        await page.reload();
        await page.locator('#sspa-dns-check .sspa-dns-results tbody tr').first().waitFor({ timeout: 20000 });
        console.log('PASS the saved result is there after a reload');
        assert.deepEqual(errors, [], 'no JavaScript errors');
        console.log('PASS no JavaScript errors');
    } finally {
        await browser.close();
    }
})().catch(error => { console.error('FAIL ' + error.message); process.exit(1); });
