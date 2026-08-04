// Admin-bar "Analyse this page" popover. Drives the run with sequential batch calls
// (same pattern as the settings page's floating monitor) and renders the stored result;
// re-clicking the admin-bar button re-opens the popover with the same result.
(function ($) {
	'use strict';

	var driving = false;

	function esc(str) {
		return $('<span>').text(str == null ? '' : String(str)).html();
	}

	// Attribute context needs quotes escaped too - SQL can legitimately contain them.
	function escAttr(str) {
		return esc(str).replace(/"/g, '&quot;');
	}

	// "5m ago" from a server-computed age, so nobody reconciles server vs local clocks.
	function agoText(seconds) {
		if (seconds == null || isNaN(seconds)) {
			return '';
		}
		if (seconds < 45) {
			return sspa_adhoc.i18n.just_now;
		}
		var v;
		if (seconds < 3600) {
			v = Math.round(seconds / 60) + 'm';
		} else if (seconds < 86400) {
			v = Math.round(seconds / 3600) + 'h';
		} else {
			v = Math.round(seconds / 86400) + 'd';
		}
		return sspa_adhoc.i18n.ago.replace('%s', v);
	}

	function pageUrl() {
		return window.location.href.split('#')[0];
	}

	function pop() {
		var el = $('#sspa-adhoc-pop');
		if (el.length) {
			return el;
		}
		var logo = sspa_adhoc.logo_url
			? '<img class="sspa-adhoc-logo" src="' + esc(sspa_adhoc.logo_url) + '" alt="Super Speedy Plugins">'
			: '<span class="sspa-adhoc-logo-text">Super Speedy Plugins</span>';
		el = $(
			'<div id="sspa-adhoc-pop" style="display:none">' +
			'<div class="sspa-adhoc-head"><span class="sspa-adhoc-title">&#9889; Page analysis</span>' +
			logo +
			'<button type="button" class="sspa-adhoc-close" aria-label="' + esc(sspa_adhoc.i18n.close) + '">&times;</button></div>' +
			'<div class="sspa-adhoc-body"></div>' +
			// The screenshot line: anyone sharing this panel shares where it came from.
			'<div class="sspa-adhoc-foot">Powered by <strong>Super Speedy Performance Analysis</strong> &middot; free from <a href="https://www.superspeedyplugins.com/" target="_blank" rel="noopener">superspeedyplugins.com</a></div>' +
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
		body('<p class="sspa-adhoc-error">' + esc(msg) + '</p><p><button type="button" class="sspa-adhoc-btn sspa-adhoc-rerun">' + esc(sspa_adhoc.i18n.rerun) + '</button></p>');
	}

	function renderResult(d, cached) {
		var left = '';
		var right = '';
		var full = '';
		// Re-run and provenance live at the TOP: whether you are looking at a stored
		// result must be unmissable, and re-running must not require scrolling.
		var bar = '<div class="sspa-adhoc-topbar sspa-adhoc-span">' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-adhoc-rerun">' + esc(sspa_adhoc.i18n.rerun) + '</button>' +
			'<span class="sspa-adhoc-badge ' + (cached ? 'is-cached' : 'is-fresh') + '">' + esc(cached ? sspa_adhoc.i18n.cached : sspa_adhoc.i18n.fresh) + '</span>' +
			(d.created ? '<span class="sspa-adhoc-note"><strong>' + esc(cached ? agoText(d.age_seconds) : sspa_adhoc.i18n.just_now) + '</strong> · ' + esc(d.created) + ' · ' + esc('admin' === d.variant ? sspa_adhoc.i18n.as_admin : sspa_adhoc.i18n.as_visitor) + '</span>' : '') +
			'<a class="sspa-adhoc-open" href="' + esc(sspa_adhoc.results_url) + '">' + esc(sspa_adhoc.i18n.full) + ' &rarr;</a>' +
			'</div>';
		if (d.blocked_by) {
			full += '<p class="sspa-adhoc-error sspa-adhoc-span">Blocked by ' + esc(d.blocked_by) + '</p>';
		}
		left += '<div class="sspa-adhoc-stats">' +
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
				post_init_boot: 'Post-init boot (widgets, REST)',
				routing_and_query: 'Routing + main query',
				render_and_output: 'Template render + output'
			};
			left += '<h4>Where the PHP time went <small>Click a phase to expand it</small></h4><table class="sspa-adhoc-table">';
			Object.keys(d.boot.segments).forEach(function (k) {
				var detail = phaseDetail(d.boot, k);
				var names = detail ? Object.keys(detail).filter(function (c) { return detail[c] >= 0.5; }) : [];
				if (!names.length) {
					left += '<tr><td class="sspa-adhoc-phase-plain">' + esc(segNames[k] || k) + '</td><td>' + d.boot.segments[k].toFixed(1) + 'ms</td></tr>';
					return;
				}
				names.sort(function (a, b) { return detail[b] - detail[a]; });
				left += '<tr class="sspa-adhoc-phase" data-phase="' + escAttr(k) + '"><td><span class="sspa-adhoc-caret">&#9656;</span>' + esc(segNames[k] || k) + '</td><td>' + d.boot.segments[k].toFixed(1) + 'ms</td></tr>';
				var shown = 0;
				names.slice(0, 12).forEach(function (c) {
					left += '<tr class="sspa-adhoc-sub" data-parent="' + escAttr(k) + '" style="display:none"><td><code>' + esc(c) + '</code></td><td>' + detail[c].toFixed(1) + 'ms</td></tr>';
					shown += detail[c];
				});
				// Phases are wall-clock; the detail is only the work our wrappers saw.
				var gap = d.boot.segments[k] - shown;
				if (gap > 1) {
					var gapLabel = k === 'render_and_output' ? 'theme templates + direct output (untimed)' : 'untimed / core framework';
					left += '<tr class="sspa-adhoc-sub" data-parent="' + escAttr(k) + '" style="display:none"><td><small>' + gapLabel + '</small></td><td><small>' + gap.toFixed(1) + 'ms</small></td></tr>';
				}
			});
			left += '</table>';

			var comps = d.boot.components || {};
			var keys = Object.keys(comps).filter(function (k) { return comps[k] >= 5; }).slice(0, 10);
			if (keys.length) {
				right += '<h4>Top plugins (load + hooks)</h4><table class="sspa-adhoc-table">';
				keys.forEach(function (k) {
					right += '<tr><td><code>' + esc(k) + '</code></td><td>' + comps[k].toFixed(1) + 'ms</td></tr>';
				});
				right += '</table>';
			}

			var r = d.boot.render;
			if (r && (r.timed_ms > 0 || r.untimed_ms !== null)) {
				right += '<h4>Render breakdown</h4><table class="sspa-adhoc-table">';
				(r.top || []).slice(0, 6).forEach(function (t) {
					right += '<tr><td>' + esc(t.label) + ' <small>' + esc(t.hook) + ' · ' + esc(t.component) + '</small></td><td>' + t.ms.toFixed(1) + 'ms</td></tr>';
				});
				if (r.untimed_ms !== null && r.untimed_ms > 0) {
					right += '<tr><td>Theme templates + direct output <small>(untimed remainder)</small></td><td>' + r.untimed_ms.toFixed(1) + 'ms</td></tr>';
				}
				right += '</table>';
			}
		}
		if (d.queries && d.queries.length) {
			full += '<div class="sspa-adhoc-span"><h4>Slowest queries <small>' + esc(sspa_adhoc.i18n.copy_hint) + '</small></h4><table class="sspa-adhoc-table">';
			d.queries.forEach(function (q) {
				full += '<tr class="sspa-adhoc-qrow" data-sql="' + escAttr(q.sql) + '" title="' + escAttr(sspa_adhoc.i18n.copy_hint) + '">' +
					'<td class="sspa-adhoc-sql"><code>' + esc(q.sql.length > 160 ? q.sql.slice(0, 160) + '…' : q.sql) + '</code><br><small>' + esc(q.component) + '</small></td><td>' + q.ms + 'ms</td></tr>';
			});
			full += '</table></div>';
		}
		body('<div class="sspa-adhoc-grid">' + bar + '<div>' + left + '</div><div>' + right + '</div>' + full + '</div>');
	}

	function stat(value, label) {
		return '<div class="sspa-adhoc-stat"><span class="sspa-adhoc-stat-value">' + esc(value) + '</span><span class="sspa-adhoc-stat-label">' + esc(label) + '</span></div>';
	}

	// What each request phase can expand into, from data the capture already stores.
	function phaseDetail(boot, key) {
		function fromHooks(names) {
			var out = {};
			names.forEach(function (n) {
				var h = boot.hooks && boot.hooks[n];
				if (h && h.components) {
					Object.keys(h.components).forEach(function (c) {
						out[c] = (out[c] || 0) + h.components[c];
					});
				}
			});
			return out;
		}
		switch (key) {
			case 'plugin_includes':
				return boot.includes || null;
			case 'plugins_loaded_callbacks':
				return fromHooks(['plugins_loaded']);
			case 'theme_load_and_setup':
				return fromHooks(['after_setup_theme']);
			case 'init_callbacks':
				return fromHooks(['init', 'widgets_init']);
			case 'post_init_boot':
				return fromHooks(['wp_loaded', 'rest_api_init']);
			case 'render_and_output':
				return (boot.render && boot.render.components) ? boot.render.components : null;
		}
		return null;
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
							renderResult(r.data, false);
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
				renderResult(resp.data, true);
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

	// Expand/collapse a request phase into its per-component contributions.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-phase', function () {
		var row = $(this);
		var key = row.attr('data-phase');
		var subs = row.closest('table').find('.sspa-adhoc-sub[data-parent="' + key + '"]');
		var opening = subs.first().is(':hidden');
		subs.toggle(opening);
		row.toggleClass('is-open', opening);
	});

	// Click a query row: copy the FULL query (the cell only shows the start) and confirm
	// with a small toast that rises and fades where you clicked.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-qrow', function (e) {
		var sql = $(this).attr('data-sql') || '';
		if (!sql) {
			return;
		}
		function toast() {
			var t = $('<span class="sspa-adhoc-toast">' + esc(sspa_adhoc.i18n.copied) + '</span>');
			$('body').append(t);
			t.css({ left: e.pageX + 'px', top: (e.pageY - 10) + 'px' });
			window.setTimeout(function () { t.remove(); }, 1300);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(sql).then(toast, function () {});
			return;
		}
		// Non-secure contexts (plain-http dev sites): the clipboard API is unavailable.
		var tmp = $('<textarea>').val(sql).css({ position: 'fixed', opacity: 0 }).appendTo('body');
		tmp[0].select();
		try { document.execCommand('copy'); toast(); } catch (err) { /* nothing to do */ }
		tmp.remove();
	});
})(jQuery);
