/* Pure chart option adapter extracted from History completion 70f0276. Shared page-shaped points; AJAX identity is supplied by its own adapter. */
(function ($) {
'use strict';
var sprintf = wp.i18n.sprintf;
function escapeText(text) { return $('<span>').text(text).html(); }
	function unitValue(value, unit) {
		if (value === null || typeof value === 'undefined') {
			return wp.i18n.__("Not measured", "super-speedy-performance-analysis");
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

	function faultSummary(faults) {
		var labels = {
			blocked: wp.i18n.__("blocked", "super-speedy-performance-analysis"),
			transport_error: wp.i18n.__("transport error", "super-speedy-performance-analysis"),
			http_error: wp.i18n.__("HTTP error", "super-speedy-performance-analysis"),
			missing: wp.i18n.__("missing measurement", "super-speedy-performance-analysis")
		};
		var counts = {};
		faults.forEach(function (fault) {
			counts[fault.state] = (counts[fault.state] || 0) + 1;
		});
		return Object.keys(labels).filter(function (state) {
			return counts[state];
		}).map(function (state) {
			return counts[state] + ' ' + labels[state];
		}).join(', ');
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
				failures.push({value: [label, markerY], period: wp.i18n.__("Previous setup", "super-speedy-performance-analysis"), summary: faultSummary(page.previous.faults), symbolOffset: [-12, 0]});
			}
			if (page.current.fault_count) {
				failures.push({value: [label, markerY], period: wp.i18n.__("Current setup", "super-speedy-performance-analysis"), summary: faultSummary(page.current.faults), symbolOffset: [12, 0]});
			}
		});

		var unit = documentData.metric.unit;
		var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
		return {
			animation: !reduceMotion,
			aria: {
				enabled: true,
				decal: {show: true},
				description: sprintf(/* translators: %s: measurement metric name. */ wp.i18n.__("Comparison of every retained %s measurement for the previous and current measured setups.", "super-speedy-performance-analysis"), documentData.metric.label.toLowerCase())
			},
			color: ['#6b7280', '#2271b1', '#9ca3af', '#135e96', '#d63638'],
			legend: {top: 0},
			grid: {left: 72, right: 28, top: 54, bottom: labels.length > 5 ? 116 : 86},
			tooltip: {
				trigger: 'item',
				formatter: function (params) {
					var data = params.data || {};
					if (data.period) {
						return '<strong>' + escapeText(data.period) + '</strong><br>' + escapeText(data.summary);
					}
					var value = Array.isArray(data.value) ? data.value[1] : data.value;
					var lines = ['<strong>' + escapeText(params.seriesName) + '</strong>', escapeText(unitValue(value, unit))];
					if (data.runId) {
						lines.push(escapeText(data.sample ? sprintf(/* translators: 1: analysis ID, 2: sample number. */ wp.i18n.__("Analysis #%1$d, sample %2$d", "super-speedy-performance-analysis"), data.runId, data.sample) : sprintf(/* translators: %d: analysis ID. */ wp.i18n.__("Analysis #%d", "super-speedy-performance-analysis"), data.runId)));
					}
					if (data.delta && data.delta.absolute !== null) {
						var change = (data.delta.absolute < 0 ? '−' : '+') + unitValue(Math.abs(data.delta.absolute), unit);
						if (data.delta.percent !== null) {
							change += ' (' + (data.delta.percent < 0 ? '−' : '+') + Math.abs(data.delta.percent).toFixed(1) + '%)';
						}
						lines.push(escapeText(documentData.metric.change_label + ': ' + change));
					}
					if (data.outputState === 'changed') {
						lines.push(escapeText(wp.i18n.__("Output changed — review", "super-speedy-performance-analysis")));
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
				{name: wp.i18n.__("Previous measurements", "super-speedy-performance-analysis"), type: 'scatter', symbolSize: 9, data: previousPoints},
				{name: wp.i18n.__("Current measurements", "super-speedy-performance-analysis"), type: 'scatter', symbolSize: 9, data: currentPoints},
				{name: wp.i18n.__("Previous median", "super-speedy-performance-analysis"), type: 'line', symbol: 'diamond', symbolSize: 13, lineStyle: {type: 'dashed', width: 2}, connectNulls: false, data: previousMedians},
				{name: wp.i18n.__("Current median", "super-speedy-performance-analysis"), type: 'line', symbol: 'diamond', symbolSize: 13, lineStyle: {width: 3}, connectNulls: false, data: currentMedians},
				{name: wp.i18n.__("Failed requests", "super-speedy-performance-analysis"), type: 'scatter', symbol: 'triangle', symbolSize: 15, itemStyle: {color: '#d63638'}, data: failures}
			]
		};
	}

window.SSPAMeasurementChart = {optionFor: optionFor};
})(jQuery);
