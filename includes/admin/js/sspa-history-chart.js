(function ($) {
	'use strict';

	var echartsPromise = null;
	var strings = sspa_history_chart;
	var sprintf = wp.i18n.sprintf;
	function escapeText(text) { return $('<span>').text(text).html(); }

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
		var status = card.querySelector('.sspa-history-chart-status');
		if (!node) {
			if (status) {
				status.textContent = 'The chart data could not be read: its data document is missing.';
			}
			return null;
		}
		try {
			return JSON.parse(node.textContent);
		} catch (error) {
			if (status) {
				status.textContent = 'The chart data could not be read: ' + error.message;
			}
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
		var diagnostics = point.evidence && point.evidence.php_diagnostics;
		var hasDiagnostics = diagnostics && diagnostics.events && diagnostics.events.length;
		var hasError = hasDiagnostics && diagnostics.events.some(function (event) { return event.severity === 'error'; });
		return {
			value: [pageLabel, point.value],
			runId: point.run_id,
			sample: point.sample,
			responseCode: point.response_code,
			savedPoint: point,
			symbol: hasDiagnostics ? 'triangle' : 'circle',
			symbolRotate: hasError ? 180 : 0,
			symbolSize: hasDiagnostics ? 14 : 9,
			itemStyle: hasError ? {color: '#d63638', borderColor: '#d63638', borderWidth: 3} : (hasDiagnostics ? {borderColor: '#996800', borderWidth: 3} : {}),
			symbolOffset: [offset + (((point.run_id + (point.sample || 0)) % 5) - 2) * 2, 0]
		};
	}

	function faultSummary(faults) {
		var labels = {
			blocked: 'blocked',
			transport_error: 'transport error',
			http_error: 'HTTP error',
			missing: 'missing measurement'
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
		var failures = [];

		pages.forEach(function (page, pageIndex) {
			var label = labels[pageIndex];
			page.previous.points.forEach(function (item) {
				previousPoints.push(Object.assign(point(label, item, -9), {period: 'previous'}));
			});
			page.current.points.forEach(function (item) {
				currentPoints.push(Object.assign(point(label, item, 9), {period: 'recent'}));
			});
			var values = page.previous.points.concat(page.current.points).map(function (item) { return Number(item.value); });
			var markerY = values.length ? Math.max.apply(null, values) * 1.08 : 1;
			['previous', 'current'].forEach(function (side) {
				page[side].faults.forEach(function (fault, index) {
					failures.push({value: [label, markerY], period: side === 'previous' ? 'previous' : 'recent', summary: faultSummary([fault]), savedPoint: fault, symbolOffset: [(side === 'previous' ? -12 : 12) + index * 3, 0]});
				});
			});
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
			color: ['#6b7280', '#2271b1', '#d63638'],
			legend: {top: 0},
			grid: {left: 72, right: 28, top: 54, bottom: labels.length > 5 ? 116 : 86},
			tooltip: {
				trigger: 'item',
				formatter: function (params) {
					var data = params.data || {};
					var heading = '<strong>' + escapeText(data.value[0] + ' (' + data.period + ')') + '</strong>';
					if (data.summary) {
						return heading + '<br>' + data.summary;
					}
					var value = Array.isArray(data.value) ? data.value[1] : data.value;
					var lines = [heading, unitValue(value, unit)];
					if (data.runId) {
						lines.push('Analysis #' + data.runId + (data.sample ? ', sample ' + data.sample : ''));
					}
					if (data.savedPoint) {
						var php = data.savedPoint.evidence && data.savedPoint.evidence.php_diagnostics;
						var aggregate = data.savedPoint.evidence && data.savedPoint.evidence.source === 'per_run_median';
						lines.push(escapeText(aggregate ? strings.aggregate : (php && php.coverage !== 'unavailable' ? sprintf(strings.tooltip_count, php.count) : strings.tooltip_unavailable)));
						lines.push(escapeText(strings.select_point));
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
				{name: 'Previous measurements', type: 'scatter', symbolSize: 9, itemStyle: {color: '#6b7280'}, data: previousPoints},
				{name: 'Recent measurements', type: 'scatter', symbolSize: 9, itemStyle: {color: '#2271b1'}, data: currentPoints},
				{name: 'Errors', type: 'scatter', symbol: 'triangle', symbolSize: 15, itemStyle: {color: '#d63638'}, data: failures}
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
			card.querySelector('.sspa-history-evidence-source').textContent = documentData.metric.description;
			var filter = (card.querySelector('.sspa-history-page-filter').value || '').trim().toLowerCase();
			$(card).find('.sspa-history-data-table tbody tr').each(function () {
				this.hidden = !!filter && (this.getAttribute('data-page-label') || '').indexOf(filter) === -1;
			});
			chart.setOption(optionFor(documentData, filter), true);
			chart.off('click');
			chart.on('click', function (event) {
				if (event.data && event.data.savedPoint) inspectPoint(card, event.data.savedPoint);
			});
			status.textContent = documentData.metric.label + ' chart loaded.';
			if (!mount.sspaResizeObserver && window.ResizeObserver) {
				mount.sspaResizeObserver = new ResizeObserver(function () { chart.resize(); });
				mount.sspaResizeObserver.observe(mount);
			}
		}).catch(function (error) {
			status.textContent = error.message;
		});
	}

	function inspectPoint(card, item) {
		var target = $(card).find('.sspa-history-point-details').empty().prop('hidden', false);
		$('<h4>').text(item.sample ? sprintf(strings.sample_heading, item.run_id, item.sample) : sprintf(strings.summary_heading, item.run_id)).appendTo(target);
		var evidence = item.evidence || {};
		$('<p>').text(sprintf(strings.evidence_state, strings.sources[evidence.source] || strings.retained_measurement, strings.states[item.state] || strings.measured)).appendTo(target);
		if (item.response_code !== null && typeof item.response_code !== 'undefined') $('<p>').text(sprintf(strings.http_status, item.response_code)).appendTo(target);
		if (evidence.error_message || evidence.error) $('<p>').text(evidence.error_message || evidence.error).appendTo(target);
		if (evidence.fatal) $('<p>').text(sprintf(strings.fatal, evidence.fatal.component || strings.unknown_component)).appendTo(target);
		if (evidence.reactions) $('<p>').text(sprintf(strings.reactions, evidence.reactions)).appendTo(target);
		var diagnostics = evidence.php_diagnostics;
		if (evidence.source === 'per_run_median') {
			$('<p>').text(strings.aggregate).appendTo(target);
		} else if (!diagnostics || diagnostics.coverage === 'unavailable') {
			$('<p>').text(diagnostics && diagnostics.reason === 'existing_error_handler'
				? strings.existing_handler : strings.unavailable).appendTo(target);
		} else {
			$('<p>').text(diagnostics.coverage === 'partial' ? strings.partial : strings.observed).appendTo(target);
			var entries = diagnostics.events || [];
			$('<p>').text(sprintf(strings.counts, diagnostics.count, entries.length)).appendTo(target);
			if (diagnostics.truncated) $('<p>').text(strings.truncated).appendTo(target);
			var list = $('<ul>').appendTo(target);
			entries.forEach(function (entry) { $('<li>').text((strings.severities[entry.severity] || entry.type) + ': ' + entry.message + (entry.component ? ' (' + entry.component + ')' : '') + (entry.file ? ' ' + entry.file + ':' + entry.line : '')).appendTo(list); });
		}
		if (item.profile_id) {
			$('<button type="button" class="button">').text(strings.open_profile).appendTo(target).on('click', function () {
				if (!window.sspaPanel || !window.sspaPanel.openProfile) {
					$('<p role="alert">').text(strings.profile_unavailable).appendTo(target);
					return;
				}
				window.sspaPanel.openProfile(item.profile_id);
			});
			var measuredPage = $('<p class="sspa-history-measured-page" aria-live="polite">').text(strings.loading_measured_page).appendTo(target);
			$.post(ajaxurl, {action: 'sspa_profile_target', nonce: sspa_admin.nonce, profile_id: item.profile_id}).done(function (response) {
				if (!response.success) {
					measuredPage.text(response.data || strings.measured_page_failed);
					return;
				}
				measuredPage.empty();
				if (response.data.url) {
					$('<a target="_blank" rel="noopener noreferrer">').attr('href', response.data.url).text(strings.open_measured_page).appendTo(measuredPage);
				} else {
					measuredPage.text(strings.measured_page_action);
				}
			}).fail(function () { measuredPage.text(strings.measured_page_failed); });
			$('<p class="description">').text(strings.representative_capture).appendTo(target);
		}
	}

	$(document).on('click', '.sspa-history-inspect-point', function () {
		var card = this.closest('[data-sspa-history-chart]');
		try { inspectPoint(card, JSON.parse(this.getAttribute('data-point'))); }
		catch (error) { $(card).find('.sspa-history-point-details').prop('hidden', false).text(sprintf(strings.unreadable, error.message)); }
	});

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
			before_run_id: documentData.previous ? documentData.previous.run_ids[0] : 0,
			selection_mode: documentData.selection_mode || 'setup'
		}).done(function (response) {
			if (!response.success) {
				select.val(documentData.metric.key);
				status.textContent = response.data || 'The metric could not be loaded.';
				return;
			}
			$(card).find('.sspa-history-chart-table').html(response.data.table);
			render(card, response.data.document);
		}).fail(function () {
			select.val(documentData.metric.key);
			status.textContent = 'The metric could not be loaded.';
		}).always(function () {
			select.prop('disabled', false);
		});
	});

	$(function () { boot(document); });
})(jQuery);
