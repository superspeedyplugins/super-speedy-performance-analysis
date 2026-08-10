// The profile panel: the popover shell, the run drivers, and the interactions on the markup
// the server sends.
//
// It renders NOTHING of the result itself any more. The panel body is one PHP partial
// (SSPA_Profile_Panel) shared with the Pages tab - two renderers over one dataset is exactly
// how the two views drifted into showing different subsets of the same capture.
//
// Opened from the admin bar for the current URL, or from anywhere via
// window.sspaPanel.openProfile(id) - which is what a Pages tab row click calls.
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
		var logo = sspa_adhoc.logo_url
			? '<img class="sspa-adhoc-logo" src="' + esc(sspa_adhoc.logo_url) + '" alt="Super Speedy Plugins">'
			: '<span class="sspa-adhoc-logo-text">Super Speedy Plugins</span>';
		el = $(
			'<div id="sspa-adhoc-pop" style="display:none">' +
			'<div class="sspa-adhoc-head"><span class="sspa-adhoc-title">Super Speedy Performance Analysis' +
			'<span class="sspa-adhoc-version">v' + esc(sspa_adhoc.version) + '</span></span>' +
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

	// Which page the panel is currently showing, so a re-run or a finished impact sweep knows
	// what to reload. One of the two is always set.
	var current = { profileId: 0, url: '' };

	function renderProgress(status, message) {
		var elapsed = status && status.elapsed_seconds ? Math.round(status.elapsed_seconds) + 's' : '';
		var detail = sspa_adhoc.i18n.running_detail;
		if (status && status.total) {
			detail = status.done + ' / ' + status.total + ' measurements';
			if (status.current) {
				detail += ' · ' + status.current;
			}
		}
		body(
			'<p class="sspa-adhoc-running"><span class="sspa-adhoc-spin"></span>' +
			esc(message || sspa_adhoc.i18n.running) + (elapsed ? ' <span class="sspa-adhoc-elapsed">' + esc(elapsed) + '</span>' : '') + '</p>' +
			'<p class="sspa-adhoc-note">' + esc(detail) + '</p>'
		);
	}

	function renderError(msg) {
		body('<p class="sspa-adhoc-error">' + esc(msg) + '</p><p><button type="button" class="sspa-adhoc-btn sspa-adhoc-rerun">' + esc(sspa_adhoc.i18n.rerun) + '</button></p>');
	}

	// ---- loading the panel ----

	function loadProfile(profileId, done) {
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_profile_panel',
			nonce: sspa_adhoc.nonce,
			profile_id: profileId
		}, function (resp) {
			if (!resp.success) {
				renderError(resp.data || 'Could not load that page profile.');
				return;
			}
			current.profileId = resp.data.profile_id;
			body(resp.data.html);
			if (done) { done(); }
		}).fail(function () { renderError('Request failed.'); });
	}

	function fetchByUrl(cb, fresh) {
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_adhoc_result',
			nonce: sspa_adhoc.nonce,
			url: current.url || pageUrl(),
			fresh: fresh ? 1 : 0
		}, cb).fail(function () { renderError('Request failed.'); });
	}

	// Whatever the panel is showing, show it again with fresh data.
	function reload() {
		if (current.url) {
			fetchByUrl(function (resp) {
				if (resp.success && resp.data.found) {
					current.profileId = resp.data.profile_id;
					body(resp.data.html);
				} else {
					renderError(sspa_adhoc.i18n.failed);
				}
			}, false);
			return;
		}
		if (current.profileId) {
			loadProfile(current.profileId);
		}
	}

	function start(url) {
		current.url = url || current.url || pageUrl();
		renderProgress(null);
		$.post(sspa_adhoc.ajaxurl, { action: 'sspa_adhoc_start', nonce: sspa_adhoc.nonce, url: current.url }, function (resp) {
			if (!resp.success) {
				renderError(resp.data || 'Could not start.');
				return;
			}
			drive(resp.data.run_id, function () {
				fetchByUrl(function (r) {
					if (r.success && r.data.found) {
						current.profileId = r.data.profile_id;
						body(r.data.html);
					} else {
						renderError(sspa_adhoc.i18n.failed);
					}
				}, true);
			});
		}).fail(function () { renderError('Request failed.'); });
	}

	/**
	 * Drive a run to completion with sequential batch calls, exactly as the settings page's
	 * floating monitor does, then hand back to the caller to show the result.
	 */
	function drive(runId, onDone, message) {
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
					renderProgress(s, message);
					window.setTimeout(step, 400);
					return;
				}
				driving = false;
				if (s && s.status === 'done') {
					onDone();
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
		renderProgress(null, message);
		step();
	}

	function openUrl(url) {
		current = { profileId: 0, url: url || pageUrl() };
		show();
		body('<p class="sspa-adhoc-note">' + esc(sspa_adhoc.i18n.loading) + '</p>');
		fetchByUrl(function (resp) {
			if (!resp.success) {
				renderError(resp.data || 'Request failed.');
				return;
			}
			if (resp.data.running) {
				drive(resp.data.running, function () {
					fetchByUrl(function (r) {
						if (r.success && r.data.found) {
							current.profileId = r.data.profile_id;
							body(r.data.html);
						} else {
							renderError(sspa_adhoc.i18n.failed);
						}
					}, true);
				});
			} else if (resp.data.found) {
				current.profileId = resp.data.profile_id;
				body(resp.data.html);
			} else {
				start();
			}
		}, false);
	}

	function openProfile(profileId) {
		current = { profileId: profileId, url: '' };
		show();
		body('<p class="sspa-adhoc-note">' + esc(sspa_adhoc.i18n.loading) + '</p>');
		loadProfile(profileId);
	}

	// The panel's public surface: the Pages tab opens it by profile id.
	window.sspaPanel = { openProfile: openProfile, openUrl: openUrl };

	$(document).on('click', '#wp-admin-bar-sspa-adhoc > a', function (e) {
		e.preventDefault();
		var el = pop();
		if (el.is(':visible')) {
			el.hide();
			return;
		}
		openUrl(pageUrl());
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-close', function () {
		pop().hide();
	});

	$(document).on('keydown', function (e) {
		if ('Escape' === e.key && $('#sspa-adhoc-pop').is(':visible')) {
			pop().hide();
		}
	});

	// Re-run: profile the page this panel is showing, which is not necessarily the page the
	// browser is on - the button carries its own URL when the panel was opened from a row.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-rerun', function () {
		start($(this).data('url') || current.url || pageUrl());
	});

	// ---- interactions on the server-rendered panel ----

	// Untimed-remainder rows with no phase-scoped profiler data: jump to the By-function
	// table, which is where the sampling profiler names that time.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-tobyfn', function (e) {
		e.stopPropagation();
		var target = $('#sspa-adhoc-byfn');
		if (!target.length) {
			return;
		}
		target[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
		target.addClass('sspa-adhoc-flash');
		window.setTimeout(function () { target.removeClass('sspa-adhoc-flash'); }, 1600);
	});

	// Expand/collapse a request phase into its per-component contributions.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-phase', function () {
		var row = $(this);
		var key = row.attr('data-phase');
		var table = row.closest('table');
		var subs = table.find('.sspa-adhoc-sub[data-parent="' + key + '"]');
		var opening = subs.first().is(':hidden');
		subs.toggle(opening);
		if (!opening) {
			// Collapsing a phase also collapses any function view opened inside it.
			table.find('.sspa-adhoc-fnsub[data-fnparent="' + key + '"]').hide();
		}
		row.toggleClass('is-open', opening);
	});

	// An untimed-remainder row with phase-scoped profiler data expands in place to the
	// functions sampled during that phase.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-untimed', function (e) {
		e.stopPropagation();
		var row = $(this);
		var key = row.attr('data-fns');
		var subs = row.closest('table').find('.sspa-adhoc-fnsub[data-fnparent="' + key + '"]');
		subs.toggle(subs.first().is(':hidden'));
	});

	// Attribution mode: both tables are already in the page, so this is a swap, not a fetch.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-attrib-btn', function () {
		var btn = $(this);
		var mode = btn.data('mode');
		var wrap = btn.closest('#sspa-adhoc-attrib');
		wrap.find('.sspa-adhoc-attrib-btn').removeClass('sspa-adhoc-btn-primary').attr('aria-pressed', 'false');
		btn.addClass('sspa-adhoc-btn-primary').attr('aria-pressed', 'true');
		wrap.find('.sspa-adhoc-attrib-table').hide().filter('[data-mode="' + mode + '"]').show();
		var desc = wrap.find('.sspa-adhoc-attrib-desc');
		desc.text(desc.data(mode) || '');
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

	// ---- plugin impact, scoped to the page in the panel ----

	var plan = null; // the last plan fetched: {plugins, oc_capable, seconds_per_job, ...}

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-measure', function () {
		var btn = $(this).prop('disabled', true);
		var target = $('#sspa-adhoc-impact .sspa-adhoc-plan');
		target.html('<p class="sspa-adhoc-note">' + esc(sspa_adhoc.i18n.loading) + '</p>');
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_impact_plan',
			nonce: sspa_adhoc.nonce,
			profile_id: btn.data('profile-id')
		}, function (resp) {
			btn.prop('disabled', false);
			if (!resp.success) {
				target.html('<p class="sspa-adhoc-error">' + esc(resp.data || 'Could not work out what to measure.') + '</p>');
				return;
			}
			plan = resp.data;
			target.html(planHtml(plan));
			updateEstimate();
		}).fail(function () {
			btn.prop('disabled', false);
			target.html('<p class="sspa-adhoc-error">Request failed.</p>');
		});
	});

	function planHtml(data) {
		var i18n = sspa_adhoc.i18n;
		var html = '<div class="sspa-adhoc-planbox">';
		html += '<h4>' + esc(i18n.plan_title) + '</h4>';
		html += '<p class="sspa-adhoc-note">' + esc(i18n.plan_hint) + '</p>';
		html += '<p class="sspa-adhoc-note">' + esc(i18n.plan_blamed) + '</p>';
		html += '<p class="sspa-adhoc-planactions">' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-pick" data-pick="blamed">' + esc(i18n.select_blamed) + '</button> ' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-pick" data-pick="all">' + esc(i18n.select_all) + '</button> ' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-pick" data-pick="none">' + esc(i18n.select_none) + '</button></p>';
		html += '<ul class="sspa-adhoc-planlist">';
		data.plugins.forEach(function (p) {
			var blamed = p.cost_ms > 0;
			html += '<li><label><input type="checkbox" class="sspa-adhoc-pluginpick" value="' + esc(p.slug) + '"' +
				(blamed ? ' checked' : '') + ' data-blamed="' + (blamed ? '1' : '0') + '"> <code>' + esc(p.slug) + '</code> ' +
				'<small>' + esc(blamed ? p.cost_ms.toFixed(1) + 'ms attributed here' : i18n.no_cost) + '</small></label></li>';
		});
		html += '</ul>';
		if (data.oc_capable) {
			html += '<p><label><input type="checkbox" id="sspa-adhoc-cachemodes"> ' + esc(i18n.cache_modes) + '</label></p>';
		}
		html += '<p class="sspa-adhoc-estimate"></p>';
		html += '<p><button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-adhoc-measure-start">' + esc(i18n.start_measuring) + '</button> ' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-measure-cancel">' + esc(i18n.cancel) + '</button></p>';
		html += '</div>';
		return html;
	}

	function selected() {
		return $('#sspa-adhoc-pop .sspa-adhoc-pluginpick:checked').map(function () {
			return this.value;
		}).get();
	}

	function duration(seconds) {
		seconds = Math.round(seconds);
		if (seconds < 90) {
			return sspa_adhoc.i18n.seconds.replace('%s', Math.max(5, Math.round(seconds / 5) * 5));
		}
		return sspa_adhoc.i18n.minutes.replace('%s', Math.max(1, Math.round(seconds / 60)));
	}

	/**
	 * What the sweep will cost, in the same unit the running-analysis panel counts in.
	 *
	 * Mirrors sweep_block_jobs(): one baseline per cache mode opens the page block and
	 * another is taken every SWEEP_REBASELINE_EVERY plugin cells, because server drift over a
	 * long block would otherwise masquerade as plugin cost.
	 */
	function measurements(cells, modes, rebaselineEvery) {
		if (cells < 1) {
			return 0;
		}
		var baselineGroups = 1 + Math.floor((cells - 1) / rebaselineEvery);
		return (cells + baselineGroups) * modes;
	}

	function updateEstimate() {
		if (!plan) {
			return;
		}
		var i18n = sspa_adhoc.i18n;
		var el = $('#sspa-adhoc-pop .sspa-adhoc-estimate');
		var count = selected().length;
		if (!count) {
			el.text(i18n.estimate_none);
			$('#sspa-adhoc-pop .sspa-adhoc-measure-start').prop('disabled', true);
			return;
		}
		$('#sspa-adhoc-pop .sspa-adhoc-measure-start').prop('disabled', false);
		var jobs = measurements(count, 1, plan.rebaseline_every);
		var text = i18n.estimate
			.replace('%1$s', count)
			.replace('%2$s', jobs)
			.replace('%3$s', duration(jobs * plan.seconds_per_job));
		// Phase 2 only runs for the plugins that showed something, so its cost is a ceiling,
		// not a total - said as one, because a total that grows mid-run reads as a bug.
		if (plan.oc_capable && $('#sspa-adhoc-cachemodes').is(':checked')) {
			var extra = measurements(count, 2, plan.rebaseline_every);
			text += ' ' + i18n.estimate_phase2
				.replace('%1$s', extra)
				.replace('%2$s', duration(extra * plan.seconds_per_job));
		}
		el.text(text);
	}

	$(document).on('change', '#sspa-adhoc-pop .sspa-adhoc-pluginpick, #sspa-adhoc-cachemodes', updateEstimate);

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-pick', function () {
		var pick = $(this).data('pick');
		$('#sspa-adhoc-pop .sspa-adhoc-pluginpick').each(function () {
			var box = $(this);
			box.prop('checked', pick === 'all' || (pick === 'blamed' && '1' === box.attr('data-blamed')));
		});
		updateEstimate();
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-measure-cancel', function () {
		$('#sspa-adhoc-impact .sspa-adhoc-plan').empty();
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-measure-start', function () {
		var slugs = selected();
		if (!slugs.length || !plan) {
			return;
		}
		var payload = {
			action: 'sspa_start_run',
			nonce: sspa_adhoc.nonce,
			type: 'deep',
			url: plan.url,
			cache_modes: (plan.oc_capable && $('#sspa-adhoc-cachemodes').is(':checked')) ? 1 : 0,
			'suspects[]': slugs
		};
		$(this).prop('disabled', true);
		$.post(sspa_adhoc.ajaxurl, payload, function (resp) {
			if (!resp.success) {
				$('#sspa-adhoc-pop .sspa-adhoc-estimate').text(resp.data || 'Could not start measuring.');
				$('#sspa-adhoc-pop .sspa-adhoc-measure-start').prop('disabled', false);
				return;
			}
			drive(resp.data.run_id, reload, 'Measuring plugin impact on this page…');
		}).fail(function () {
			$('#sspa-adhoc-pop .sspa-adhoc-estimate').text('Could not start measuring.');
			$('#sspa-adhoc-pop .sspa-adhoc-measure-start').prop('disabled', false);
		});
	});
})(jQuery);
