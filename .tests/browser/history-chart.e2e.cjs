const assert = require('node:assert/strict');
const path = require('node:path');
const playwrightModule = process.env.SSPA_PLAYWRIGHT_MODULE || path.resolve(__dirname, '../observatory/node_modules/playwright');
const { chromium } = require(playwrightModule);

const siteUrl = process.env.SSPA_E2E_URL;
const adminUser = process.env.SSPA_E2E_USER;
const adminPassword = process.env.SSPA_E2E_PASSWORD;
const screenshot = process.env.SSPA_E2E_SCREENSHOT;

if (!siteUrl || !adminUser || !adminPassword) {
	throw new Error('The History browser test requires its site URL and parallel-dev administrator credentials.');
}

(async () => {
	const browser = await chromium.launch({ headless: true, channel: process.platform === 'win32' ? 'chrome' : undefined });
	const context = await browser.newContext({ viewport: { width: 1400, height: 900 } });
	const page = await context.newPage();
	const browserErrors = [];
	page.on('pageerror', (error) => browserErrors.push(error.message));
	page.on('console', (message) => {
		if (message.type() === 'error') browserErrors.push(message.text());
	});

	try {
		await page.goto(siteUrl + '/wp-login.php');
		await page.locator('#user_login').fill(adminUser);
		await page.locator('#user_pass').fill(adminPassword);
		await Promise.all([
			page.waitForURL(/\/wp-admin\//),
			page.locator('#wp-submit').click()
		]);

		await page.goto(siteUrl + '/wp-admin/admin.php?page=sspa');
		assert.equal(
			await page.evaluate(() => performance.getEntriesByType('resource').some((entry) => entry.name.includes('echarts-history.min.js'))),
			false,
			'ECharts must not load on the Overview tab'
		);

		await page.locator('.nav-tab[data-tab="history"]').click();
		await page.locator('.sspa-history-chart canvas').waitFor();
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'chart loaded' }).waitFor();
		assert.equal(
			await page.evaluate(() => performance.getEntriesByType('resource').filter((entry) => entry.name.includes('echarts-history.min.js')).length),
			1,
			'ECharts loads once, after History opens'
		);
		const malformedStatus = await page.evaluate(async () => {
			const probe = document.createElement('div');
			probe.innerHTML = '<section data-sspa-history-chart><div class="sspa-history-chart-status"></div><div class="sspa-history-chart"></div><script type="application/json" class="sspa-history-chart-document">{broken</script></section>';
			document.body.appendChild(probe);
			window.jQuery(document).trigger('sspa:tab-rendered', ['history', probe]);
			await new Promise((resolve) => setTimeout(resolve, 50));
			const status = probe.querySelector('.sspa-history-chart-status').textContent;
			probe.remove();
			return status;
		});
		assert.match(malformedStatus, /could not be read/i, 'Malformed chart data must surface a visible error');

		const source = await page.locator('.sspa-history-chart-document').evaluate((node) => JSON.parse(node.textContent));
		const plotted = await page.locator('.sspa-history-chart').evaluate((mount) => {
			const option = mount.sspaChart.getOption();
			return {
				previous: option.series[0].data.map((point) => Number(point.value[1])),
				current: option.series[1].data.map((point) => Number(point.value[1])),
				previousMedians: option.series[2].data.map((point) => point === null ? null : Number(point.value === undefined ? point : point.value)),
				currentMedians: option.series[3].data.map((point) => point === null ? null : Number(point.value === undefined ? point : point.value)),
				animation: option.animation
			};
		});
		assert.deepEqual(plotted.previous, source.pages.flatMap((item) => item.previous.points.map((point) => Number(point.value))));
		assert.deepEqual(plotted.current, source.pages.flatMap((item) => item.current.points.map((point) => Number(point.value))));
		assert.deepEqual(plotted.previousMedians, source.pages.map((item) => item.previous.median));
		assert.deepEqual(plotted.currentMedians, source.pages.map((item) => item.current.median));
		assert.equal(await page.locator('.sspa-history-data-table tbody tr').count(), source.pages.length);

		await page.locator('.sspa-history-metric').selectOption('generation_ms');
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'Page generation time chart loaded' }).waitFor();
		const generation = await page.locator('[data-sspa-history-chart]').evaluate((card) => card.sspaDocument);
		assert.equal(generation.metric.key, 'generation_ms');
		assert.equal(generation.metric.source, 'per_run_median');
		const generationPoints = await page.locator('.sspa-history-chart').evaluate((mount) => mount.sspaChart.getOption().series[1].data.map((point) => Number(point.value[1])));
		assert.deepEqual(generationPoints, generation.pages.flatMap((item) => item.current.points.map((point) => Number(point.value))));

		const filter = page.locator('.sspa-history-page-filter');
		await filter.fill('no-such-page');
		assert.equal(await page.locator('.sspa-history-data-table tbody tr').evaluateAll((rows) => rows.filter((row) => !row.hidden).length), 0);
		await filter.fill(generation.pages[0].label.toLowerCase());
		assert.ok(await page.locator('.sspa-history-data-table tbody tr').evaluateAll((rows) => rows.filter((row) => !row.hidden).length) >= 1);

		const dataSummary = page.locator('.sspa-history-data-details summary');
		await dataSummary.focus();
		await page.keyboard.press('Enter');
		assert.equal(await page.locator('.sspa-history-data-details').getAttribute('open'), '');

		await page.emulateMedia({ reducedMotion: 'reduce' });
		await page.locator('.sspa-history-metric').selectOption('sql_ms');
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'Database time chart loaded' }).waitFor();
		assert.equal(await page.locator('.sspa-history-chart').evaluate((mount) => mount.sspaChart.getOption().animation), false);
		if (screenshot) {
			const parsedScreenshot = path.parse(screenshot);
			await page.locator('[data-sspa-history-chart]').screenshot({
				path: path.join(parsedScreenshot.dir, parsedScreenshot.name + '-card' + parsedScreenshot.ext)
			});
		}

		await page.setViewportSize({ width: 480, height: 800 });
		await page.locator('.sspa-history-chart canvas').waitFor();
		assert.ok((await page.locator('.sspa-history-chart').boundingBox()).width >= 560);
		if (screenshot) {
			await page.screenshot({ path: screenshot, fullPage: true });
		}
		assert.deepEqual(browserErrors, []);
		console.log('PASS: History chart plots exact source values, switches metrics, filters pages, honours reduced motion and remains usable at 480px');
	} finally {
		await browser.close();
	}
})().catch((error) => {
	console.error('FAIL:', error.stack || error.message);
	process.exitCode = 1;
});
