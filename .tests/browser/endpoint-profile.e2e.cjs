// AJAX tab user journey (plan additions B, E, F): an administrator starts a window, a logged-out
// visitor uses a trimmed REST endpoint, the administrator stops the window, reads the endpoint's
// start-up, CPU and loaded setup, then uses "Profile this endpoint" and reads the profile and its
// environment evidence. Then the Pages tab lists the result. Screenshots and a summary are kept
// in SSPA_ENDPOINT_ARTIFACT_DIR; the browser is always closed, on failure too.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const {chromium} = require(process.env.SSPA_PLAYWRIGHT_MODULE || '../node_modules/playwright');

const site = process.env.SSPA_E2E_URL;
const route = process.env.SSPA_ENDPOINT_ROUTE || 'sspa-endpoint-fixture/v1/startup/';
const match = process.env.SSPA_ENDPOINT_MATCH || 'startup';
const placeholder = process.env.SSPA_ENDPOINT_PLACEHOLDER || '{term}';
const term = process.env.SSPA_ENDPOINT_TERM || 'blue';
const out = process.env.SSPA_ENDPOINT_ARTIFACT_DIR;

(async () => {
    assert.match(new URL(site).hostname, /\.localhost$/);
    fs.mkdirSync(out, {recursive: true});
    const browser = await chromium.launch({headless: true});
    const summary = {site, route, steps: []};
    try {
        const admin = await browser.newContext({viewport: {width: 1400, height: 1000}});
        const page = await admin.newPage();
        const errors = [];
        page.on('pageerror', e => errors.push(e.message));
        await page.goto(site + '/wp-login.php');
        await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
        await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
        await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);

        await page.goto(site + '/wp-admin/admin.php?page=sspa#ajax');
        const startForm = page.locator('.sspa-ajax-start');
        await startForm.waitFor();
        await startForm.locator('[name=label]').fill('Endpoint journey ' + Date.now());
        await startForm.locator('[name=scenario]').fill('Visitor uses ' + match);
        await Promise.all([page.waitForEvent('load'), startForm.locator('button').click()]);
        await page.locator('.sspa-ajax-stop').waitFor();
        summary.steps.push('window started from the AJAX tab');

        // A logged-out visitor, in its own context, exercises the endpoint.
        const visitor = await browser.newContext();
        const visitorPage = await visitor.newPage();
        const terms = [term, 'red', 'green', term, 'navy'];
        for (const t of terms) {
            const response = await visitorPage.goto(site + '/wp-json/' + route + t);
            assert.equal(response.status(), 200, 'visitor request to ' + route + t);
            summary.transport = response.headers()['x-sss-transport'] || summary.transport || null;
        }
        await visitor.close();
        summary.steps.push(terms.length + ' visitor requests');

        await Promise.all([page.waitForEvent('load'), page.locator('.sspa-ajax-stop').click()]);
        const row = page.locator('.sspa-ajax-endpoints tr[data-endpoint*="' + match + '"][data-method="GET"]').first();
        await row.waitFor();
        const rowText = await row.innerText();
        summary.row = rowText;
        assert.match(rowText, new RegExp('\\b' + terms.length + '\\b'), 'the row counts the visitor requests');
        assert.match(rowText, /active plugins loaded|plugins? loaded/, 'the row shows the loaded setup');
        assert.match(rowText, /% of wall/, 'the row shows CPU against wall');
        await row.scrollIntoViewIfNeeded();
        await page.locator('.sspa-ajax-endpoints').screenshot({path: out + '/observed-endpoints.png'});
        summary.steps.push('observed endpoints table read');

        // Profile this endpoint: the placeholder is refused, the completed URL runs.
        await row.locator('.sspa-endpoint-profile-open').click();
        const form = page.locator('.sspa-endpoint-profile-form');
        await form.waitFor({state: 'visible'});
        // First time: the route with its placeholder. Later: the URL last profiled for it.
        const suggested = await form.locator('[name=url]').inputValue();
        summary.suggested = suggested;
        assert.ok(suggested.includes(placeholder) || suggested.includes('/wp-json/' + route), 'the suggested URL is this route: ' + suggested);
        const template = site + '/wp-json/' + route + placeholder;
        await form.locator('[name=url]').fill(template);
        await form.locator('[name=samples]').fill('3');
        // Observed from a logged-out visitor, so "a logged-out visitor" is preselected.
        assert.equal(await form.locator('[name=as]:checked').inputValue(), 'anon', 'the identity is preselected from the observation');
        summary.steps.push('identity preselected: a logged-out visitor');
        await form.locator('[name=confirmed]').check();
        await form.locator('button.button-primary').click();
        await page.waitForFunction(() => /placeholder/i.test(document.querySelector('.sspa-ajax-status').textContent));
        summary.steps.push('unfilled placeholder refused: ' + await page.locator('.sspa-ajax-status').innerText());
        const filled = template.replace(placeholder, term);
        await form.locator('[name=url]').fill(filled);
        await form.locator('button.button-primary').click();
        const panel = page.locator('#sspa-adhoc-pop');
        await panel.locator('.sspa-environment').waitFor({timeout: 300000});
        const panelText = await panel.innerText();
        summary.environment = await panel.locator('.sspa-environment').innerText();
        assert.match(panelText, /profiled as a logged-out visitor/);
        assert.match(panelText, /This request's files in OPcache/);
        assert.match(panelText, /Database round trip/);
        assert.match(panelText, /Object cache/);
        await panel.screenshot({path: out + '/endpoint-profile-panel.png'});
        await panel.locator('.sspa-environment').screenshot({path: out + '/endpoint-environment.png'});
        summary.steps.push('profile opened in the panel with environment evidence');

        await page.keyboard.press('Escape');
        await page.goto(site + '/wp-admin/admin.php?page=sspa#ajax');
        await page.locator('.sspa-ajax-endpoints tr[data-endpoint*="' + match + '"] .sspa-endpoint-profile-view').first().waitFor();
        summary.steps.push('the endpoint row now opens its latest profile');

        await page.goto(site + '/wp-admin/admin.php?page=sspa#pages');
        const pagesRow = page.locator('.sspa-pages-table tr', {hasText: route + term}).first();
        await pagesRow.waitFor();
        assert.match(await pagesRow.innerText(), /endpoint-/);
        summary.steps.push('the Pages tab lists the endpoint profile');
        assert.deepEqual(errors, [], 'no browser errors');
        console.log('PASS endpoint journey: ' + summary.steps.join('; '));
    } finally {
        fs.writeFileSync(out + '/summary.json', JSON.stringify(summary, null, 2));
        await browser.close();
    }
})().catch(e => { console.error(e); process.exitCode = 1; });
