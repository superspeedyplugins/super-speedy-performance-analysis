jQuery(function () {
	var hash = window.location.hash.replace('#', '');
	sspa_click_tab(hash || 'overview');

	// A finished run reloads the page, so sspa_autospot must be consumed exactly once -
	// left in the URL it would re-arm on every reload and loop the analysis forever.
	var autospot = window.location.search.indexOf('sspa_autospot=1') !== -1;
	if (autospot) {
		sspa_strip_autospot_param();
	}

	// Resume the floating monitor if a run is already active when the page loads.
	var active = parseInt(jQuery('#sspa-runner').data('active-run'), 10);
	if (active) {
		sspa_drive_run(active);
	} else if (autospot) {
		// Arrived from the plugin-toggle notice: run a quick spot profile of key pages.
		sspa_start_typed_run({ 'page_keys[]': ['home', 'shop', 'baseline'] }, jQuery('#sspa-run-analysis'));
	}
});

function sspa_strip_autospot_param() {
	if (!window.history || !window.history.replaceState) {
		return;
	}
	var search = window.location.search
		.replace(/([?&])sspa_autospot=1(&|$)/, function (m, before, after) {
			return after ? before : '';
		})
		.replace(/[?&]$/, '');
	window.history.replaceState(null, null, window.location.pathname + search + window.location.hash);
}

// Re-check the Tools tab in place - no page reload, so the active tab is kept.
jQuery(document).on('click', '#sspa-tools-recheck', function () {
	var btn = jQuery(this).prop('disabled', true);
	jQuery.post(ajaxurl, { action: 'sspa_tools_recheck', nonce: sspa_admin.nonce }, function (resp) {
		if (resp.success) {
			jQuery('#sspa_main div.tab-contents[data-tab="tools"]').html(resp.data.html);
		} else {
			btn.prop('disabled', false);
			alert(resp.data || 'Re-check failed.');
		}
	}).fail(function () {
		btn.prop('disabled', false);
		alert('Re-check failed.');
	});
});

// Replace an orphaned Query Monitor db.php (QM deactivated, drop-in left behind).
jQuery(document).on('click', '#sspa-replace-stale-dropin', function () {
	var btn = jQuery(this).prop('disabled', true).text('Replacing…');
	jQuery.post(ajaxurl, { action: 'sspa_replace_stale_dropin', nonce: sspa_admin.nonce }, function (resp) {
		if (resp.success) {
			window.location.reload();
		} else {
			btn.prop('disabled', false);
			alert(resp.data || 'Could not replace the drop-in.');
		}
	}).fail(function () {
		btn.prop('disabled', false);
		alert('Could not replace the drop-in.');
	});
});

jQuery(document).on('click', '#sspa_main .nav-tab-wrapper .nav-tab', function (e) {
	var slug = jQuery(this).data('tab');
	window.history.pushState(null, null, '#' + slug);
	sspa_click_tab(slug);
	e.preventDefault();
	e.stopPropagation();
});

function sspa_click_tab(slug) {
	if (!jQuery('#sspa_main .nav-tab-wrapper .nav-tab[data-tab="' + slug + '"]').length) {
		slug = 'overview';
	}
	jQuery('#sspa_main .nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
	jQuery('#sspa_main .nav-tab-wrapper .nav-tab[data-tab="' + slug + '"]').addClass('nav-tab-active').focus();
	jQuery('#sspa_main div.tab-contents').css('display', 'none');
	jQuery('#sspa_main div.tab-contents[data-tab="' + slug + '"]').css('display', 'block');
}

// ---- Pages drill-down ----

jQuery(document).on('click', '.sspa-page-row', function () {
	var row = jQuery(this);
	var existing = row.next('.sspa-detail-row');
	if (existing.length) {
		existing.toggle();
		return;
	}
	var profileId = row.data('profile-id');
	var cols = row.children('td').length;
	var detail = jQuery('<tr class="sspa-detail-row"><td colspan="' + cols + '">Loading&hellip;</td></tr>');
	row.after(detail);
	jQuery.post(ajaxurl, { action: 'sspa_page_detail', nonce: sspa_admin.nonce, profile_id: profileId }, function (resp) {
		if (!resp.success) {
			detail.children('td').text(resp.data || 'No detail available.');
			return;
		}
		var d = resp.data;
		var html = '<div class="sspa-detail"><h4>By component</h4><table class="widefat"><thead><tr><th>Component</th><th>Queries</th><th>SQL ms</th><th>Rows</th><th>Slowest ms</th><th>HTTP ms</th></tr></thead><tbody>';
		d.components.forEach(function (c) {
			html += '<tr><td><code>' + sspa_esc(c.component) + '</code></td><td>' + c.query_count + '</td><td>' + c.sql_ms.toFixed(1) + '</td><td>' + c.rows + '</td><td>' + c.slowest_ms.toFixed(1) + '</td><td>' + c.http_ms.toFixed(1) + '</td></tr>';
		});
		html += '</tbody></table><h4>Slowest queries</h4><table class="widefat"><thead><tr><th>ms</th><th>Rows</th><th>Component</th><th>Query</th><th>Caller</th></tr></thead><tbody>';
		d.queries.forEach(function (q) {
			html += '<tr><td>' + q.ms.toFixed(1) + '</td><td>' + (q.rows === null ? '-' : q.rows) + '</td><td><code>' + sspa_esc(q.component) + '</code></td><td class="sspa-sql"><code>' + sspa_esc(q.sql) + '</code></td><td>' + sspa_esc(q.caller) + '</td></tr>';
		});
		html += '</tbody></table>';
		if (d.http.length) {
			html += '<h4>HTTP calls</h4><table class="widefat"><thead><tr><th>ms</th><th>URL</th><th>Code</th><th>Component</th></tr></thead><tbody>';
			d.http.forEach(function (h) {
				html += '<tr><td>' + (h.ms === null ? '-' : h.ms) + '</td><td>' + sspa_esc(h.url) + '</td><td>' + sspa_esc(String(h.code)) + '</td><td><code>' + sspa_esc(h.component) + '</code></td></tr>';
			});
			html += '</tbody></table>';
		}
		if (d.boot) {
			// The PHP floor decomposed: request phases, then per-plugin PHP cost
			// (file include + timed hook callbacks), then the slowest single callbacks.
			html += '<h4>Where the PHP time went</h4>';
			var segNames = {
				core_before_plugins: 'WordPress core (before plugins)',
				plugin_includes: 'Plugin file loading',
				plugins_loaded_callbacks: 'plugins_loaded callbacks (plugin boot)',
				theme_load_and_setup: 'Theme load + setup',
				init_callbacks: 'init callbacks',
				post_init_boot: 'Post-init boot (widgets, REST)',
				routing_and_query: 'Routing + main query',
				render_and_output: 'Template render + output'
			};
			html += '<table class="widefat"><thead><tr><th>Request phase</th><th>ms</th></tr></thead><tbody>';
			Object.keys(d.boot.segments || {}).forEach(function (k) {
				html += '<tr><td>' + sspa_esc(segNames[k] || k) + '</td><td>' + d.boot.segments[k].toFixed(1) + '</td></tr>';
			});
			html += '</tbody></table>';
			var comps = d.boot.components || {};
			var compKeys = Object.keys(comps).filter(function (k) { return comps[k] >= 1; }).slice(0, 25);
			if (compKeys.length) {
				html += '<h4>PHP cost per plugin (load + hook callbacks)</h4><table class="widefat"><thead><tr><th>Plugin</th><th>Load ms</th><th>Hook ms</th><th>Total ms</th></tr></thead><tbody>';
				compKeys.forEach(function (k) {
					var inc = (d.boot.includes && d.boot.includes[k]) ? d.boot.includes[k] : 0;
					html += '<tr><td><code>' + sspa_esc(k) + '</code></td><td>' + inc.toFixed(1) + '</td><td>' + (comps[k] - inc).toFixed(1) + '</td><td>' + comps[k].toFixed(1) + '</td></tr>';
				});
				html += '</tbody></table>';
			}
			if (d.boot.top_callbacks && d.boot.top_callbacks.length) {
				html += '<h4>Slowest hook callbacks</h4><table class="widefat"><thead><tr><th>ms</th><th>Hook</th><th>Callback</th><th>Component</th></tr></thead><tbody>';
				d.boot.top_callbacks.forEach(function (c) {
					html += '<tr><td>' + c.ms.toFixed(1) + '</td><td><code>' + sspa_esc(c.hook) + '</code></td><td>' + sspa_esc(c.label) + '</td><td><code>' + sspa_esc(c.component) + '</code></td></tr>';
				});
				html += '</tbody></table>';
			}
			if (d.profile && d.profile.functions && d.profile.functions.length) {
				html += '<h4>By function (Excimer sampling - ' + d.profile.samples + ' samples at ' + d.profile.period_ms + 'ms)</h4><table class="widefat"><thead><tr><th>Incl ms</th><th>Self ms</th><th>Function</th><th>Component</th></tr></thead><tbody>';
				d.profile.functions.forEach(function (f) {
					var by = '';
					var byKeys = f.by ? Object.keys(f.by) : [];
					if (byKeys.length > 1 || (byKeys.length === 1 && byKeys[0] !== f.component)) {
						by = '<br><small>driven by: ' + byKeys.map(function (c) { return sspa_esc(c) + ' ' + f.by[c].toFixed(0) + 'ms'; }).join(' · ') + '</small>';
					}
					html += '<tr><td>' + f.incl_ms.toFixed(0) + '</td><td>' + f.self_ms.toFixed(0) + '</td><td><code>' + sspa_esc(f.fn) + '</code>' + (f.file ? ' <small>' + sspa_esc(f.file + (f.line ? ':' + f.line : '')) + '</small>' : '') + by + '</td><td><code>' + sspa_esc(f.component) + '</code></td></tr>';
				});
				html += '</tbody></table>';
			}
			var rb = d.boot.render;
			if (rb && (rb.timed_ms > 0 || (rb.untimed_ms !== null && rb.untimed_ms > 0))) {
				html += '<h4>Render breakdown (wp_head/wp_footer/content filters, shortcodes, widgets)</h4><table class="widefat"><thead><tr><th>ms</th><th>Unit</th><th>Kind</th><th>Component</th></tr></thead><tbody>';
				(rb.top || []).forEach(function (t) {
					html += '<tr><td>' + t.ms.toFixed(1) + '</td><td>' + sspa_esc(t.label) + '</td><td><code>' + sspa_esc(t.hook) + '</code></td><td><code>' + sspa_esc(t.component) + '</code></td></tr>';
				});
				if (rb.untimed_ms !== null && rb.untimed_ms > 0) {
					html += '<tr><td>' + rb.untimed_ms.toFixed(1) + '</td><td>Theme templates + direct output (untimed remainder)</td><td>-</td><td><code>theme</code></td></tr>';
				}
				html += '</tbody></table>';
			}
		}
		html += '</div>';
		detail.children('td').html(html);
	});
});

function sspa_esc(str) {
	return jQuery('<span>').text(str == null ? '' : String(str)).html();
}

// ---- Plugins drill-down: per-page x cache-mode measured impacts ----

jQuery(document).on('click', '.sspa-impact-details', function (e) {
	e.preventDefault();
	var link = jQuery(this);
	var row = link.closest('tr');
	var existing = row.next('.sspa-impact-detail-row');
	if (existing.length) {
		existing.toggle();
		return;
	}
	var cols = row.children('td').length;
	var detail = jQuery('<tr class="sspa-impact-detail-row"><td colspan="' + cols + '">Loading&hellip;</td></tr>');
	row.after(detail);
	jQuery.post(ajaxurl, { action: 'sspa_plugin_detail', nonce: sspa_admin.nonce, plugin: link.data('plugin') }, function (resp) {
		if (!resp.success) {
			detail.children('td').text(resp.data || 'No detail available.');
			return;
		}
		var rows = resp.data.rows;
		var modeOrder = ['normal', 'disabled', 'prime', 'warm'];
		var modeLabels = { normal: 'Standard (cache warm)', disabled: 'No object cache', prime: 'Cache priming', warm: 'Warm cache' };
		var modes = modeOrder.filter(function (m) {
			return rows.some(function (r) { return r.object_cache_mode === m; });
		});
		var byPage = {};
		rows.forEach(function (r) {
			(byPage[r.page_key] = byPage[r.page_key] || {})[r.object_cache_mode] = r;
		});
		var html = '<div class="sspa-detail"><h4>Measured impact of <code>' + sspa_esc(link.data('plugin')) + '</code> per page</h4>';
		html += '<table class="widefat"><thead><tr><th>Page</th>';
		modes.forEach(function (m) {
			html += '<th>' + modeLabels[m] + '</th>';
		});
		html += '</tr></thead><tbody>';
		Object.keys(byPage).forEach(function (page) {
			html += '<tr><td><code>' + sspa_esc(page) + '</code></td>';
			modes.forEach(function (m) {
				var r = byPage[page][m];
				html += '<td>' + (r ? sspa_impact_cell(r) : '-') + '</td>';
			});
			html += '</tr>';
		});
		html += '</tbody></table>';
		html += '<p class="description">"adds" = the plugin costs that much page-generation time; "saves" = the page is SLOWER without it (the plugin is speeding it up); "within noise" = no measurable difference on that page.</p></div>';
		detail.children('td').html(html);
	});
});

function sspa_impact_cell(r) {
	if (r.confidence !== 'measured') {
		return '<span class="sspa-impact-noise">within &plusmn;' + Math.round(r.noise_floor_ms) + 'ms noise</span>';
	}
	var d = parseFloat(r.delta_ttfb_ms);
	var cls = d < 0 ? 'sspa-impact-saves' : 'sspa-impact-adds';
	var label = (d < 0 ? 'saves ' : 'adds ') + Math.abs(Math.round(d)) + 'ms';
	var sql = Math.round(parseFloat(r.delta_sql_ms));
	var q = parseInt(r.delta_queries, 10);
	var sub = 'SQL ' + (sql >= 0 ? '+' : '−') + Math.abs(sql) + 'ms · ' + (q >= 0 ? '+' : '−') + Math.abs(q) + ' queries';
	return '<strong class="' + cls + '">' + label + '</strong><br><small>' + sub + '</small>';
}

// ---- Prune stored blobs ----

jQuery(document).on('click', '#sspa-prune-blobs', function () {
	var keep = jQuery(this).data('keep');
	if (!confirm('Delete detailed per-query data for all but the last ' + keep + ' runs?\n\nSummary metrics, findings and history are always kept. If sharing is enabled, every affected run is first saved to the durable local submission queue.')) {
		return;
	}
	jQuery.post(ajaxurl, { action: 'sspa_prune_blobs', nonce: sspa_admin.nonce }, function (resp) {
		if (resp.success) {
			alert('Done. Detailed data now uses ' + resp.data.human + '.');
			window.location.reload();
		}
	});
});

// ---- Share tab ----

jQuery(document).on('change', '#sspa-share-optin', function () {
	var optin = jQuery(this).is(':checked') ? 1 : 0;
	jQuery.post(ajaxurl, { action: 'sspa_share_optin', nonce: sspa_admin.nonce, optin: optin }, function () {
		jQuery('#sspa-submit-now').prop('disabled', !optin);
	});
});

jQuery(document).on('click', '#sspa-preview-payload', function () {
	var pre = jQuery('#sspa-payload-preview');
	if (pre.is(':visible')) {
		pre.hide();
		return;
	}
	pre.text('Loading exact queued payload…').show();
	jQuery.post(ajaxurl, { action: 'sspa_payload_preview', nonce: sspa_admin.nonce }, function (resp) {
		pre.text(resp.success ? resp.data.payload : (resp.data || 'Could not build the payload.'));
	});
});

jQuery(document).on('click', '#sspa-submit-now', function () {
	var btn = jQuery(this).prop('disabled', true);
	jQuery.post(ajaxurl, { action: 'sspa_submit_now', nonce: sspa_admin.nonce }, function (resp) {
		alert(resp.success ? 'Queued locally. Delivery runs in the background and retries automatically.' : (resp.data || 'Could not queue the submission.'));
		window.location.reload();
	}).fail(function () {
		alert('Could not queue the submission.');
		btn.prop('disabled', false);
	});
});

// ---- Run Analysis ----

jQuery(document).on('click', '#sspa-run-analysis', function () {
	sspa_start_typed_run({}, jQuery(this));
});

jQuery(document).on('click', '#sspa-run-deep', function () {
	sspa_start_typed_run({ type: 'deep' }, jQuery(this));
});

jQuery(document).on('click', '#sspa-run-cache', function () {
	sspa_start_typed_run({ type: 'cache_impact' }, jQuery(this));
});

jQuery(document).on('click', '.sspa-measure-plugin', function () {
	var plugin = jQuery(this).data('plugin');
	if (!confirm('Measure "' + plugin + '" on every profiled page with the plugin disabled for the test requests only? Visitors are unaffected.')) {
		return;
	}
	sspa_start_typed_run({ type: 'deep', 'suspects[]': plugin }, jQuery(this));
});

function sspa_start_typed_run(extra, btn) {
	btn.prop('disabled', true);
	var payload = jQuery.extend({
		action: 'sspa_start_run',
		nonce: sspa_admin.nonce,
		swap_dropin: jQuery('#sspa-swap-dropin').is(':checked') ? 1 : 0,
		include_writes: jQuery('#sspa-include-writes').is(':checked') ? 1 : 0
	}, extra);
	jQuery.post(ajaxurl, payload, function (resp) {
		if (!resp.success) {
			alert(resp.data || 'Could not start the analysis.');
			btn.prop('disabled', false);
			return;
		}
		// No tab switch, no reload: the floating monitor shows progress wherever you are.
		sspa_drive_run(resp.data.run_id);
	}).fail(function () {
		alert('Could not start the analysis (request failed).');
		btn.prop('disabled', false);
	});
}

jQuery(document).on('click', '#sspa-cancel-run, #sspa-runner-cancel', function () {
	if (!confirm('Cancel the running analysis?')) {
		return;
	}
	jQuery.post(ajaxurl, { action: 'sspa_cancel_run', nonce: sspa_admin.nonce }, function () {
		window.location.reload();
	});
});

// ---- Floating run monitor ----

jQuery(document).on('click', '#sspa-runner .sspa-runner-toggle, #sspa-runner .sspa-runner-head', function (e) {
	if (jQuery(e.target).is('#sspa-runner-cancel')) {
		return;
	}
	var runner = jQuery('#sspa-runner');
	runner.toggleClass('sspa-runner-min');
	try {
		window.localStorage.setItem('sspa_runner_min', runner.hasClass('sspa-runner-min') ? '1' : '0');
	} catch (err) { /* private mode */ }
	e.preventDefault();
});

function sspa_runner_show() {
	var runner = jQuery('#sspa-runner');
	try {
		if (window.localStorage.getItem('sspa_runner_min') === '1') {
			runner.addClass('sspa-runner-min');
		}
	} catch (err) { /* private mode */ }
	runner.show();
}

function sspa_fmt_duration(seconds) {
	if (seconds === null || seconds === undefined || isNaN(seconds)) {
		return null;
	}
	seconds = Math.max(0, Math.round(seconds));
	var h = Math.floor(seconds / 3600);
	var m = Math.floor((seconds % 3600) / 60);
	if (h > 0) {
		return h + 'h ' + m + 'm';
	}
	if (m > 0) {
		return m + 'm';
	}
	return seconds + 's';
}

function sspa_runner_update(s) {
	var runner = jQuery('#sspa-runner');
	var pct = s.total ? Math.min(100, Math.round((s.done / s.total) * 100)) : 0;
	runner.find('.sspa-progress-fill').css('width', pct + '%');
	runner.find('.sspa-runner-counts').text(s.done + ' / ' + s.total + ' measurements (' + pct + '%)');
	runner.find('.sspa-runner-current').text(s.current ? 'Now testing: ' + s.current : (s.status === 'analysing' ? 'Analysing results…' : ''));
	var eta = sspa_fmt_duration(s.eta_seconds);
	var elapsed = sspa_fmt_duration(s.elapsed_seconds);
	var bits = [];
	if (elapsed) {
		bits.push('Elapsed ' + elapsed);
	}
	if (eta) {
		// Phase 1 is the fast screen; phase 2 length depends on what it finds.
		bits.push('~' + eta + (s.run_type === 'deep' && s.phase === 1 ? ' left in screening' : ' left'));
	}
	runner.find('.sspa-runner-eta').text(bits.join(' · '));
	runner.find('.sspa-runner-mini-summary').text(pct + '%' + (eta ? ' · ~' + eta + ' left' : ''));
	var title = { deep: 'Deep analysis running', cache_impact: 'Cache impact analysis running' }[s.run_type] || 'Analysis running';
	if (s.run_type === 'deep' && s.phase) {
		title += s.phase === 1 ? ' - phase 1/2: screening all plugins' : ' - phase 2/2: confirming impacted plugins';
	}
	runner.find('.sspa-runner-title').text(title);
}

function sspa_runner_finish(status) {
	var runner = jQuery('#sspa-runner').removeClass('sspa-runner-min');
	var label = status === 'done' ? 'Analysis complete ✓ loading results…' : 'Analysis ' + status + ' - reloading…';
	runner.find('.sspa-runner-title').text(label);
	runner.find('.sspa-runner-mini-summary').text('');
	runner.find('.sspa-progress-fill').css('width', '100%');
	runner.find('.sspa-runner-current, .sspa-runner-eta, .sspa-runner-actions').hide();
	setTimeout(function () {
		window.location.reload();
	}, 1500);
}

// The browser drives batches sequentially; WP-Cron is the backup for headless progress,
// and the hourly janitor re-kicks a run whose driver disappeared.
function sspa_drive_run(runId) {
	sspa_runner_show();
	jQuery('#sspa-run-analysis, #sspa-run-deep, #sspa-run-cache, .sspa-measure-plugin').prop('disabled', true);
	jQuery('#sspa-cancel-run').show();

	var failures = 0;
	function step() {
		jQuery.post(ajaxurl, { action: 'sspa_process_batch', nonce: sspa_admin.nonce, run_id: runId }, function (resp) {
			failures = 0;
			if (!resp.success || !resp.data) {
				sspa_runner_finish('finished');
				return;
			}
			var s = resp.data;
			sspa_runner_update(s);
			if (s.status === 'crawling' || s.status === 'analysing') {
				setTimeout(step, 400);
			} else {
				sspa_runner_finish(s.status);
			}
		}).fail(function () {
			// Transient network/server hiccups must not kill an hours-long run.
			failures++;
			jQuery('#sspa-runner .sspa-runner-current').text(failures > 1 ? 'Connection hiccup, retrying… (' + failures + ')' : '');
			setTimeout(step, Math.min(30000, 3000 * failures));
		});
	}
	step();
}

// --- Tools tab: installation steps + copy to clipboard ---------------------
// Steps are inert text. Nothing here installs, edits or restarts anything; the user runs
// the commands themselves, or sends them to their host.
jQuery(function ($) {
	$(document).on('click', '.sspa-steps-toggle', function () {
		var $btn = $(this);
		var $row = $('#' + $btn.data('target'));
		var open = $row.is(':visible');
		$row.toggle(!open);
		$btn.attr('aria-expanded', open ? 'false' : 'true')
			.text(open ? sspa_tools_i18n.show : sspa_tools_i18n.hide);
	});

	$(document).on('click', '.sspa-copy', function () {
		var $btn = $(this);
		var text = $btn.closest('.sspa-code-block').find('code').text();
		var done = function () {
			var original = $btn.text();
			$btn.text(sspa_tools_i18n.copied);
			setTimeout(function () { $btn.text(original); }, 1500);
		};
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(done, function () {});
			return;
		}
		// Fallback for non-secure contexts, where the clipboard API is unavailable.
		var $tmp = $('<textarea>').val(text).css({position: 'fixed', opacity: 0}).appendTo('body');
		$tmp[0].select();
		try { document.execCommand('copy'); done(); } catch (e) {}
		$tmp.remove();
	});
});
