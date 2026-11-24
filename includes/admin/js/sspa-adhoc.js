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

	// Escaped text with each http(s) URL made a link, so a message that names a screen by its
	// URL (Enable profiler in Tools: ...) can be followed. Sentence punctuation stays outside.
	function linkify(str) {
		// esc() leaves quotes alone, so a quote also ends the URL: it can never close the href.
		return esc(str).replace(/https?:\/\/[^\s<"']*[^\s<"'.,;:!?)]/g, function (url) {
			return '<a href="' + url + '">' + url + '</a>';
		});
	}

	function pageUrl() {
		return window.location.href.split('#')[0];
	}

	function pop() {
		var el = $('#sspa-adhoc-pop');
		if (el.length) {
			return el;
		}
		var logo = '';
		var foot = '';
		// SSPA-PRO-START
		logo = sspa_adhoc.logo_url
			? '<img class="sspa-adhoc-logo" src="' + esc(sspa_adhoc.logo_url) + '" alt="Super Speedy Plugins">'
			: '<span class="sspa-adhoc-logo-text">Super Speedy Plugins</span>';
		// The screenshot line: anyone sharing this panel shares where it came from.
		foot = '<div class="sspa-adhoc-foot">' + wp.i18n.sprintf(/* translators: 1: linked product name, 2: linked publisher website. */ wp.i18n.__('Powered by %1$s · free from %2$s', 'super-speedy-performance-analysis'), '<strong>Super Speedy Performance Analysis</strong>', '<a href="https://www.superspeedyplugins.com/" target="_blank" rel="noopener">superspeedyplugins.com</a>') + '</div>';
		// SSPA-PRO-END
		el = $(
			'<div id="sspa-adhoc-pop" role="dialog" aria-labelledby="sspa-adhoc-title" style="display:none">' +
			'<div class="sspa-adhoc-head"><span class="sspa-adhoc-title" id="sspa-adhoc-title">Super Speedy Performance Analysis' +
			'<span class="sspa-adhoc-version">v' + esc(sspa_adhoc.version) + '</span></span>' +
			logo +
			'<button type="button" class="sspa-adhoc-close" aria-label="' + esc(sspa_adhoc.i18n.close) + '">&times;</button></div>' +
			'<div class="sspa-adhoc-status" role="status" aria-live="polite" aria-atomic="true"></div>' +
			'<div class="sspa-adhoc-body"></div>' +
			foot +
			'</div>'
		);
		$('body').append(el);
		return el;
	}

	function body(html) {
		var panel = pop();
		var content = panel.find('.sspa-adhoc-body').html(html);
		if (sspa_adhoc.mail_warning) content.prepend($('<p class="sspa-adhoc-error sspa-adhoc-span">').text(sspa_adhoc.mail_warning));
		var running = content.find('.sspa-adhoc-running').first().clone();
		running.find('.sspa-adhoc-elapsed').remove();
		var message = content.find('.sspa-adhoc-error').first().text().trim()
			|| running.text().trim()
			|| (content.find('.sspa-profile-target').length ? sspa_adhoc.i18n.profile_ready : '')
			|| content.children('.sspa-adhoc-note').first().text().trim();
		var status = panel.find('.sspa-adhoc-status');
		if (status.text() !== message) { status.text(message); }
	}

	// Whatever opened the panel gets focus back when it closes, so a keyboard user is not
	// dropped at the top of the document after Escape. The opener is the focused element at
	// the moment the panel appears, or, for a mouse click on something that cannot hold focus,
	// the last thing pressed.
	var opener = null;
	var lastPressed = null;
	$(document).on('mousedown', function (e) {
		lastPressed = e.target;
	});
	function show() {
		var el = pop();
		if (!el.is(':visible')) {
			var active = document.activeElement;
			opener = (active && active !== document.body && !el[0].contains(active)) ? active : lastPressed;
			el.show().find('.sspa-adhoc-close').trigger('focus');
		}
	}
	function hide() {
		pop().hide();
		var target = opener;
		opener = null;
		if (target && document.contains(target)) {
			if (!target.hasAttribute('tabindex') && !/^(a|button|input|select|textarea)$/i.test(target.tagName)) {
				target.setAttribute('tabindex', '-1');
			}
			target.focus();
		}
	}

	// Which page the panel is currently showing, so a re-run or a finished impact sweep knows
	// what to reload. One of the two is always set.
	var current = { profileId: 0, url: '' };

	function renderProgress(status, message) {
		var elapsed = status && status.elapsed_seconds ? Math.round(status.elapsed_seconds) + 's' : '';
		var detail = sspa_adhoc.i18n.running_detail;
		if (status && status.total) {
			detail = status.done + ' / ' + status.total + ' measurements' + (status.measurement_label ? ' (' + status.measurement_label + ')' : '');
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
		body('<p class="sspa-adhoc-error">' + linkify(msg) + '</p><p><button type="button" class="sspa-adhoc-btn sspa-adhoc-rerun">' + esc(sspa_adhoc.i18n.rerun) + '</button></p>');
	}

	// ---- loading the panel ----

	function loadProfile(profileId, done) {
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_profile_panel',
			nonce: sspa_adhoc.nonce,
			profile_id: profileId,
			// Offers belong to the Performance Analysis screens only.
			pa_screen: document.getElementById('sspa_main') ? 1 : 0
		}, function (resp) {
			if (!resp.success) {
				renderError(SSPAFeedback.response(resp, wp.i18n.__('Could not load that page profile.', 'super-speedy-performance-analysis')));
				return;
			}
			current.profileId = resp.data.profile_id;
			body(resp.data.html);
			if (done) { done(); }
		}).fail(function (xhr, textStatus) { renderError(SSPAFeedback.xhr(xhr, wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'), textStatus)); });
	}

	function fetchByUrl(cb, fresh) {
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_adhoc_result',
			nonce: sspa_adhoc.nonce,
			url: current.url || pageUrl(),
			fresh: fresh ? 1 : 0,
			pa_screen: document.getElementById('sspa_main') ? 1 : 0
		}, cb).fail(function (xhr, textStatus) { renderError(SSPAFeedback.xhr(xhr, wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'), textStatus)); });
	}

	// Whatever the panel is showing, show it again with fresh data.
	function reload(done) {
		if (current.url) {
			fetchByUrl(function (resp) {
				if (resp.success && resp.data.found) {
					current.profileId = resp.data.profile_id;
					body(resp.data.html);
					if (done) { done(); }
				} else {
					renderError(resp && resp.success ? sspa_adhoc.i18n.failed : SSPAFeedback.response(resp, sspa_adhoc.i18n.failed));
				}
			}, false);
			return;
		}
		if (current.profileId) {
			loadProfile(current.profileId, done);
		}
	}

	// A finished page-scoped Plugin Impact Analysis: its summary goes at the top of the page's
	// panel, with the link to the Plugins tab filtered to this page. Shown here, so it counts
	// as seen and the settings screen does not show it a second time.
	function impactSummary(runId) {
		$.post(sspa_adhoc.ajaxurl, { action: 'sspa_impact_summary', nonce: sspa_adhoc.nonce, run_id: runId }, function (resp) {
			if (!resp || !resp.success || !resp.data || typeof resp.data.html !== 'string') {
				return;
			}
			var block = $('<div class="sspa-adhoc-span sspa-adhoc-impact-summary">')
				.append($('<h4>').text(wp.i18n.__('Plugin Impact Analysis complete', 'super-speedy-performance-analysis')))
				.append(resp.data.html); // escaped by the server's wp_kses renderer
			// The panel's own buttons: on the front end the theme styles WordPress's .button.
			block.find('.button-primary').removeClass('button-primary').addClass('sspa-adhoc-btn-primary');
			block.find('.button').removeClass('button').addClass('sspa-adhoc-btn');
			pop().find('.sspa-adhoc-body').prepend(block);
			$.post(sspa_adhoc.ajaxurl, { action: 'sspa_impact_summary_seen', nonce: sspa_adhoc.nonce, run_id: runId });
		});
	}

	$(document).on('click', '#sspa-adhoc-pop .sspa-impact-summary-close', function () {
		$(this).closest('.sspa-adhoc-impact-summary').remove();
	});

	function start(url, allHooks, rawStreams) {
		current.url = url || current.url || pageUrl();
		renderProgress(null);
		$.post(sspa_adhoc.ajaxurl, { action: 'sspa_adhoc_start', nonce: sspa_adhoc.nonce, url: current.url, all_hooks: allHooks ? 1 : 0, raw_streams: rawStreams ? 1 : 0 }, function (resp) {
			if (!resp.success) {
				renderError(SSPAFeedback.response(resp, wp.i18n.__('Could not start.', 'super-speedy-performance-analysis')));
				return;
			}
			drive(resp.data.run_id, function () {
				fetchByUrl(function (r) {
					if (r.success && r.data.found) {
						current.profileId = r.data.profile_id;
						body(r.data.html);
					} else {
						renderError(r && r.success ? sspa_adhoc.i18n.failed : SSPAFeedback.response(r, sspa_adhoc.i18n.failed));
					}
				}, true);
			});
		}).fail(function (xhr, textStatus) { renderError(SSPAFeedback.xhr(xhr, wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'), textStatus)); });
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
		// Browser-transport run (loopbacks blocked at preflight): process_batch no-ops
		// server-side, this loop becomes the status poll, and SSPATransport fetches the
		// pages from THIS browser - carrying the basic auth / clearance the server lacks.
		var browserDriver = null;
		function step() {
			$.post(sspa_adhoc.ajaxurl, { action: 'sspa_process_batch', nonce: sspa_adhoc.nonce, run_id: runId }, function (resp) {
				failures = 0;
				var s = resp && resp.success ? resp.data : null;
				if (s && (s.status === 'crawling' || s.status === 'analysing')) {
					if (s.transport === 'browser' && s.status === 'crawling' && !browserDriver && window.SSPATransport) {
						console.info(wp.i18n.__('[SSPA] loopbacks are blocked on this site - this browser is fetching the pages itself', 'super-speedy-performance-analysis'));
						browserDriver = window.SSPATransport.drive({
							ajaxurl: sspa_adhoc.ajaxurl,
							nonce: sspa_adhoc.nonce,
							runId: runId,
							onFail: function (m) { console.error(wp.i18n.__('[SSPA] browser transport stopped: ', 'super-speedy-performance-analysis') + m); }
						});
					}
					renderProgress(s, message);
					window.setTimeout(step, 400);
					return;
				}
				driving = false;
				if (browserDriver) { browserDriver.stop(); }
				if (s && s.status === 'done') {
					onDone();
				} else if (!resp || !resp.success) {
					renderError(SSPAFeedback.response(resp, sspa_adhoc.i18n.failed));
				} else {
					// The run's own recorded end reason, when it has one (cancelled, no progress...).
					renderError(s && s.reason ? sspa_adhoc.i18n.failed + ' ' + s.reason : sspa_adhoc.i18n.failed);
				}
			}).fail(function (xhr, textStatus) {
				if (++failures < 4) {
					window.setTimeout(step, 2000);
					return;
				}
				driving = false;
				if (browserDriver) { browserDriver.stop(); }
				renderError(SSPAFeedback.xhr(xhr, wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'), textStatus));
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
				renderError(SSPAFeedback.response(resp, wp.i18n.__('Request failed.', 'super-speedy-performance-analysis')));
				return;
			}
			if (resp.data.running) {
				drive(resp.data.running, function () {
					fetchByUrl(function (r) {
						if (r.success && r.data.found) {
							current.profileId = r.data.profile_id;
							body(r.data.html);
						} else {
							renderError(r && r.success ? sspa_adhoc.i18n.failed : SSPAFeedback.response(r, sspa_adhoc.i18n.failed));
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

	// Drive a run someone else started (the AJAX tab's "Profile this endpoint") in this panel,
	// then hand back so the caller can open the profile it produced.
	function openRun(runId, onDone, message) {
		current = { profileId: 0, url: '' };
		show();
		drive(runId, onDone, message);
	}

	function openStatus(message, detail) {
		current = { profileId: 0, url: '' };
		show();
		body(
			'<p class="sspa-adhoc-running"><span class="sspa-adhoc-spin"></span>' + esc(message || sspa_adhoc.i18n.running) + '</p>' +
			(detail ? '<p class="sspa-adhoc-note">' + esc(detail) + '</p>' : '')
		);
	}

	function openError(message) {
		current = { profileId: 0, url: '' };
		show();
		renderError(message || sspa_adhoc.i18n.failed);
	}

	// The panel's public surface: the Pages tab opens it by profile id, and the settings
	// page's Plugin Impact Analysis button opens the site-scoped plugin picker.
	window.sspaPanel = {
		openProfile: openProfile,
		openRun: openRun,
		openUrl: openUrl,
		openImpactPicker: openImpactPicker,
		openStatus: openStatus,
		openError: openError,
		linkify: linkify
	};

	// Deep link. Another screen - the shared Super Speedy dashboard - can send someone here
	// with the right tool primed: ?sspa_open=page|checkout|save clicks the matching admin-bar
	// entry once the DOM is ready.
	//
	// It only OPENS the panel. Nothing is measured and nothing is bought: the page panel shows
	// a stored result, and checkout opens its pre-flight, which still needs the human press.
	// A link on another screen must never be able to start a real purchase.
	$(function () {
		var want = (window.location.search.match(/[?&]sspa_open=([a-z]+)/) || [])[1];
		if (!want) {
			return;
		}
		var targets = {
			page: '#wp-admin-bar-sspa-adhoc > a',
			checkout: '#wp-admin-bar-sspa-checkout > a',
			save: '#wp-admin-bar-sspa-admin-save > a'
		};
		var target = targets[want] ? $(targets[want]) : $();
		if (target.length) {
			target.trigger('click');
		}
	});

	$(document).on('click', '#wp-admin-bar-sspa-menu > a, #wp-admin-bar-sspa-adhoc > a', function (e) {
		e.preventDefault();
		var el = pop();
		if (el.is(':visible')) {
			hide();
			return;
		}
		openUrl(pageUrl());
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-close', function () {
		hide();
	});

	$(document).on('keydown', function (e) {
		if (wp.i18n.__('Escape', 'super-speedy-performance-analysis') === e.key && $('#sspa-adhoc-pop').is(':visible')) {
			hide();
		}
	});

	// Re-run: profile the page this panel is showing, which is not necessarily the page the
	// browser is on - the button carries its own URL when the panel was opened from a row.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-rerun', function () {
		start($(this).data('url') || current.url || pageUrl());
	});

	// Export the exact stored one-page diagnostic already represented by this panel. The
	// server builds the document so Pages-tab and admin-bar entry points download the same
	// schema, then the browser saves it without creating a public file on the web server.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-export', function () {
		var button = $(this);
		var profileId = Number(button.data('profile-id')) || current.profileId;
		var original = button.text();
		button.prop('disabled', true).text(sspa_adhoc.i18n.exporting);
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_profile_export',
			nonce: sspa_adhoc.nonce,
			profile_id: profileId
		}, function (resp) {
			if (!resp.success || !resp.data || !resp.data.payload) {
				button.prop('disabled', false).text(original);
				window.alert(SSPAFeedback.response(resp, sspa_adhoc.i18n.export_failed));
				return;
			}
			var blob = new Blob([JSON.stringify(resp.data.payload, null, 2) + '\n'], { type: 'application/json' });
			var url = window.URL.createObjectURL(blob);
			var link = document.createElement('a');
			link.href = url;
			link.download = resp.data.filename || ((sspa_adhoc.download_prefix || '') + 'sspa-page-' + profileId + '.json');
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(url);
			button.prop('disabled', false).text(original);
		}).fail(function (xhr, textStatus) {
			button.prop('disabled', false).text(original);
			window.alert(SSPAFeedback.xhr(xhr, sspa_adhoc.i18n.export_failed, textStatus));
		});
	});

	// One server-generated Markdown document backs both actions. It contains the useful
	// diagnostic evidence but removes raw query values, SQL literals and checkout identifiers
	// before it reaches the browser.
	//
	// Resolves with {markdown, filename}; rejects with an Error whose message says what failed:
	// the server's own refusal, an HTTP failure with its status, or an empty export.
	function requestMarkdown(kind, id) {
		return new Promise(function (resolve, reject) {
			$.post(sspa_adhoc.ajaxurl, {
				action: 'sspa_markdown_export',
				nonce: sspa_adhoc.nonce,
				kind: kind,
				id: id
			}).done(function (resp) {
				if (!resp || !resp.success) {
					reject(new Error(SSPAFeedback.response(resp, sspa_adhoc.i18n.markdown_failed)));
					return;
				}
				if (!resp.data || 'string' !== typeof resp.data.markdown || !resp.data.markdown) {
					reject(new Error(sspa_adhoc.i18n.markdown_empty));
					return;
				}
				resolve(resp.data);
			}).fail(function (xhr, textStatus) {
				reject(new Error(SSPAFeedback.xhr(xhr, sspa_adhoc.i18n.markdown_failed, textStatus)));
			});
		});
	}

	function markdownExport(button, mode) {
		var kind = String(button.data('kind') || '');
		var id = Number(button.data('id')) || 0;
		var original = button.text();
		var status = button.siblings('.sspa-markdown-status').first();
		function settle(text) {
			button.prop('disabled', false).text(original);
			status.text(text || '');
		}
		button.prop('disabled', true).text(sspa_adhoc.i18n.exporting);
		status.text('');
		var request = requestMarkdown(kind, id);
		if ('copy' === mode) {
			// The clipboard write starts now, inside the click. A large export can take longer
			// than the browser's user-activation window (five seconds in Chromium), and a write
			// started when the answer arrives is refused (0.70.6).
			SSPAFeedback.copyLater(request.then(function (data) { return data.markdown; })).then(function (outcome) {
				settle(outcome.manual ? sspa_adhoc.i18n.copy_manual : sspa_adhoc.i18n.copied + '.');
			}, function (failure) {
				settle();
				window.alert(failure && failure.exportError ? failure.exportError.message : String(failure && failure.message ? failure.message : failure));
			});
			return;
		}
		request.then(function (data) {
			var blob = new Blob([data.markdown], { type: 'text/markdown;charset=utf-8' });
			var url = window.URL.createObjectURL(blob);
			var link = document.createElement('a');
			link.href = url;
			link.download = data.filename || ((sspa_adhoc.download_prefix || '') + 'sspa-analysis-' + id + '.md');
			document.body.appendChild(link);
			link.click();
			link.remove();
			window.URL.revokeObjectURL(url);
			settle(wp.i18n.__('Downloaded.', 'super-speedy-performance-analysis'));
		}, function (error) {
			settle();
			window.alert(error.message);
		});
	}

	$(document).on('click', '#wp-admin-bar-sspa-copy-markdown > a', function (event) {
		event.preventDefault();
		var id = new URL(this.href).searchParams.get('run');
		markdownExport($(this).data('kind', 'run').data('id', id), 'download');
	});

	$(document).on('click', '.sspa-markdown-download', function () {
		markdownExport($(this), 'download');
	});

	$(document).on('click', '.sspa-markdown-copy', function () {
		markdownExport($(this), 'copy');
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
		row.find('button[aria-expanded]').attr('aria-expanded', String(opening));
		if (!opening) { subs.find('button[aria-expanded]').attr('aria-expanded', 'false'); }
	});

	// An untimed-remainder row with phase-scoped profiler data expands in place to the
	// functions sampled during that phase.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-untimed', function (e) {
		e.stopPropagation();
		var row = $(this);
		var key = row.attr('data-fns');
		var subs = row.closest('table').find('.sspa-adhoc-fnsub[data-fnparent="' + key + '"]');
		var opening = subs.first().is(':hidden');
		subs.toggle(opening);
		row.find('button[aria-expanded]').attr('aria-expanded', String(opening));
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
		// The "Where it came from" block is read, selected and clicked in; it never copies.
		if ($(e.target).closest('.sspa-adhoc-qctx').length) {
			return;
		}
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
		// Copied inside the click; a refusal shows the query to copy by hand instead of nothing.
		SSPAFeedback.copyText(sql).then(function (outcome) {
			if (!outcome.manual) { toast(); }
		});
	});

	// Analyse rows: the server re-runs the retained SELECT (named by profile and event id, never
	// sent as SQL) under ANALYZE and answers with the rows each table actually read.
	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-analyse', function () {
		var btn = $(this);
		var out = btn.closest('.sspa-adhoc-qctx').find('.sspa-adhoc-analyse-result');
		btn.prop('disabled', true);
		out.html('<p class="sspa-adhoc-note">' + esc(wp.i18n.__('Running the query once more under ANALYZE...', 'super-speedy-performance-analysis')) + '</p>');
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_analyse_rows',
			nonce: sspa_adhoc.nonce,
			profile_id: btn.attr('data-profile'),
			event_id: btn.attr('data-event')
		}, function (resp) {
			btn.prop('disabled', false);
			if (!resp.success) {
				out.html('<p class="sspa-adhoc-error">' + esc(SSPAFeedback.response(resp, wp.i18n.__('The analysis failed.', 'super-speedy-performance-analysis'))) + '</p>');
				return;
			}
			var d = resp.data;
			var rows = (d.tables || []).map(function (t) {
				return '<tr><td><code>' + esc(t.table) + '</code></td><td>' + esc(t.access_type || '-') + (t.key ? ' <code>' + esc(t.key) + '</code>' : '') + '</td><td>' + esc(Number(t.rows).toLocaleString()) + '</td><td>' + esc(t.estimated_rows === null || t.estimated_rows === undefined ? '-' : Number(t.estimated_rows).toLocaleString()) + '</td><td>' + esc(t.loops) + '</td></tr>';
			}).join('');
			out.html('<p class="sspa-adhoc-note">' + esc(wp.i18n.sprintf(
				/* translators: 1: ANALYZE statement used, 2: milliseconds */
				wp.i18n.__('%1$s: the query took %2$s ms this time.', 'super-speedy-performance-analysis'), d.method, Number(d.total_ms).toFixed(1)
			)) + '</p><table class="sspa-adhoc-table sspa-adhoc-analyse-table"><tr><th>' + esc(wp.i18n.__('Table', 'super-speedy-performance-analysis')) + '</th><th>' + esc(wp.i18n.__('Access', 'super-speedy-performance-analysis')) + '</th><th>' + esc(wp.i18n.__('Rows read', 'super-speedy-performance-analysis')) + '</th><th>' + esc(wp.i18n.__('Estimated', 'super-speedy-performance-analysis')) + '</th><th>' + esc(wp.i18n.__('Loops', 'super-speedy-performance-analysis')) + '</th></tr>' + rows + '</table>'
				+ '<details><summary>' + esc(wp.i18n.__('Raw output', 'super-speedy-performance-analysis')) + '</summary><pre class="sspa-adhoc-analyse-raw">' + esc(d.raw) + '</pre></details>');
		}).fail(function (xhr, textStatus) {
			btn.prop('disabled', false);
			out.html('<p class="sspa-adhoc-error">' + esc(SSPAFeedback.xhr(xhr, wp.i18n.__('The analysis failed.', 'super-speedy-performance-analysis'), textStatus)) + '</p>');
		});
	});

	// ---- plugin impact: the picker ----
	// One picker, two scopes. From the page panel it plans "measure plugins on this page";
	// from the settings page's Plugin Impact Analysis button it plans the site-wide sweep.
	// Page-scoped measured suspects are preselected for review; the run starts only on confirmation.

	var plan = null; // the last plan fetched: {scope, plugins, pages, oc_capable, ...}

	function fetchPlan(profileId, target, url) {
		target.html('<p class="sspa-adhoc-note">' + esc(sspa_adhoc.i18n.loading) + '</p>');
		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_impact_plan',
			nonce: sspa_adhoc.nonce,
			profile_id: profileId || 0,
			url: url || ''
		}, function (resp) {
			if (!resp.success) {
				target.html('<p class="sspa-adhoc-error">' + esc(SSPAFeedback.response(resp, wp.i18n.__('Could not work out what to measure.', 'super-speedy-performance-analysis'))) + '</p>');
				return;
			}
			plan = resp.data;
			target.html(planHtml(plan));
			updateEstimate();
		}).fail(function (xhr, textStatus) {
			target.html('<p class="sspa-adhoc-error">' + esc(SSPAFeedback.xhr(xhr, wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'), textStatus)) + '</p>');
		});
	}

	$(document).on('click', '#sspa-adhoc-pop .sspa-impact-only-page', function () {
		var box = $(this).closest('.sspa-adhoc-plan');
		var url = box.find('.sspa-impact-only-url').val().trim();
		if (url) { current.url = url; fetchPlan(0, box, url); }
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-time-raw-streams', function () { start(current.url, true, true); });
	$(document).on('click', '#sspa-adhoc-pop .sspa-time-all-hooks', function () { start(current.url, true); });

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-measure', function () {
		fetchPlan($(this).data('profile-id'), $(this).closest('.sspa-page-summary').find('.sspa-adhoc-plan'));
	});

	// The settings page's Plugin Impact Analysis button opens the same picker in the popover
	// shell, site-scoped. sspa-admin.js calls this.
	function openImpactPicker() {
		current = { profileId: 0, url: '' };
		show();
		body('<div class="sspa-adhoc-grid"><div class="sspa-adhoc-span sspa-adhoc-plan"></div></div>');
		fetchPlan(0, pop().find('.sspa-adhoc-plan'));
	}

	function planHtml(data) {
		var i18n = sspa_adhoc.i18n;
		var site = 'site' === data.scope;
		var html = '<div class="sspa-adhoc-planbox">';
		if (site) html += '<p><label>' + esc(i18n.only_page) + ' <input type="url" class="sspa-impact-only-url"></label> <button type="button" class="sspa-adhoc-btn sspa-impact-only-page">' + esc(i18n.only_page_apply) + '</button></p>';
		html += '<h4>' + esc(site ? i18n.plan_title_site : i18n.plan_title) + '</h4>';
		html += '<p class="sspa-adhoc-note">' + esc(i18n.plan_hint) + '</p>';
		html += '<p class="sspa-adhoc-note">' + esc(i18n.plan_risk) + '</p>';
		html += '<p class="sspa-adhoc-note">' + esc(i18n.plan_pick) + '</p>';
		html += '<p class="sspa-adhoc-planactions">' +
			(site ? '' : '<button type="button" class="sspa-adhoc-btn sspa-adhoc-pick" data-pick="blamed">' + esc(i18n.select_blamed) + '</button> ') +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-pick" data-pick="all">' + esc(i18n.select_all) + '</button> ' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-pick" data-pick="none">' + esc(i18n.select_none) + '</button></p>';
		html += '<ul class="sspa-adhoc-planlist">';
		data.plugins.forEach(function (p, index) {
			var blamed = p.cost_ms > 0;
			var cost = blamed
				? (site ? i18n.attributed_site : i18n.attributed_here).replace('%s', p.cost_ms.toFixed(1))
				: i18n.no_cost;
			// A plugin that cannot run without this one comes out in the same measurement, so
			// the verdict will name both. Say so here, not afterwards.
			var group = (p.group && p.group.length)
				? ' <small>' + esc(i18n.with_group.replace('%s', p.group.join(', '))) + '</small>'
				: '';
			html += '<li><label><input type="checkbox" class="sspa-adhoc-pluginpick" value="' + esc(p.slug) + '"' +
				' data-blamed="' + (blamed ? '1' : '0') + '"' + (!site && blamed && index < 3 ? ' checked' : '') + '> <code>' + esc(p.slug) + '</code> ' +
				'<small>' + esc(cost) + '</small>' +
				group + '</label></li>';
		});
		html += '</ul>';
		if (data.oc_capable) {
			html += '<p><label><input type="checkbox" id="sspa-adhoc-cachemodes"> ' + esc(i18n.cache_modes) + '</label></p>';
		}
		if (!data.oc_capable && data.oc_reason) {
			html += '<p class="sspa-adhoc-note">' + esc(data.oc_reason) + '</p>';
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

		var text;
		var jobs;
		var site = 'site' === plan.scope;
		var all = site && count === plan.plugins.length;
		if (all) {
			// Every plugin = the classic two-phase sweep: a cheap screen of each plugin on
			// its busiest pages first, the full treatment only for what shows an impact.
			var screenCells = count * Math.min(plan.screen_pages + 1, plan.pages);
			jobs = Math.round(measurements(screenCells, 1, plan.rebaseline_every) * 1.1);
			text = i18n.estimate_screen
				.replace('%1$s', count)
				.replace('%2$s', jobs)
				.replace('%3$s', duration(jobs * plan.seconds_per_job));
		} else if (site) {
			// A chosen shortlist is targeted: every chosen plugin on every profiled page.
			jobs = plan.pages * measurements(count, 1, plan.rebaseline_every);
			text = i18n.estimate_site
				.replace('%1$s', count)
				.replace('%2$s', plan.pages)
				.replace('%3$s', jobs)
				.replace('%4$s', duration(jobs * plan.seconds_per_job));
		} else {
			jobs = measurements(count, 1, plan.rebaseline_every);
			text = i18n.estimate
				.replace('%1$s', count)
				.replace('%2$s', jobs)
				.replace('%3$s', duration(jobs * plan.seconds_per_job));
		}
		// Phase 2 only runs for the plugins that showed something, so its cost is a ceiling,
		// not a total - said as one, because a total that grows mid-run reads as a bug.
		if (!all && plan.oc_capable && $('#sspa-adhoc-cachemodes').is(':checked')) {
			var pages = site ? plan.pages : 1;
			var extra = pages * measurements(count, 2, plan.rebaseline_every);
			text += ' ' + i18n.estimate_phase2
				.replace('%1$s', extra)
				.replace('%2$s', duration(extra * plan.seconds_per_job));
		}
		// "1 plugins" would be the first thing a careful reader notices.
		el.text(text.replace(wp.i18n.__('1 plugins ', 'super-speedy-performance-analysis'), wp.i18n.__('1 plugin ', 'super-speedy-performance-analysis')).replace(wp.i18n.__(' 1 pages ', 'super-speedy-performance-analysis'), wp.i18n.__(' 1 page ', 'super-speedy-performance-analysis')).replace(wp.i18n.__('1 minutes', 'super-speedy-performance-analysis'), wp.i18n.__('1 minute', 'super-speedy-performance-analysis')));
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
		if (plan && 'site' === plan.scope) {
			pop().hide();
			return;
		}
		$('#sspa-adhoc-pop .sspa-adhoc-plan').empty();
	});

	$(document).on('click', '#sspa-adhoc-pop .sspa-adhoc-measure-start', function () {
		var slugs = selected();
		if (!slugs.length || !plan) {
			return;
		}
		var cache = (plan.oc_capable && $('#sspa-adhoc-cachemodes').is(':checked')) ? 1 : 0;
		var btn = $(this).prop('disabled', true);

		if ('site' === plan.scope) {
			var payload = {
				action: 'sspa_start_run',
				nonce: sspa_adhoc.nonce,
				type: 'deep',
				cache_modes: cache,
			};
			// Choosing every plugin IS the classic screened sweep (theme included, phase 1
			// screens, phase 2 confirms); a shortlist is a targeted run of just those.
			if (slugs.length !== plan.plugins.length) {
				payload['suspects[]'] = slugs;
			}
			$.post(sspa_adhoc.ajaxurl, payload, function (resp) {
				if (!resp.success) {
					$('#sspa-adhoc-pop .sspa-adhoc-estimate').text(SSPAFeedback.response(resp, wp.i18n.__('Could not start measuring.', 'super-speedy-performance-analysis')));
					btn.prop('disabled', false);
					return;
				}
				// The floating monitor on the settings page shows the feed; the picker's
				// job is done the moment the run exists.
				pop().hide();
				if (typeof window.sspa_drive_run === 'function') {
					window.sspa_drive_run(resp.data.run_id);
				}
			}).fail(function (xhr, textStatus) {
				$('#sspa-adhoc-pop .sspa-adhoc-estimate').text(SSPAFeedback.xhr(xhr, wp.i18n.__('Could not start measuring.', 'super-speedy-performance-analysis'), textStatus));
				btn.prop('disabled', false);
			});
			return;
		}

		$.post(sspa_adhoc.ajaxurl, {
			action: 'sspa_start_run',
			nonce: sspa_adhoc.nonce,
			type: 'deep',
			url: plan.url,
			cache_modes: cache,
			'suspects[]': slugs
		}, function (resp) {
			if (!resp.success) {
				$('#sspa-adhoc-pop .sspa-adhoc-estimate').text(SSPAFeedback.response(resp, wp.i18n.__('Could not start measuring.', 'super-speedy-performance-analysis')));
				$('#sspa-adhoc-pop .sspa-adhoc-measure-start').prop('disabled', false);
				return;
			}
			var runId = resp.data.run_id;
			drive(runId, function () {
				reload(function () { impactSummary(runId); });
			}, wp.i18n.__('Measuring plugin impact on this page…', 'super-speedy-performance-analysis'));
		}).fail(function (xhr, textStatus) {
			$('#sspa-adhoc-pop .sspa-adhoc-estimate').text(SSPAFeedback.xhr(xhr, wp.i18n.__('Could not start measuring.', 'super-speedy-performance-analysis'), textStatus));
			$('#sspa-adhoc-pop .sspa-adhoc-measure-start').prop('disabled', false);
		});
	});
})(jQuery);
