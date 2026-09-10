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
		if (message.type() === 'error') browserErrors.push(message.text() + ' [' + message.location().url + ']');
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
		const axis = await page.locator('.sspa-history-chart').evaluate(mount => {
			const option = mount.sspaChart.getOption();
			return {rotate:option.xAxis[0].axisLabel.rotate, lines:option.xAxis[0].splitLine.show,
				labels:option.xAxis[0].data.map(key => option.xAxis[0].axisLabel.formatter ? option.xAxis[0].axisLabel.formatter(key) : key)};
		});
		assert.equal(axis.rotate, 90, 'Page labels must be vertical so neighbouring categories do not overlap');
		assert.equal(axis.lines, true, 'Faint category dividers must align labels with measurements');
		assert.ok(axis.labels.every(label => !label.includes(' · normal')), 'Default transport context is not axis text');
		assert.ok(source.pages.some(item => item.relative_url), 'Retained measured URLs must reach the chart document');
		const tooltipHeadings = await page.locator('.sspa-history-chart').evaluate(mount => {
			const option = mount.sspaChart.getOption();
			return option.series.flatMap((series, index) => series.data.map(data => {
				const node = document.createElement('div');
				node.innerHTML = option.tooltip[0].formatter({seriesName:series.name, seriesIndex:index, data});
				const label = option.xAxis[0].axisLabel.formatter(data.value[0]).split('\n')[0];
				return {actual:node.querySelector('strong').textContent, expected:label + ' (' + (index === 0 || data.period === 'previous' || data.period === 'Before' ? 'previous' : 'recent') + ')'};
			}));
		});
		assert.ok(tooltipHeadings.length > 0);
		for (const heading of tooltipHeadings) assert.equal(heading.actual, heading.expected, 'Every tooltip names its x-axis page and previous/recent period');
		const escapedHeading = await page.locator('.sspa-history-chart').evaluate(mount => {
			const option = mount.sspaChart.getOption();
			const data = {...option.series[0].data[0], value:['<img src=x onerror=alert(1)>', 1]};
			const node = document.createElement('div');
			node.innerHTML = option.tooltip[0].formatter({data, seriesIndex:0});
			return {text:node.querySelector('strong').textContent, images:node.querySelectorAll('img').length};
		});
		assert.deepEqual(escapedHeading, {text:'<img src=x onerror=alert(1)> (previous)', images:0}, 'Page labels are inert tooltip text');
		const plotted = await page.locator('.sspa-history-chart').evaluate((mount) => {
			const option = mount.sspaChart.getOption();
			return {
				previous: option.series[0].data.map((point) => Number(point.value[1])),
				current: option.series[1].data.map((point) => Number(point.value[1])),
				series: option.series.map(series => ({name:series.name, type:series.type, color:series.itemStyle && series.itemStyle.color})),
				animation: option.animation
			};
		});
		assert.deepEqual(plotted.previous, source.pages.flatMap((item) => item.previous.points.map((point) => Number(point.value))));
		assert.deepEqual(plotted.current, source.pages.flatMap((item) => item.current.points.map((point) => Number(point.value))));
		assert.deepEqual(plotted.series, [
			{name:'Previous measurements', type:'scatter', color:'#6b7280'},
			{name:'Recent measurements', type:'scatter', color:'#2271b1'},
			{name:'Errors', type:'scatter', color:'#d63638'}
		], 'The chart shows only grey previous, blue recent and red error points, without median overlays');
		assert.equal(await page.locator('.sspa-history-data-table tbody tr').count(), source.pages.length);

		// A rejected metric request must not relabel the measurements still on screen.
		const rejectMetric = async route => {
			if ((route.request().postData() || '').includes('action=sspa_history_series')) {
				return route.fulfill({status: 200, contentType: 'application/json', body: JSON.stringify({success: false, data: 'Metric request rejected for regression test.'})});
			}
			return route.continue();
		};
		await page.route('**/admin-ajax.php', rejectMetric);
		await page.locator('.sspa-history-metric').selectOption('generation_ms');
		await page.locator('.sspa-history-chart-status').filter({hasText: 'Metric request rejected'}).waitFor();
		assert.equal(await page.locator('.sspa-history-metric').inputValue(), source.metric.key,
			'A failed metric change must keep the selector consistent with the displayed measurements');
		assert.equal(await page.locator('[data-sspa-history-chart]').evaluate(card => card.sspaDocument.metric.key), source.metric.key);
		await page.unroute('**/admin-ajax.php', rejectMetric);
		await page.locator('.sspa-history-metric').selectOption('generation_ms');
		await page.locator('.sspa-history-chart-status').filter({ hasText: 'Page generation time chart loaded' }).waitFor();
		const generation = await page.locator('[data-sspa-history-chart]').evaluate((card) => card.sspaDocument);
		assert.equal(generation.metric.key, 'generation_ms');
		assert.equal(generation.metric.source, 'per_run_median');
		assert.match(await page.locator('[data-sspa-history-chart]').innerText(), /Each point is one analysis.*page median/, 'Summary metrics visibly distinguish per-analysis medians from raw request samples');
		const generationPoints = await page.locator('.sspa-history-chart').evaluate((mount) => mount.sspaChart.getOption().series[1].data.map((point) => Number(point.value[1])));
		assert.deepEqual(generationPoints, generation.pages.flatMap((item) => item.current.points.map((point) => Number(point.value))));

		const filter = page.locator('.sspa-history-page-filter');
		await filter.fill('no-such-page');
		assert.equal(await page.locator('.sspa-history-data-table tbody tr').evaluateAll((rows) => rows.filter((row) => !row.hidden).length), 0);
		await page.locator('.sspa-history-metric').selectOption('sql_count');
		await page.locator('.sspa-history-metric').evaluate((select) => new Promise((resolve) => {
			if (!select.disabled) return resolve();
			const observer = new MutationObserver(() => {
				if (!select.disabled) { observer.disconnect(); resolve(); }
			});
			observer.observe(select, { attributes: true, attributeFilter: ['disabled'] });
		}));
		assert.equal(await page.locator('.sspa-history-data-table tbody tr').evaluateAll((rows) => rows.filter((row) => !row.hidden).length), 0,
			'Changing the metric must preserve the table page filter');
		assert.deepEqual(await page.locator('.sspa-history-chart').evaluate((mount) => mount.sspaChart.getOption().xAxis[0].data), [],
			'The chart and table must both keep the same empty filtered result');
		await filter.fill(generation.pages[0].label.toLowerCase());
		assert.ok(await page.locator('.sspa-history-data-table tbody tr').evaluateAll((rows) => rows.filter((row) => !row.hidden).length) >= 1);

		const dataSummary = page.locator('.sspa-history-data-details > summary');
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

		// Selecting exact runs must update the chart and report together, even with unchanged plugins.
		const selected = await page.locator('#sspa-history-after option').evaluateAll(nodes => nodes.slice(0, 2).map(node => node.value));
		await page.locator('#sspa-history-mode').selectOption('pair');
		await page.locator('#sspa-history-before').selectOption(selected[1]);
		await page.locator('#sspa-history-after').selectOption(selected[0]);
		await page.locator('#sspa-history-compare').click();
		await page.waitForFunction(() => !document.querySelector('#sspa-history-compare').disabled);
		await page.locator('.sspa-history-chart-status').filter({hasText:'chart loaded'}).waitFor();
		const exact = await page.locator('[data-sspa-history-chart]').evaluate(card => card.sspaDocument);
		assert.deepEqual(exact.previous.run_ids, [Number(selected[1])]);
		assert.deepEqual(exact.current.run_ids, [Number(selected[0])]);
		assert.match(await page.locator('#sspa-history-comparison').innerText(), new RegExp('#' + selected[0]));
		// Pending selector edits must not retarget actions attached to the displayed report.
		await page.locator('#sspa-history-before').selectOption(selected[0]);
		const exportRequest = page.waitForRequest(request => (request.postData() || '').includes('action=sspa_history_export'));
		await page.locator('.sspa-history-preview-export').click();
		const exported = new URLSearchParams((await exportRequest).postData());
		assert.equal(exported.get('before_run_id'), selected[1], 'Export must use the displayed comparison, not unsubmitted selector changes');
		await page.waitForFunction(() => jQuery.active === 0);
		await page.locator('#sspa-history-before').selectOption(selected[1]);
		await page.locator('.sspa-history-page-filter').fill('');
		await page.locator('.sspa-history-data-details > summary').click();
		await page.locator('.sspa-history-data-table tbody tr').first().locator('details > summary').click();
		await page.locator('.sspa-history-inspect-point').first().click();
		assert.match(await page.locator('.sspa-history-point-details').innerText(), /Analysis #/);
		let starts = 0;
		page.on('request', request => { if ((request.postData() || '').includes('action=sspa_start_run')) starts++; });
		const savedLink = page.locator('.sspa-history-run-link[data-run-id="' + selected[1] + '"]');
		await savedLink.click();
		await page.locator('.sspa-history-saved-report').waitFor();
		assert.match(await page.locator('.sspa-history-saved-report').innerText(), new RegExp('#' + selected[1]));
		assert.equal(starts, 0, 'Opening saved evidence never starts another analysis');
		await page.reload();
		await page.locator('.sspa-history-saved-report').waitFor();
		assert.equal(await page.locator('.sspa-history-saved-report').getAttribute('data-saved-run-id'), selected[1], 'A saved report URL survives reload');
		await page.goBack();
		await page.locator('.sspa-history-chart canvas').waitFor();
		// A failed saved-report response must be visible and retry the same selected run.
		const rejectSavedReport = async route => {
			if ((route.request().postData() || '').includes('action=sspa_history_run')) {
				await route.fulfill({status:200, contentType:'application/json', body:JSON.stringify({success:false, data:'Saved report test failure'})});
			} else await route.continue();
		};
		await page.route('**/admin-ajax.php', rejectSavedReport);
		await savedLink.click();
		await page.locator('#sspa-history-saved-run [role="alert"]').waitFor();
		assert.match(await page.locator('#sspa-history-saved-run').innerText(), /Saved report test failure/);
		await page.unroute('**/admin-ajax.php', rejectSavedReport);
		await page.locator('.sspa-history-retry').click();
		await page.locator('.sspa-history-saved-report').waitFor();
		assert.equal(await page.locator('.sspa-history-saved-report').getAttribute('data-saved-run-id'), selected[1]);
		await page.locator('.sspa-history-back').click();
		await page.locator('.sspa-history-chart canvas').waitFor();
		await page.locator('#sspa-history-mode').selectOption('setup');
		await page.locator('#sspa-history-compare').click();
		await page.waitForFunction(() => !document.querySelector('#sspa-history-compare').disabled);
		const setupPeriod = await page.locator('[data-sspa-history-chart]').evaluate(card => card.sspaDocument);
		assert.ok(setupPeriod.previous.run_ids.length > 1, 'The setup fixture includes repeated measurements before the update');
		assert.equal(Number(await page.locator('.sspa-history-comparison').getAttribute('data-before-run')), Math.max(...setupPeriod.previous.run_ids),
			'Automatic setup comparison must use the last measured run before the configuration changed');
		for (const width of [480, 320]) {
			await page.setViewportSize({width, height:800});
			await page.waitForFunction(() => {
				const mount = document.querySelector('.sspa-history-chart');
				return Math.abs(mount.sspaChart.getWidth() - mount.clientWidth) <= 1;
			}, null, {timeout:2000});
			assert.ok((await page.locator('.sspa-history-chart').boundingBox()).width > 100);
			const overflow = await page.evaluate(() => ({width:innerWidth, document:document.documentElement.scrollWidth,
				elements:Array.from(document.querySelectorAll('#sspa_main *')).filter(node => node.getBoundingClientRect().right > innerWidth + 1 && !node.closest('.sspa-table-scroll')).slice(0, 12).map(node => ({tag:node.tagName, cls:node.className, width:node.getBoundingClientRect().width}))}));
			if (screenshot) await page.screenshot({path:screenshot, fullPage:true});
			assert.ok(overflow.document <= width + 1, 'History must not overflow the viewport: ' + JSON.stringify(overflow));
		}
		if (screenshot) {
			await page.screenshot({ path: screenshot, fullPage: true });
		}
		if (process.env.SSPA_E2E_DIAGNOSTIC_RUN) {
			await page.setViewportSize({width:1400, height:900});
			await page.locator('.sspa-history-metric').selectOption('request_wall_ms');
			await page.waitForFunction(() => !document.querySelector('.sspa-history-metric').disabled);
			await page.locator('#sspa-history-mode').selectOption('setup');
			await page.locator('#sspa-history-after').selectOption(process.env.SSPA_E2E_DIAGNOSTIC_RUN);
			await page.locator('#sspa-history-compare').click();
			await page.waitForFunction(() => !document.querySelector('#sspa-history-compare').disabled);
			await page.locator('.sspa-history-chart-status').filter({hasText:'chart loaded'}).waitFor();
			// setOption publishes coordinates before its animation finishes. Arm the
			// real render-completion signal before filtering, rather than sleeping.
			await page.locator('.sspa-history-chart').evaluate(mount => {
				mount.sspaDiagnosticRendered = new Promise(resolve => {
					const finished = () => {
						const labels = mount.sspaChart.getOption().xAxis[0].data;
						if (labels.length !== 1 || mount.sspaChart.getOption().xAxis[0].axisLabel.formatter(labels[0]).split('\n')[0] !== 'Diagnostic Pair') return;
						mount.sspaChart.off('finished', finished);
						resolve();
					};
					mount.sspaChart.on('finished', finished);
				});
			});
			await page.locator('.sspa-history-page-filter').fill('diagnostic-pair');
			await page.locator('.sspa-history-chart').evaluate(mount => mount.sspaDiagnosticRendered);
			const diagnosticPoint = await page.locator('.sspa-history-chart').evaluate(mount => {
				const points = mount.sspaChart.getOption().series[1].data;
				// Sample two is drawn last and may cover sample one's centre when their
				// real timings are close. The table check below still opens sample one.
				const index = points.findLastIndex(point => (point.savedPoint.evidence.php_diagnostics.events || []).some(event => event.message.includes('diagnostic two')));
				if (index < 0) return null;
				const pixel = mount.sspaChart.convertToPixel({seriesIndex:1}, points[index].value);
				return {index, symbol:points[index].symbol, x:pixel[0] + points[index].symbolOffset[0], y:pixel[1], message:points[index].savedPoint.evidence.php_diagnostics.events[0].message};
			});
			assert.ok(diagnosticPoint, 'The actual measured warning appears in a chart point');
			assert.equal(diagnosticPoint.symbol, 'triangle', 'Observed diagnostics have a visible warning marker');
			await page.locator('.sspa-history-chart').scrollIntoViewIfNeeded();
			const chartBox = await page.locator('.sspa-history-chart').boundingBox();
			await page.mouse.click(chartBox.x + diagnosticPoint.x, chartBox.y + diagnosticPoint.y);
			await page.locator('.sspa-history-point-details').filter({hasText:'SSPA local diagnostic two'}).waitFor();
			await page.locator('.sspa-history-data-details > summary').click();
			const diagnosticRow = page.locator('.sspa-history-data-table tbody tr[data-page-label*="diagnostic-pair"]');
			await diagnosticRow.locator('details > summary').click();
			await diagnosticRow.locator('.sspa-history-inspect-point').filter({hasText:'After'}).first().click();
			assert.ok((await page.locator('.sspa-history-point-details').innerText()).includes('SSPA local diagnostic one'));
			assert.equal(await page.locator('.sspa-history-point-details script').count(), 0, 'Diagnostic message markup is inert text');
			const measuredLink = page.locator('.sspa-history-measured-page a');
			await measuredLink.waitFor();
			const measuredUrl = await measuredLink.getAttribute('href');
			assert.equal(measuredUrl, siteUrl + '/', 'History opens the measured fixture page, not the current admin URL');
			assert.equal(await measuredLink.getAttribute('target'), '_blank');
			assert.match(await measuredLink.getAttribute('rel'), /noopener/);
			await page.locator('.sspa-history-point-details button').filter({hasText:'Open saved page profile'}).click();
			await page.locator('#sspa-adhoc-pop .sspa-profile-target').waitFor();
			assert.equal(await page.locator('#sspa-adhoc-pop .sspa-profile-target a').getAttribute('href'), measuredUrl);
			assert.match(await page.locator('#sspa-adhoc-pop .sspa-profile-target').innerText(), /GET.*diagnostic-pair/);
			assert.equal(await page.locator('#sspa-adhoc-pop .sspa-profile-target').evaluate(node => !!(node.compareDocumentPosition(document.querySelector('#sspa-adhoc-pop .sspa-adhoc-topbar')) & Node.DOCUMENT_POSITION_FOLLOWING)), true, 'Measured target precedes the action buttons');
			await page.locator('#sspa-adhoc-pop .sspa-adhoc-close').click();
			if (screenshot) {
				const parsed = path.parse(screenshot);
				await page.locator('[data-sspa-history-chart]').screenshot({path:path.join(parsed.dir, parsed.name + '-warnings' + parsed.ext)});
			}
		}
		assert.deepEqual(browserErrors, []);
		console.log('PASS: History workflow opens saved reports and synchronises exact comparisons, plotted values, filters and diagnostics with 320px/480px viewport fit');
	} finally {
		await browser.close();
	}
})().catch((error) => {
	console.error('FAIL:', error.stack || error.message);
	process.exitCode = 1;
});
