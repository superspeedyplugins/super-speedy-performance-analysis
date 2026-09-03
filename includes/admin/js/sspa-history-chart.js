(function ($) {
	'use strict';

	var echartsPromise = null;

	function loadECharts() {
		if (window.SSPAECharts) {
			return Promise.resolve(window.SSPAECharts);
		}
		if (echartsPromise) {
			return echartsPromise;
		}
		echartsPromise = new Promise(function (resolve, reject) {
			var script = document.createElement('script');
			script.src = sspa_admin.history_chart_asset;
			script.async = true;
			script.onload = function () {
				if (window.SSPAECharts) {
					resolve(window.SSPAECharts);
				} else {
					reject(new Error('ECharts did not initialise.'));
				}
			};
			script.onerror = function () {
				reject(new Error('The local chart library could not be loaded.'));
			};
			document.head.appendChild(script);
		});
		return echartsPromise;
	}

	function readDocument(card) {
		var node = card.querySelector('.sspa-history-chart-document');
		if (!node) {
			return null;
		}
		try {
			return JSON.parse(node.textContent);
		} catch (error) {
			return null;
		}
	}

	function unitValue(value, unit) {
		if (value === null || typeof value === 'undefined') {
			return 'Not measured';
		}
		if (unit === 'bytes') {
			var suffixes = ['B', 'KB', 'MB', 'GB'];
			var scaled = Number(value);
			var suffix = 0;
			while (scaled >= 1024 && suffix < suffixes.length - 1) {
				scaled /= 1024;
				suffix++;
			}
			return scaled.toFixed(1) + ' ' + suffixes[suffix];
		}
		return Number(value).toFixed(unit === 'count' ? 0 : 1) + (unit === 'ms' ? ' ms' : '');
	}

	function axisLabel(page) {
		if (page.method === 'GET' && page.variant === 'anon' && page.object_cache_mode === 'normal') {
			return page.label;
		}
		return page.label + '\n' + page.method + ' · ' + page.variant + ' · ' + page.object_cache_mode;
	}

	function point(pageLabel, point, offset) {
		return {
			value: [pageLabel, point.value],
			runId: point.run_id,
			sample: point.sample,
			responseCode: point.response_code,
			symbolOffset: [offset + (((point.run_id + (point.sample || 0)) % 5) - 2) * 2, 0]
		};
	}

	function optionFor(documentData, filter) {
		var pages = documentData.pages.filter(function (page) {
			return !filter || (page.label + ' ' + page.key).toLowerCase().indexOf(filter) !== -1;
		});
		var labels = pages.map(axisLabel);
		var previousPoints = [];
		var currentPoints = [];
		var previousMedians = [];
		var currentMedians = [];
		var failures = [];

		pages.forEach(function (page, pageIndex) {
			var label = labels[pageIndex];
			page.previous.points.forEach(function (item) {
				previousPoints.push(point(label, item, -9));
			});
			page.current.points.forEach(function (item) {
				currentPoints.push(point(label, item, 9));
			});
			previousMedians.push(page.previous.median);
			currentMedians.push({
				value: page.current.median,
				outputState: page.output_state,
				delta: page.delta
			});
			var values = page.previous.points.concat(page.current.points).map(function (item) { return Number(item.value); });
			var markerY = values.length ? Math.max.apply(null, values) * 1.08 : 1;
			if (page.previous.fault_count) {
				failures.push({value: [label, markerY], period: 'Previous setup', count: page.previous.fault_count});
			}
			if (page.current.fault_count) {
				failures.push({value: [label, markerY], period: 'Current setup', count: page.current.fault_count});
			}
		});

		var unit = documentData.metric.unit;
		var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		return {
			animation: !reduceMotion,
			aria: {
				enabled: true,
				decal: {show: true},
				description: 'Comparison of every retained ' + documentData.metric.label.toLowerCase() + ' measurement for the previous and current measured setups.'
			},
			color: ['#6b7280', '#2271b1', '#9ca3af', '#135e96', '#d63638'],
			legend: {top: 0},
			grid: {left: 72, right: 28, top: 54, bottom: labels.length > 5 ? 116 : 86},
			tooltip: {
				trigger: 'item',
				formatter: function (params) {
					var data = params.data || {};
					if (data.period) {
						return '<strong>' + data.period + '</strong><br>' + data.count + ' failed request' + (data.count === 1 ? '' : 's');
					}
					var value = Array.isArray(data.value) ? data.value[1] : data.value;
					var lines = ['<strong>' + params.seriesName + '</strong>', unitValue(value, unit)];
					if (data.runId) {
						lines.push('Analysis #' + data.runId + (data.sample ? ', sample ' + data.sample : ''));
					}
					if (data.outputState === 'changed') {
						lines.push('Output changed — review');
					}
					return lines.join('<br>');
				}
			},
			xAxis: {
				type: 'category',
				data: labels,
				axisLabel: {interval: 0, rotate: labels.length > 5 ? 28 : 0}
			},
			yAxis: {
				type: 'value',
				name: documentData.metric.label + (unit === 'ms' ? ' (ms)' : ''),
				min: 0,
				axisLabel: {formatter: function (value) { return unitValue(value, unit); }}
			},
			dataZoom: labels.length > 5 ? [
				{type: 'inside', xAxisIndex: 0, filterMode: 'filter'},
				{type: 'slider', xAxisIndex: 0, bottom: 14, height: 24, filterMode: 'filter'}
			] : [{type: 'inside', xAxisIndex: 0, filterMode: 'filter'}],
			series: [
				{name: 'Previous measurements', type: 'scatter', symbolSize: 9, data: previousPoints},
				{name: 'Current measurements', type: 'scatter', symbolSize: 9, data: currentPoints},
				{name: 'Previous median', type: 'line', symbol: 'diamond', symbolSize: 13, lineStyle: {type: 'dashed', width: 2}, connectNulls: false, data: previousMedians},
				{name: 'Current median', type: 'line', symbol: 'diamond', symbolSize: 13, lineStyle: {width: 3}, connectNulls: false, data: currentMedians},
				{name: 'Failed requests', type: 'scatter', symbol: 'triangle', symbolSize: 15, itemStyle: {color: '#d63638'}, data: failures}
			]
		};
	}

	function render(card, documentData) {
		var mount = card.querySelector('.sspa-history-chart');
		var status = card.querySelector('.sspa-history-chart-status');
		if (!mount || !documentData || !documentData.pages.length) {
			return;
		}
		status.textContent = 'Loading chart…';
		loadECharts().then(function (echarts) {
			var chart = mount.sspaChart || echarts.init(mount, null, {renderer: 'canvas'});
			mount.sspaChart = chart;
			card.sspaDocument = documentData;
			var filter = (card.querySelector('.sspa-history-page-filter').value || '').trim().toLowerCase();
			chart.setOption(optionFor(documentData, filter), true);
			status.textContent = documentData.metric.label + ' chart loaded.';
			if (!mount.sspaResizeObserver && window.ResizeObserver) {
				mount.sspaResizeObserver = new ResizeObserver(function () { chart.resize(); });
				mount.sspaResizeObserver.observe(mount);
			}
		}).catch(function (error) {
			status.textContent = error.message;
		});
	}

	function boot(root) {
		$(root || document).find('[data-sspa-history-chart]').each(function () {
			if (!this.sspaDocument) {
				render(this, readDocument(this));
			}
		});
	}

	$(document).on('sspa:tab-rendered', function (event, slug, panel) {
		if (slug === 'history') {
			boot(panel);
		}
	});

	$(document).on('input', '.sspa-history-page-filter', function () {
		var card = this.closest('[data-sspa-history-chart]');
		if (!card || !card.sspaDocument) {
			return;
		}
		var filter = this.value.trim().toLowerCase();
		$(card).find('.sspa-history-data-table tbody tr').each(function () {
			this.hidden = filter && (this.getAttribute('data-page-label') || '').indexOf(filter) === -1;
		});
		render(card, card.sspaDocument);
	});

	$(document).on('change', '.sspa-history-metric', function () {
		var select = $(this).prop('disabled', true);
		var card = this.closest('[data-sspa-history-chart]');
		var status = card.querySelector('.sspa-history-chart-status');
		var documentData = card.sspaDocument || readDocument(card);
		status.textContent = 'Loading ' + select.find(':selected').text().toLowerCase() + '…';
		$.post(ajaxurl, {
			action: 'sspa_history_series',
			nonce: sspa_admin.nonce,
			metric: select.val(),
			after_run_id: documentData.anchor_run_id,
			before_run_id: documentData.previous ? documentData.previous.run_ids[0] : 0
		}).done(function (response) {
			if (!response.success) {
				status.textContent = response.data || 'The metric could not be loaded.';
				return;
			}
			$(card).find('.sspa-history-chart-table').html(response.data.table);
			render(card, response.data.document);
		}).fail(function () {
			status.textContent = 'The metric could not be loaded.';
		}).always(function () {
			select.prop('disabled', false);
		});
	});

	$(function () { boot(document); });
})(jQuery);
