// Compare's chart after case 159 (run-compare-pages.sh): the plugin name and version are inside the
// chart canvas, the page measured with "Analyse this page" is in the chart and the chooser, and
// unticking it in the chooser removes it from the chart and the data table; All brings it back.
const assert = require('node:assert/strict');
const path = require('node:path');
const playwrightModule = process.env.SSPA_PLAYWRIGHT_MODULE || path.resolve(__dirname, '../observatory/node_modules/playwright');
const { chromium } = require(playwrightModule);

const siteUrl = process.env.SSPA_E2E_URL;
const before = process.env.SSPA_E2E_BEFORE;
const after = process.env.SSPA_E2E_AFTER;
const version = process.env.SSPA_E2E_VERSION;
const screenshot = process.env.SSPA_E2E_SCREENSHOT;
if (!siteUrl || !process.env.SSPA_E2E_USER || !process.env.SSPA_E2E_PASSWORD || !before || !after || !version) {
	throw new Error('compare-pages.e2e.cjs needs the site, administrator, the two run IDs and the plugin version.');
}

(async () => {
	const browser = await chromium.launch({ headless: true });
	const page = await (await browser.newContext({ viewport: { width: 1400, height: 900 } })).newPage();
	const errors = [];
	page.on('pageerror', (error) => errors.push(error.message));
	try {
		await page.goto(siteUrl + '/wp-login.php');
		await page.locator('#user_login').fill(process.env.SSPA_E2E_USER);
		await page.locator('#user_pass').fill(process.env.SSPA_E2E_PASSWORD);
		await Promise.all([page.waitForURL(/\/wp-admin\//), page.locator('#wp-submit').click()]);
		await page.evaluate(() => { try { localStorage.removeItem('sspa_history_pages'); } catch (e) {} });

		await page.goto(siteUrl + '/wp-admin/admin.php?page=sspa#compare');
		await page.locator('#sspa-history-before').waitFor();
		await page.selectOption('#sspa-history-before', before);
		await page.selectOption('#sspa-history-after', after);
		assert.equal(await page.locator('#sspa-history-include-adhoc').isChecked(), true, 'pages measured with Analyse this page are included by default');
		await page.locator('#sspa-history-compare').click();
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'chart loaded' }).waitFor({ timeout: 60000 });

		const chartState = () => page.evaluate(() => {
			const option = document.querySelector('.sspa-history-chart').sspaChart.getOption();
			return { title: option.title[0].text, subtitle: option.title[0].subtext, pages: option.xAxis[0].data };
		});
		let state = await chartState();
		assert.equal(state.title, 'Super Speedy Performance Analysis v' + version, 'the chart canvas carries the plugin name and version');
		assert.match(state.subtitle, new RegExp('Before #' + before + ' · After #' + after), 'the subtitle names the two runs');
		const custom = state.pages.find((key) => key.startsWith('url-'));
		assert.ok(custom, 'the page measured with Analyse this page is in the chart');

		await page.locator('.sspa-history-page-chooser summary').click();
		const option = page.locator('.sspa-history-page-option', { hasText: '/?case159=custom-page' });
		await option.waitFor();
		assert.match(await page.locator('#sspa-history-comparison').innerText(), /\/\?case159=custom-page/, 'the comparison table names the page by its URL');
		await option.locator('input').uncheck();
		state = await chartState();
		assert.ok(!state.pages.includes(custom), 'unticking a page removes it from the chart');
		assert.equal(await page.locator(`.sspa-history-data-table tr[data-page-key="${custom.replace(/"/g, '\\"')}"]`).first().isHidden(), true, 'and from the data table');

		await page.reload();
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'chart loaded' }).waitFor({ timeout: 60000 });
		await page.selectOption('#sspa-history-before', before);
		await page.selectOption('#sspa-history-after', after);
		await page.locator('#sspa-history-compare').click();
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'chart loaded' }).waitFor({ timeout: 60000 });
		state = await chartState();
		assert.ok(!state.pages.includes(custom), 'the choice is kept after reloading the page');

		await page.locator('.sspa-history-page-chooser summary').click();
		await page.locator('.sspa-history-pages-all').click();
		state = await chartState();
		assert.ok(state.pages.includes(custom), 'All brings every page back');
		await page.waitForTimeout(1500); // let the chart's entry animation finish
		if (screenshot) await page.locator('.sspa-history-chart').screenshot({ path: screenshot });
		assert.deepEqual(errors, [], 'no browser errors');
		console.log('PASS: compare chart title, custom-URL page and page chooser');
	} finally {
		await browser.close();
	}
})().catch((error) => { console.error('FAIL: ' + error.message); process.exit(1); });
