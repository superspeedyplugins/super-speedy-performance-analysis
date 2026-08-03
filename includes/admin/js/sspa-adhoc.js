// Admin-bar "Analyse this page" popover. Drives the run with sequential batch calls
// (same pattern as the settings page's floating monitor) and renders the stored result;
// re-clicking the admin-bar button re-opens the popover with the same result.
(function ($) {
	'use strict';

	var driving = false;

	function esc(str) {
		return $('<span>').text(str == null ? '' : String(str)).html();
	}

	function pageUrl() {
		return window.location.href.split('#')[0];
	}

	function pop() {
		var el = $('#sspa-adhoc-pop');
		if (el.length) {
			return el;
		}
		el = $(
			'<div id="sspa-adhoc-pop" style="display:none">' +
			'<div class="sspa-adhoc-head"><span class="sspa-adhoc-title">Page analysis</span>' +
			'<button type="button" class="sspa-adhoc-close" aria-label="' + esc(sspa_adhoc.i18n.close) + '">&times;</button></div>' +
			'<div class="sspa-adhoc-body"></div>' +
			'</div>'
		);
		$('body').append(el);
		return el;
	}

	function body(html) {
		pop().find('.sspa-adhoc-body').html(html);
	}

	function show() {
		pop().show();
	}

	function renderProgress(status) {
		var elapsed = status && status.elapsed_seconds ? Math.round(status.elapsed_seconds) + 's' : '';
		body(
			'<p class="sspa-adhoc-running"><span class="sspa-adhoc-spin"></span>' +
			esc(sspa_adhoc.i18n.running) + (elapsed ? ' <span class="sspa-adhoc-elapsed">' + esc(elapsed) + '</span>' : '') + '</p>' +
			'<p class="sspa-adhoc-note">' + esc(sspa_adhoc.i18n.running_detail) + '</p>'
		);
	}

	function renderError(msg) {
		body('<p class="sspa-adhoc-error">' + esc(msg) + '</p><p><button type="button" class="button sspa-adhoc-rerun">' + esc(sspa_adhoc.i18n.rerun) + '</button></p>');
	}

	function renderResult(d) {
		var html = '';
		if (d.blocked_by) {
			html += '<p class="sspa-adhoc-error">Blocked by ' + esc(d.blocked_by) + '</p>';
		}
		html += '<div class="sspa-adhoc-stats">' +
			stat(d.gen_ms !== null ? d.gen_ms + 'ms' : '?', 'Generation') +
			stat(d.sql_ms !== null ? d.sql_ms + 'ms / ' + d.sql_count : '?', 'SQL / queries') +
			stat(d.http_ms !== null ? d.http_ms + 'ms' : '0ms', 'HTTP') +
			stat(d.peak_mem || '?', 'Peak RAM') +
			'</div>';

		if (d.boot && d.boot.segments) {
			var segNames = {
				core_before_plugins: 'Core (before plugins)',
				plugin_includes: 'Plugin file loading',
				plugins_loaded_callbacks: 'Plugin boot (plugins_loaded)',
				theme_load_and_setup: 'Theme load + setup',
				init_callbacks: 'init callbacks',
				routing_and_query: 'Routing + main query'
			};
			html += '<h4>Where the PHP time went</h4><table class="sspa-adhoc-table">';
			Object.keys(d.boot.segments).forEach(function (k) {
				html += '<tr><td>' + esc(segNames[k] || k) + '</td><td>' + d.boot.segments[k].toFixed(1) + 'ms</td></tr>';
			});
			html += '</table>';
			var comps = d.boot.components || {};
			var keys = Object.keys(comps).filter(function (k) { return comps[k] >= 5; }).slice(0, 8);
			if (keys.length) {
				html += '<h4>Top plugins (load + hooks)</h4><table class="sspa-adhoc-table">';
				keys.forEach(function (k) {
					html += '<tr><td><code>' + esc(k) + '</code></td><td>' + comps[k].toFixed(1) + 'ms</td></tr>';
				});
				html += '</table>';
			}
		}
		if (d.queries && d.queries.length) {
			html += '<h4>Slowest queries</h4><table class="sspa-adhoc-table">';
			d.queries.forEach(function (q) {
				html += '<tr><td class="sspa-adhoc-sql"><code>' + esc(q.sql.length > 90 ? q.sql.slice(0, 90) + '…' : q.sql) + '</code><br><small>' + esc(q.component) + '</small></td><td>' + q.ms + 'ms</td></tr>';
			});
			html += '</table>';
		}
		html += '<p class="sspa-adhoc-actions">' +
			'<button type="button" class="button button-primary sspa-adhoc-rerun">' + esc(sspa_adhoc.i18n.rerun) + '</button> ' +
			'<a class="button" href="' + esc(sspa_adhoc.results_url) + '">' + esc(sspa_adhoc.i18n.full) + '</a>' +
			(d.created ? ' <span class="sspa-adhoc-note">' + esc(d.created) + ' · ' + esc(d.variant) + '</span>' : '') +
			'</p>';
		body(html);
	}

	function stat(value, label) {
		return '<div class="sspa-adhoc-stat"><span class="sspa-adhoc-stat-value">' + esc(value) + '</span><span class="sspa-adhoc-stat-label">' + esc(label) + '</span></div>';
	}

	function fetchResult(cb) {
		$.post(sspa_adhoc.ajaxurl, { action: 'sspa_adhoc_result', nonce: sspa_adhoc.nonce, url: pageUrl() }, cb)
			.fail(function () { renderError('Request failed.'); });
	}

	function start() {
		renderProgress(null);
		$.post(sspa_adhoc.ajaxurl, { action: 'sspa_adhoc_start', nonce: sspa_adhoc.nonce, url: pageUrl() }, function (resp) {
			if (!resp.success) {
				renderError(resp.data || 'Could not start.');
				return;
			}
			drive(resp.data.run_id);
		}).fail(function () { renderError('Request failed.'); });
	}

	function drive(runId) {
		if (driving) {
			return;
		}
		driving = true;
		var failures = 0;
		function step() {
			$.post(sspa_adhoc.ajaxurl, { action: 'sspa_process_batch', nonce: sspa_adhoc.nonce, run_id: runId }, function (resp) {
				failures = 0;
				var s = resp.success ? resp.data : null;
				if (s && (s.status === 'crawling' || s.status === 'analysing')) {
					renderProgress(s);
					window.setTimeout(step, 400);
					return;
				}
				driving = false;
				if (s && s.status === 'done') {
					fetchResult(function (r) {
						if (r.success && r.data.found) {
							renderResult(r.data);
						} else {
							renderError(sspa_adhoc.i18n.failed);
						}
					});
				} else {
					renderError(sspa_adhoc.i18n.failed);
				}
			}).fail(function () {
				if (++failures < 4) {
					window.setTimeout(step, 2000);
					return;
				}
				driving = false;
				renderError('Request failed.');
			});
		}
		renderProgress(null);
		step();
	}

	function open() {
		show();
		body('<p class="sspa-adhoc-note">Loading…</p>');
		fetchResult(function (resp) {
			if (!resp.success) {
				renderError(resp.data || 'Request failed.');
				return;
			}
			if (resp.data.running) {
				drive(resp.data.running);
			} else if (resp.data.found) {
				renderResult(resp.data);
			} else {
				start();
			}
		});
	}

	$(document).on('click', '#wp-admin-bar-sspa-adhoc > a', function (e) {
		e.preventDefault();
		var el = pop();
		if (el.is(':visible')) {
			el.hide();
			return;
		}
		open();
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-close', function () {
		pop().hide();
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-rerun', function () {
		start();
	});
})(jQuery);
