jQuery(function () {
	sspa_select_url_tab();
	jQuery(window).on('hashchange popstate', sspa_select_url_tab);

	// A finished run reloads the page, so sspa_autospot must be consumed exactly once -
	// left in the URL it would re-arm on every reload and loop the analysis forever.
	var query = new URLSearchParams(window.location.search);
	var autospot = query.get('sspa_autospot') === '1';
	var changeSetId = query.get('sspa_change_set') || '';
	var baselineRunId = parseInt(query.get('sspa_baseline_run_id'), 10) || 0;
	if (autospot) {
		sspa_strip_autospot_param();
	}

	// Resume the floating monitor if a run is already active when the page loads.
	var active = parseInt(jQuery('#sspa-runner').data('active-run'), 10);
	if (active) {
		sspa_drive_run(active);
	} else if (autospot) {
		// Arrived from the plugin-toggle notice: run a quick spot profile of key pages.
		sspa_start_typed_run({
			'page_keys[]': sspa_admin.quick_comparison_page_keys,
			change_set_id: changeSetId,
			baseline_run_id: baselineRunId
		}, jQuery('#sspa-run-analysis'));
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
		.replace(/([?&])sspa_change_set=[^&]*(&|$)/, function (m, before, after) {
			return after ? before : '';
		})
		.replace(/([?&])sspa_baseline_run_id=[^&]*(&|$)/, function (m, before, after) {
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
			alert(resp.data || wp.i18n.__("Re-check failed.", "super-speedy-performance-analysis"));
		}
	}).fail(function () {
		btn.prop('disabled', false);
		alert(wp.i18n.__("Re-check failed.", "super-speedy-performance-analysis"));
	});
});

// Replace an orphaned Query Monitor db.php (QM deactivated, drop-in left behind).
jQuery(document).on('click', '#sspa-replace-stale-dropin', function () {
	var btn = jQuery(this).prop('disabled', true).text(wp.i18n.__("Replacing…", "super-speedy-performance-analysis"));
	jQuery.post(ajaxurl, { action: 'sspa_replace_stale_dropin', nonce: sspa_admin.nonce }, function (resp) {
		if (resp.success) {
			// The health line lives on Overview, the install steps on Tools.
			sspa_refresh_tabs(['overview', 'tools'], function () { btn.prop('disabled', false).text(wp.i18n.__("Replace", "super-speedy-performance-analysis")); });
		} else {
			btn.prop('disabled', false);
			alert(resp.data || wp.i18n.__("Could not replace the drop-in.", "super-speedy-performance-analysis"));
		}
	}).fail(function () {
		btn.prop('disabled', false);
		alert(wp.i18n.__("Could not replace the drop-in.", "super-speedy-performance-analysis"));
	});
});

jQuery(document).on('click', '#sspa_main .nav-tab-wrapper .nav-tab', function (e) {
	var slug = jQuery(this).data('tab');
	window.history.pushState(null, null, '#' + slug);
	sspa_click_tab(slug);
	e.preventDefault();
	e.stopPropagation();
});

function sspa_select_url_tab() {
	sspa_click_tab(window.location.hash.substring(1) || new URLSearchParams(window.location.search).get('tab') || 'overview');
}

function sspa_click_tab(slug) {
	var tabs = jQuery('#sspa_main .nav-tab-wrapper .nav-tab');
	var tab = tabs.filter(function () { return jQuery(this).attr('data-tab') === slug; });
	if (!tab.length) {
		slug = 'overview';
		tab = tabs.filter(function () { return jQuery(this).attr('data-tab') === slug; });
	}
	tabs.removeClass('nav-tab-active');
	tab.addClass('nav-tab-active').focus();
	jQuery('#sspa_main div.tab-contents').css('display', 'none');
	var panel = jQuery('#sspa_main div.tab-contents').filter(function () {
		return jQuery(this).attr('data-tab') === slug;
	}).css('display', 'block');
	if (panel.attr('data-sspa-tab-loaded') === '0' && panel.attr('data-sspa-tab-loading') !== '1') {
		panel.attr('data-sspa-tab-loading', '1');
		sspa_refresh_tabs([slug]);
	}
}

// ---- Attribution mode: swap the table, never reload the page ----

jQuery(document).on('click', '#sspa_main .sspa-attrib-mode', function () {
	var btn = jQuery(this);
	var mode = btn.data('mode');
	if (btn.hasClass('button-primary') || btn.prop('disabled')) {
		return;
	}
	var buttons = jQuery('#sspa_main .sspa-attrib-mode').prop('disabled', true);
	jQuery('#sspa-attrib-wrap').css('opacity', 0.5);

	jQuery.post(ajaxurl, { action: 'sspa_attribution', nonce: sspa_admin.nonce, mode: mode }, function (resp) {
		if (!resp.success) {
			alert(resp.data || wp.i18n.__("Could not switch attribution mode.", "super-speedy-performance-analysis"));
			return;
		}
		jQuery('#sspa-attrib-wrap').html(resp.data.html);
		jQuery('#sspa-attrib-describe').text(resp.data.describe);
		buttons.each(function () {
			var b = jQuery(this);
			var on = b.data('mode') === resp.data.mode;
			b.toggleClass('button-primary', on).attr('aria-pressed', on ? 'true' : 'false');
		});
		// Keep the mode deep-linkable without navigating away from the tab.
		if (window.history.replaceState) {
			var url = new URL(window.location.href);
			url.searchParams.set('attrib', resp.data.mode);
			// url already carries the current hash, so the tab stays selected on reload.
			window.history.replaceState(null, '', url.toString());
		}
	}).fail(function () {
		alert(wp.i18n.__("Could not switch attribution mode.", "super-speedy-performance-analysis"));
	}).always(function () {
		buttons.prop('disabled', false);
		jQuery('#sspa-attrib-wrap').css('opacity', '');
	});
});


// ---- Submission queue, driven by the browser ----
// WP-Cron only fires on traffic and many hosts disable it, so a queue that relies on it can
// sit for days. While an admin is on this screen the browser drains it; cron stays as the
// fallback for headless sites. Duplicate delivery is stopped by the compare-and-set claim in
// begin_attempt() and by the receiver's own idempotency, so this needs no locking of its own.
var sspa_ticking = false;

function sspa_drive_submissions() {
	if (sspa_ticking) {
		return;
	}
	sspa_ticking = true;
	jQuery.post(ajaxurl, { action: 'sspa_submission_tick', nonce: sspa_admin.nonce }, function (resp) {
		sspa_ticking = false;
		if (resp && resp.success && resp.data.more) {
			window.setTimeout(sspa_drive_submissions, 1500);
		} else if (resp && resp.success) {
			sspa_refresh_tabs(['share']);
		}
	}).fail(function () {
		sspa_ticking = false;
	});
}

jQuery(function () {
	if (jQuery('#sspa_main').length) {
		window.setTimeout(sspa_drive_submissions, 2000);
	}
});

// ---- Tab refresh ----
// Nothing on this screen reloads the page. A reload loses the selected tab, the scroll
// position and any open drill-down, so every action that changes server state re-renders
// only the tabs it affected.
function sspa_refresh_tabs(tabs, done) {
	tabs = tabs.filter(function (slug) {
		var panel = jQuery('#sspa_main div.tab-contents[data-tab="' + slug + '"]');
		return panel.length && (panel.attr('data-sspa-tab-loaded') === '1' || panel.attr('data-sspa-tab-loading') === '1');
	});
	if (!tabs.length) {
		if (done) { done(null); }
		return;
	}
	function showError(detail) {
		tabs.forEach(function (slug) {
			var panel = jQuery('#sspa_main div.tab-contents[data-tab="' + slug + '"]');
			panel.removeAttr('data-sspa-tab-loading').find('.sspa-tab-loading, .sspa-tab-error').remove();
			var notice = jQuery('<div class="notice notice-error sspa-tab-error" role="alert">');
			jQuery('<p>').text(sspa_admin.tab_failed.replace('%s', detail)).appendTo(notice);
			jQuery('<button type="button" class="button sspa-tab-retry">').text(sspa_admin.tab_retry).appendTo(notice);
			panel.prepend(notice);
		});
	}
	jQuery.post(ajaxurl, { action: 'sspa_render_tab', nonce: sspa_admin.nonce, tabs: tabs.join(',') }, function (resp) {
		if (resp && resp.success && resp.data && resp.data.tabs) {
			Object.keys(resp.data.tabs).forEach(function (slug) {
				var panel = jQuery('#sspa_main div.tab-contents[data-tab="' + slug + '"]');
				// Preserve the selected preview, including a request still filling it.
				// Background queue refreshes must not detach its response target.
				var preview = panel.find('.sspa-payload-preview:visible').detach();
				var summary = preview.length ? panel.find('.sspa-payload-summary').detach() : jQuery();
				panel
					.html(resp.data.tabs[slug])
					.attr('data-sspa-tab-loaded', '1')
					.removeAttr('data-sspa-tab-loading');
				if (preview.length) {
					panel.find('.sspa-payload-preview').replaceWith(preview);
					panel.find('.sspa-payload-summary').replaceWith(summary);
				}
				jQuery(document).trigger('sspa:tab-rendered', [slug, panel.get(0)]);
			});
			jQuery('#sspa-runner').attr('data-active-run', resp.data.active_run || 0);
		} else {
			showError(resp && typeof resp.data === 'string' ? resp.data : sspa_admin.tab_invalid_response);
		}
		if (done) { done(resp); }
	}).fail(function (xhr, status, error) {
		showError(xhr.responseJSON && typeof xhr.responseJSON.data === 'string' ? xhr.responseJSON.data :
			(xhr.status ? 'HTTP ' + xhr.status + ': ' + (error || status) : sspa_admin.tab_network_error));
		if (done) { done(null); }
	});
}

jQuery(document).on('click', '#sspa_main .sspa-tab-retry', function () {
	var button = jQuery(this).prop('disabled', true);
	var panel = button.closest('.tab-contents').attr('data-sspa-tab-loading', '1');
	sspa_refresh_tabs([panel.attr('data-tab')]);
});

// In-page links between tabs. These used to be hrefs to ?tab=<slug>, which reloaded the page
// AND landed on Overview anyway, because tab selection is driven by the hash.
jQuery(document).on('click', '#sspa_main .sspa-goto-tab', function (e) {
	e.preventDefault();
	var slug = jQuery(this).data('tab');
	window.history.pushState(null, null, '#' + slug);
	sspa_click_tab(slug);
});

jQuery(document).on('change', '#sspa-remove-data-on-uninstall', function () {
	var checkbox = jQuery(this).prop('disabled', true);
	var spinner = checkbox.closest('.sspa-history-toolbar').find('.spinner').addClass('is-active');
	jQuery.post(ajaxurl, {
		action: 'sspa_uninstall_setting',
		nonce: sspa_admin.nonce,
		enabled: checkbox.is(':checked') ? 1 : 0
	}).fail(function () {
		checkbox.prop('checked', !checkbox.is(':checked'));
		alert(wp.i18n.__("Could not save the uninstall setting.", "super-speedy-performance-analysis"));
	}).always(function () {
		checkbox.prop('disabled', false);
		spinner.removeClass('is-active');
	});
});

jQuery(document).on('change', '#sspa-plugin-update-detection', function () {
	var checkbox = jQuery(this).prop('disabled', true);
	var spinner = checkbox.closest('p').find('.spinner').addClass('is-active');
	jQuery.post(ajaxurl, {
		action: 'sspa_history_setting',
		nonce: sspa_admin.nonce,
		plugin_update_detection: checkbox.is(':checked') ? 1 : 0
	}).fail(function () {
		checkbox.prop('checked', !checkbox.is(':checked'));
		alert(wp.i18n.__("Could not save the update comparison setting.", "super-speedy-performance-analysis"));
	}).always(function () {
		checkbox.prop('disabled', false);
		spinner.removeClass('is-active');
	});
});

function sspa_history_pair(section) {
	if (section && section.length) {
		return {
			before_run_id: parseInt(section.attr('data-before-run'), 10) || 0,
			after_run_id: parseInt(section.attr('data-after-run'), 10) || 0
		};
	}
	return {
		before_run_id: parseInt(jQuery('#sspa-history-before').val(), 10) || 0,
		after_run_id: parseInt(jQuery('#sspa-history-after').val(), 10) || 0
	};
}

jQuery(document).on('click', '#sspa-history-compare', function () {
	var btn = jQuery(this).prop('disabled', true);
	var spinner = btn.siblings('.spinner').addClass('is-active');
	var panel = btn.closest('div.tab-contents');
	var chartHost = panel.find('.sspa-history-chart-host');
	var filter = chartHost.find('.sspa-history-page-filter').val() || '';
	var data = jQuery.extend({ action: 'sspa_history_compare', nonce: sspa_admin.nonce,
		selection_mode: jQuery('#sspa-history-mode').val() || 'pair',
		metric: chartHost.find('.sspa-history-metric').val() || 'request_wall_ms'
	}, sspa_history_pair());
	chartHost.prop('hidden', true);
	jQuery('#sspa-history-comparison').text(wp.i18n.__("Loading the selected comparison…", "super-speedy-performance-analysis"));
	panel.find('.sspa-history-compare-controls select').prop('disabled', true);
	jQuery.post(ajaxurl, data, function (resp) {
		if (resp.success) {
			jQuery('#sspa-history-comparison').html(resp.data.html);
			chartHost.find('.sspa-history-chart').each(function () {
				if (this.sspaResizeObserver) this.sspaResizeObserver.disconnect();
				if (this.sspaChart) this.sspaChart.dispose();
			});
			chartHost.html(resp.data.chart_html).prop('hidden', false);
			chartHost.find('.sspa-history-page-filter').val(filter);
			jQuery('#sspa-history-after').val(resp.data.after_run_id);
			if (resp.data.before_run_id) jQuery('#sspa-history-before').val(resp.data.before_run_id);
			jQuery(document).trigger('sspa:tab-rendered', ['history', panel.get(0)]);
		} else {
			jQuery('#sspa-history-comparison').text(resp.data || wp.i18n.__("The points in time could not be compared.", "super-speedy-performance-analysis"));
		}
	}).fail(function () {
		jQuery('#sspa-history-comparison').text(wp.i18n.__("The points in time could not be compared. Please try Compare again.", "super-speedy-performance-analysis"));
	}).always(function () {
		btn.prop('disabled', false);
		panel.find('.sspa-history-compare-controls select').prop('disabled', false);
		spinner.removeClass('is-active');
	});
});

jQuery(document).on('click', '.sspa-history-assert', function () {
	var btn = jQuery(this).prop('disabled', true);
	var data = jQuery.extend({
		action: 'sspa_history_assertion',
		nonce: sspa_admin.nonce,
		mode: btn.data('mode'),
		page_identity: btn.data('page-identity')
	}, sspa_history_pair(btn.closest('.sspa-history-comparison')));
	jQuery.post(ajaxurl, data, function (resp) {
		if (resp.success) {
			jQuery('#sspa-history-comparison').html(resp.data.html);
		} else {
			btn.prop('disabled', false);
			alert(resp.data || wp.i18n.__("The expectation could not be changed.", "super-speedy-performance-analysis"));
		}
	}).fail(function () {
		btn.prop('disabled', false);
		alert(wp.i18n.__("The expectation could not be changed.", "super-speedy-performance-analysis"));
	});
});

jQuery(document).on('click', '.sspa-history-preview-export', function () {
	var section = jQuery(this).closest('.sspa-history-comparison');
	var btn = jQuery(this).prop('disabled', true);
	var spinner = section.find('.sspa-history-export-actions .spinner').addClass('is-active');
	var data = jQuery.extend({ action: 'sspa_history_export', nonce: sspa_admin.nonce }, sspa_history_pair(section));
	jQuery.post(ajaxurl, data, function (resp) {
		if (!resp.success) {
			alert(resp.data || wp.i18n.__("The evidence preview could not be prepared.", "super-speedy-performance-analysis"));
			return;
		}
		var json = JSON.stringify(resp.data.payload, null, 2);
		section.data('sspa-history-export', { json: json, filename: resp.data.filename });
		section.find('.sspa-history-export-preview').prop('hidden', false).text(json);
		section.find('.sspa-history-download-export').prop('disabled', false);
	}).fail(function () {
		alert(wp.i18n.__("The evidence preview could not be prepared.", "super-speedy-performance-analysis"));
	}).always(function () {
		btn.prop('disabled', false);
		spinner.removeClass('is-active');
	});
});

jQuery(document).on('click', '.sspa-history-download-export', function () {
	var section = jQuery(this).closest('.sspa-history-comparison');
	var prepared = section.data('sspa-history-export');
	if (!prepared || !prepared.json) {
		return;
	}
	var blob = new Blob([prepared.json], { type: 'application/json' });
	var link = document.createElement('a');
	link.href = URL.createObjectURL(blob);
	link.download = prepared.filename || ((sspa_admin.download_prefix || '') + 'sspa-history-comparison.json');
	document.body.appendChild(link);
	link.click();
	document.body.removeChild(link);
	URL.revokeObjectURL(link.href);
});

// ---- Pages drill-down ----
// Opens THE profile panel - the same one the admin bar's "Analyse this page" opens, rendered
// by the same PHP partial. This used to build its own markup here with html += concatenation,
// which is precisely how the two views ended up showing different subsets of one capture.

// A row is a button: it opens by click, and by Enter or Space from the keyboard.
jQuery(document).on('keydown', '.sspa-page-row', function (e) {
	if ('Enter' === e.key || ' ' === e.key) {
		e.preventDefault();
		jQuery(this).trigger('click');
	}
});
jQuery(document).on('click', '.sspa-page-row', function () {
	if (!window.sspaPanel) {
		return;
	}
	window.sspaPanel.openProfile(jQuery(this).data('profile-id'));
});

function sspa_esc(str) {
	return jQuery('<span>').text(str == null ? '' : String(str)).html();
}

// Download the complete local cache optimisation evidence shown on Overview. The server
// builds a versioned document; the browser saves it without sending it anywhere else.
jQuery(document).on('click', '.sspa-cache-safety-download', function () {
	var btn = jQuery(this).prop('disabled', true);
	var status = btn.siblings('.sspa-cache-safety-download-status').text(wp.i18n.__(" Preparing report…", "super-speedy-performance-analysis"));
	jQuery.post(ajaxurl, {
		action: 'sspa_cache_recon_export',
		nonce: sspa_admin.nonce,
		run_id: btn.data('run-id')
	}, function (resp) {
		if (!resp.success) {
			status.text(' ' + (resp.data || wp.i18n.__("The report could not be prepared.", "super-speedy-performance-analysis")));
			return;
		}
		var json = JSON.stringify(resp.data.payload, null, 2);
		var blob = new Blob([json], { type: 'application/json' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = resp.data.filename || ((sspa_admin.download_prefix || '') + 'sspa-cache-optimisation-analysis.json');
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(link.href);
		status.text(wp.i18n.__(" Downloaded.", "super-speedy-performance-analysis"));
	}).fail(function () {
		status.text(wp.i18n.__(" The report could not be prepared.", "super-speedy-performance-analysis"));
	}).always(function () {
		btn.prop('disabled', false);
	});
});

// ---- Experimental traffic collector -----------------------------------

var sspaTrafficPoll = null;

function sspa_schedule_traffic_poll() {
	window.clearTimeout(sspaTrafficPoll);
	if (!jQuery('.sspa-traffic-panel[data-active="1"]').length) {
		return;
	}
	sspaTrafficPoll = window.setTimeout(function () {
		sspa_refresh_tabs(['traffic'], sspa_schedule_traffic_poll);
	}, 15000);
}

jQuery(function () {
	sspa_schedule_traffic_poll();
});

jQuery(document).on('click', '#sspa-traffic-start', function () {
	var btn = jQuery(this);
	var message = jQuery('.sspa-traffic-message');
	if (!jQuery('#sspa-traffic-confirm').prop('checked')) {
		alert(wp.i18n.__("Confirm that you have read the privacy and resource limits before starting.", "super-speedy-performance-analysis"));
		return;
	}
	btn.prop('disabled', true).text(wp.i18n.__("Running database pre-flight…", "super-speedy-performance-analysis"));
	jQuery.post(ajaxurl, {
		action: 'sspa_traffic_start',
		nonce: sspa_admin.nonce,
		duration: jQuery('#sspa-traffic-duration').val(),
		confirmed: 1
	}, function (resp) {
		if (!resp.success) {
			btn.prop('disabled', false).text(wp.i18n.__("Start collection", "super-speedy-performance-analysis"));
			alert(resp.data || wp.i18n.__("Collection could not be started.", "super-speedy-performance-analysis"));
			return;
		}
		message.text(wp.i18n.__("Collection started.", "super-speedy-performance-analysis"));
		sspa_refresh_tabs(['traffic'], sspa_schedule_traffic_poll);
	}).fail(function () {
		btn.prop('disabled', false).text(wp.i18n.__("Start collection", "super-speedy-performance-analysis"));
		alert(wp.i18n.__("Collection could not be started.", "super-speedy-performance-analysis"));
	});
});

jQuery(document).on('click', '#sspa-traffic-stop, #sspa-traffic-emergency-stop', function () {
	var btn = jQuery(this);
	var emergency = btn.is('#sspa-traffic-emergency-stop');
	if (emergency && !window.confirm(wp.i18n.__("Remove the observer immediately? No more request or order-outcome events will be recorded.", "super-speedy-performance-analysis"))) {
		return;
	}
	btn.prop('disabled', true);
	jQuery.post(ajaxurl, {
		action: 'sspa_traffic_stop',
		nonce: sspa_admin.nonce,
		collection_id: jQuery('.sspa-traffic-panel').data('collection-id') || 0,
		emergency: emergency ? 1 : 0
	}, function (resp) {
		if (!resp.success) {
			btn.prop('disabled', false);
			alert(resp.data || wp.i18n.__("Collection could not be stopped.", "super-speedy-performance-analysis"));
			return;
		}
		sspa_refresh_tabs(['traffic'], sspa_schedule_traffic_poll);
	}).fail(function () {
		btn.prop('disabled', false);
		alert(wp.i18n.__("Collection could not be stopped.", "super-speedy-performance-analysis"));
	});
});

jQuery(document).on('click', '#sspa-traffic-observations', function () {
	var btn = jQuery(this).prop('disabled', true);
	var message = jQuery('.sspa-traffic-message').text(wp.i18n.__(" Preparing observations…", "super-speedy-performance-analysis"));
	jQuery.post(ajaxurl, {
		action: 'sspa_traffic_observations',
		nonce: sspa_admin.nonce,
		collection_id: jQuery('.sspa-traffic-panel').data('collection-id') || 0
	}, function (resp) {
		if (!resp.success) {
			message.text(' ' + (resp.data || wp.i18n.__("Observations could not be prepared.", "super-speedy-performance-analysis")));
			return;
		}
		var json = JSON.stringify(resp.data.payload, null, 2);
		var blob = new Blob([json], { type: 'application/json' });
		var link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = resp.data.filename || ((sspa_admin.download_prefix || '') + 'sspa-experimental-traffic-observations.json');
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(link.href);
		message.text(wp.i18n.__(" Downloaded.", "super-speedy-performance-analysis"));
	}).fail(function () {
		message.text(wp.i18n.__(" Observations could not be prepared.", "super-speedy-performance-analysis"));
	}).always(function () {
		btn.prop('disabled', false);
	});
});

jQuery(document).on('click', '#sspa-traffic-delete', function () {
	if (!window.confirm(wp.i18n.__("Permanently delete this collection, all raw event rows and its temporary join key?", "super-speedy-performance-analysis"))) {
		return;
	}
	var btn = jQuery(this).prop('disabled', true);
	jQuery.post(ajaxurl, {
		action: 'sspa_traffic_delete',
		nonce: sspa_admin.nonce,
		collection_id: jQuery('.sspa-traffic-panel').data('collection-id') || 0
	}, function (resp) {
		if (!resp.success) {
			btn.prop('disabled', false);
			alert(resp.data || wp.i18n.__("Collection data could not be deleted.", "super-speedy-performance-analysis"));
			return;
		}
		sspa_refresh_tabs(['traffic'], sspa_schedule_traffic_poll);
	}).fail(function () {
		btn.prop('disabled', false);
		alert(wp.i18n.__("Collection data could not be deleted.", "super-speedy-performance-analysis"));
	});
});

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
	var detail = jQuery('<tr class="sspa-impact-detail-row"><td colspan="' + cols + '">' + sspa_esc(wp.i18n.__("Loading…", "super-speedy-performance-analysis")) + '</td></tr>');
	row.after(detail);
	jQuery.post(ajaxurl, { action: 'sspa_plugin_detail', nonce: sspa_admin.nonce, plugin: link.data('plugin') }, function (resp) {
		if (!resp.success) {
			detail.children('td').text(resp.data || wp.i18n.__("No detail available.", "super-speedy-performance-analysis"));
			return;
		}
		var rows = resp.data.rows;
		var modeOrder = ['normal', 'disabled', 'prime', 'warm'];
		var modeLabels = { normal: wp.i18n.__("Standard (cache warm)", "super-speedy-performance-analysis"), disabled: wp.i18n.__("No object cache", "super-speedy-performance-analysis"), prime: wp.i18n.__("First sample (ambient cache)", "super-speedy-performance-analysis"), warm: wp.i18n.__("Warm cache", "super-speedy-performance-analysis") };
		var modes = modeOrder.filter(function (m) {
			return rows.some(function (r) { return r.object_cache_mode === m; });
		});
		var byPage = {};
		rows.forEach(function (r) {
			(byPage[r.page_key] = byPage[r.page_key] || {})[r.object_cache_mode] = r;
		});
		var version = resp.data.measured_version;
		var heading = version ? wp.i18n.sprintf(/* translators: 1: plugin identifier, 2: plugin version. */ wp.i18n.__("Measured impact of %1$s version %2$s per page", "super-speedy-performance-analysis"), link.data('plugin'), version) : wp.i18n.sprintf(/* translators: Plugin identifier. */ wp.i18n.__("Measured impact of %s per page", "super-speedy-performance-analysis"), link.data('plugin'));
		var html = '<div class="sspa-detail"><h4>' + sspa_esc(heading) + '</h4>';
		html += '<table class="widefat"><thead><tr><th>' + sspa_esc(wp.i18n.__("Page", "super-speedy-performance-analysis")) + '</th>';
		modes.forEach(function (m) {
			html += '<th>' + sspa_esc(modeLabels[m]) + '</th>';
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
		html += '<p class="description">' + sspa_esc(wp.i18n.__("\"adds\" = the plugin costs that much page-generation time; \"saves\" = the page is SLOWER without it (the plugin is speeding it up); \"within noise\" = no measurable difference on that page.", "super-speedy-performance-analysis")) + '</p></div>';
		detail.children('td').html(html);
	});
});

function sspa_impact_cell(r) {
	if (r.confidence !== 'measured') {
		return '<span class="sspa-impact-noise">' + sspa_esc(wp.i18n.sprintf(/* translators: Noise threshold in milliseconds. */ wp.i18n.__("within ±%sms noise", "super-speedy-performance-analysis"), Math.round(r.noise_floor_ms))) + '</span>';
	}
	var d = parseFloat(r.delta_ttfb_ms);
	var cls = d < 0 ? 'sspa-impact-saves' : 'sspa-impact-adds';
	var label = wp.i18n.sprintf(d < 0 ? /* translators: Time saved in milliseconds. */ wp.i18n.__("saves %sms", "super-speedy-performance-analysis") : /* translators: Time added in milliseconds. */ wp.i18n.__("adds %sms", "super-speedy-performance-analysis"), Math.abs(Math.round(d)));
	var sql = Math.round(parseFloat(r.delta_sql_ms));
	var q = parseInt(r.delta_queries, 10);
	var sub = wp.i18n.sprintf(/* translators: 1: signed SQL-time difference in milliseconds, 2: signed query-count difference. */ wp.i18n.__("SQL %1$sms · %2$s queries", "super-speedy-performance-analysis"), (sql >= 0 ? '+' : '−') + Math.abs(sql), (q >= 0 ? '+' : '−') + Math.abs(q));
	return '<strong class="' + cls + '">' + sspa_esc(label) + '</strong><br><small>' + sspa_esc(sub) + '</small>';
}

// ---- Prune stored blobs ----

jQuery(document).on('click', '#sspa-prune-blobs', function () {
	var keep = jQuery(this).data('keep');
	if (!confirm(wp.i18n.sprintf(/* translators: Number of recent runs to retain. */ wp.i18n.__("Delete detailed per-query data for all but the last %s runs?\n\nSummary metrics, findings and history are always kept. If sharing is enabled, every affected run is first saved to the durable local submission queue.", "super-speedy-performance-analysis"), keep))) {
		return;
	}
	jQuery.post(ajaxurl, { action: 'sspa_prune_blobs', nonce: sspa_admin.nonce }, function (resp) {
		if (resp.success) {
			alert(wp.i18n.sprintf(/* translators: Formatted storage size. */ wp.i18n.__("Done. Detailed data now uses %s.", "super-speedy-performance-analysis"), resp.data.human));
			sspa_refresh_tabs(['overview', 'tools', 'history', 'share']);
		}
	});
});

// ---- Share tab ----

jQuery(document).on('change', '#sspa-share-optin', function () {
	var optin = jQuery(this).is(':checked') ? 1 : 0;
	jQuery.post(ajaxurl, { action: 'sspa_share_optin', nonce: sspa_admin.nonce, optin: optin }, function (resp) {
		if (!resp.success) {
			alert(resp.data || wp.i18n.__("Could not update sharing consent.", "super-speedy-performance-analysis"));
			sspa_refresh_tabs(['share']);
			return;
		}
		jQuery('.sspa-sharing-action').prop('disabled', !optin);
	});
});

// Per-plugin publishing. Independent of the site-wide setting above: a site owner can let one
// plugin publish its settings and refuse another without turning sharing off altogether.
jQuery(document).on('change', '.sspa-publisher-toggle', function () {
	var box = jQuery(this);
	var enabled = box.is(':checked') ? 1 : 0;
	jQuery.post(ajaxurl, {
		action: 'sspa_publisher_toggle',
		nonce: sspa_admin.nonce,
		slug: box.val(),
		enabled: enabled
	}, function (resp) {
		if (!resp.success) {
			alert(resp.data || wp.i18n.__("Could not update this plugin.", "super-speedy-performance-analysis"));
			// Put the box back where it was rather than leaving the screen claiming something
			// the site does not believe.
			box.prop('checked', !enabled);
		}
	}).fail(function () {
		alert(wp.i18n.__("Could not update this plugin.", "super-speedy-performance-analysis"));
		box.prop('checked', !enabled);
	});
});

jQuery(document).on('click', '.sspa-preview-outbox', function () {
	var panel = jQuery(this).closest('.tab-contents');
	var pre = panel.find('.sspa-payload-preview');
	var summary = panel.find('.sspa-payload-summary');
	var outboxId = jQuery(this).data('outbox-id') || 0;
	if (pre.is(':visible') && pre.data('outbox-id') === outboxId) {
		pre.hide();
		summary.hide();
		return;
	}
	pre.data('outbox-id', outboxId).text(wp.i18n.__("Building the exact payload…", "super-speedy-performance-analysis")).show();
	summary.hide();
	jQuery.post(ajaxurl, { action: 'sspa_payload_preview', nonce: sspa_admin.nonce, outbox_id: outboxId }, function (resp) {
		if (!resp.success) {
			pre.text(resp.data || wp.i18n.__("Could not build the payload.", "super-speedy-performance-analysis"));
			return;
		}
		pre.text(resp.data.payload);
		summary.html(sspa_payload_summary_html(resp.data)).show();
		// Download is built from the payload already in hand - no second request, and the file
		// is byte-identical to what was shown.
		summary.find('.sspa-download-payload').on('click', function (e) {
			e.preventDefault();
			var blob = new Blob([resp.data.payload], { type: 'application/json' });
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = resp.data.filename || ((sspa_admin.download_prefix || '') + 'sspa-shared-data.json');
			document.body.appendChild(a);
			a.click();
			document.body.removeChild(a);
			URL.revokeObjectURL(a.href);
		});
	});
});

// Plain English above the JSON. Most people do not read JSON, and they are exactly the people
// a privacy promise has to convince.
function sspa_payload_summary_html(data) {
	var s = data.summary || {};
	var kb = Math.max(1, Math.round((data.bytes || 0) / 1024));
	var heading = s.run_type ? wp.i18n.sprintf(/* translators: 1: analysis type, 2: payload size in kilobytes. */ wp.i18n.__("This is everything that would be sent for this %1$s analysis (%2$s KB).", "super-speedy-performance-analysis"), s.run_type, kb) : wp.i18n.sprintf(/* translators: Payload size in kilobytes. */ wp.i18n.__("This is everything that would be sent (%s KB).", "super-speedy-performance-analysis"), kb);
	var html = '<p><strong>' + sspa_esc(heading) + '</strong></p>';
	if (s.includes && s.includes.length) {
		var contents = s.includes.join(', ');
		if (s.components) { contents = wp.i18n.sprintf(/* translators: 1: list of included fields, 2: number of active components. */ wp.i18n.__("%1$s, and the names and versions of %2$s active components", "super-speedy-performance-analysis"), contents, s.components); }
		html += '<p>' + sspa_esc(wp.i18n.sprintf(/* translators: List of included fields. */ wp.i18n.__("It contains: %s.", "super-speedy-performance-analysis"), contents)) + '</p>';
	}
	if (s.state_components && s.state_components.length) {
		html += '<p>' + sspa_esc(wp.i18n.sprintf(/* translators: List of plugin names. */ wp.i18n.__("These plugins have opted in to publishing their own performance settings: %s.", "super-speedy-performance-analysis"), s.state_components.join(', '))) + '</p>';
	}
	if (s.excludes && s.excludes.length) {
		html += '<p>' + sspa_esc(wp.i18n.sprintf(/* translators: List of excluded fields. */ wp.i18n.__("It does not contain %s.", "super-speedy-performance-analysis"), s.excludes.join('; '))) + '</p>';
	}
	html += '<p><a href="#" class="button button-small sspa-download-payload">' + sspa_esc(wp.i18n.__("Download this file", "super-speedy-performance-analysis")) + '</a></p>';
	return html;
}

// Share one analysis on its own. Deliberately no confirm()/alert(): the outcome is reported
// in the cell itself, so the answer stays next to the run it refers to.
jQuery(document).on('click', '.sspa-share-run', function () {
	var btn = jQuery(this).prop('disabled', true);
	var cell = btn.closest('.sspa-share-run-cell');
	cell.find('.sspa-share-run-result').remove();
	btn.after(' <span class="sspa-share-run-result description">' + sspa_esc(wp.i18n.__("Preparing the anonymised payload…", "super-speedy-performance-analysis")) + '</span>');
	jQuery.post(ajaxurl, {
		action: 'sspa_share_run',
		nonce: sspa_admin.nonce,
		run_id: btn.data('run-id')
	}, function (resp) {
		if (!resp.success) {
			cell.find('.sspa-share-run-result').text(resp.data || wp.i18n.__("Could not share this analysis.", "super-speedy-performance-analysis"));
			btn.prop('disabled', false);
			return;
		}
		btn.remove();
		cell.find('.sspa-share-run-result').html(
			sspa_esc(wp.i18n.sprintf(/* translators: Compressed payload size in bytes. */ wp.i18n.__("Queued to share (this run only) - %s bytes.", "super-speedy-performance-analysis"), resp.data.compressed_bytes)) + ' ' +
			'<button type="button" class="button button-small sspa-preview-outbox" data-outbox-id="' + resp.data.outbox_id + '">' + sspa_esc(wp.i18n.__("Preview data", "super-speedy-performance-analysis")) + '</button>'
		);
		sspa_drive_submissions();
	}).fail(function () {
		cell.find('.sspa-share-run-result').text(wp.i18n.__("Could not share this analysis.", "super-speedy-performance-analysis"));
		btn.prop('disabled', false);
	});
});

jQuery(document).on('click', '#sspa-submit-now', function () {
	var btn = jQuery(this).prop('disabled', true);
	jQuery.post(ajaxurl, { action: 'sspa_submit_now', nonce: sspa_admin.nonce }, function (resp) {
		alert(resp.success ? wp.i18n.__("Queued locally. Delivery runs in the background and retries automatically.", "super-speedy-performance-analysis") : (resp.data || wp.i18n.__("Could not queue the submission.", "super-speedy-performance-analysis")));
		sspa_refresh_tabs(['share', 'history'], function () { btn.prop('disabled', false); });
		sspa_drive_submissions();
	}).fail(function () {
		alert(wp.i18n.__("Could not queue the submission.", "super-speedy-performance-analysis"));
		btn.prop('disabled', false);
	});
});

jQuery(document).on('click', '#sspa-backfill', function () {
	var btn = jQuery(this).prop('disabled', true);
	var status = jQuery('#sspa-backfill-status');
	var restart = parseInt(btn.data('restart'), 10) ? 1 : 0;
	var totalQueued = 0;
	var totalFailed = 0;

	function nextBatch(first) {
		status.text(wp.i18n.__("Building a bounded batch of historical payloads…", "super-speedy-performance-analysis"));
		jQuery.post(ajaxurl, {
			action: 'sspa_community_backfill',
			nonce: sspa_admin.nonce,
			restart: first ? restart : 0
		}, function (resp) {
			if (!resp.success) {
				status.text(resp.data || wp.i18n.__("Historical queueing failed.", "super-speedy-performance-analysis"));
				btn.prop('disabled', false);
				return;
			}
			totalQueued += parseInt(resp.data.queued, 10) || 0;
			totalFailed += (resp.data.failed || []).length;
			status.text(wp.i18n.sprintf(/* translators: 1: number queued, 2: number of historical runs remaining. */ wp.i18n.__("Queued %1$s; %2$s historical run(s) remain.", "super-speedy-performance-analysis"), totalQueued, resp.data.inventory.remaining));
			if (!resp.data.complete) {
				window.setTimeout(function () { nextBatch(false); }, 250);
				return;
			}
			alert(wp.i18n.sprintf(/* translators: 1: number queued, 2: number needing review. */ wp.i18n.__("Historical queueing finished: %1$s queued, %2$s requiring review.", "super-speedy-performance-analysis"), totalQueued, totalFailed));
			sspa_refresh_tabs(['share', 'history']);
			sspa_drive_submissions();
		}).fail(function () {
			status.text(wp.i18n.__("Historical queueing request failed. Progress has been saved; press the button to resume.", "super-speedy-performance-analysis"));
			btn.prop('disabled', false);
		});
	}

	nextBatch(true);
});

jQuery(document).on('click', '.sspa-outbox-action', function () {
	var btn = jQuery(this);
	var operation = btn.data('operation');
	if (operation === 'pause' && !confirm(wp.i18n.__("Pause this submission? Its exact local payload will be retained and can be resumed later.", "super-speedy-performance-analysis"))) {
		return;
	}
	btn.prop('disabled', true);
	jQuery.post(ajaxurl, {
		action: 'sspa_outbox_action',
		nonce: sspa_admin.nonce,
		outbox_id: btn.data('outbox-id'),
		operation: operation
	}, function (resp) {
		if (!resp.success) {
			alert(resp.data || wp.i18n.__("Could not update the submission.", "super-speedy-performance-analysis"));
			btn.prop('disabled', false);
			return;
		}
		sspa_refresh_tabs(['share', 'history']);
		sspa_drive_submissions();
	}).fail(function () {
		alert(wp.i18n.__("Could not update the submission.", "super-speedy-performance-analysis"));
		btn.prop('disabled', false);
	});
});

// ---- Run Analysis ----

jQuery(document).on('click', '#sspa-run-analysis', function () {
	sspa_start_typed_run({}, jQuery(this));
});

// Plugin Impact Analysis never starts from one press any more. The button opens the plugin
// picker (rendered by sspa-adhoc.js into the panel shell): nothing is measured until the
// site owner ticks the plugins themselves, with the estimate and the caution in front of
// them. Measuring a plugin means excluding it from test requests, and which plugins that is
// acceptable for is their call, not a default.
jQuery(document).on('click', '#sspa-run-deep', function () {
	if (window.sspaPanel && window.sspaPanel.openImpactPicker) {
		window.sspaPanel.openImpactPicker();
	}
});

jQuery(document).on('click', '#sspa-run-cache', function () {
	sspa_start_typed_run({ type: 'cache_impact' }, jQuery(this));
});

jQuery(document).on('click', '.sspa-measure-plugin', function () {
	var plugin = jQuery(this).data('plugin');
	if (!confirm(wp.i18n.sprintf(/* translators: Plugin identifier. */ wp.i18n.__("Measure \"%s\" on every profiled page with the plugin disabled for the test requests only? Visitors are unaffected.", "super-speedy-performance-analysis"), plugin))) {
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
			alert(resp.data || wp.i18n.__("Could not start the analysis.", "super-speedy-performance-analysis"));
			btn.prop('disabled', false);
			return;
		}
		// No tab switch, no reload: the floating monitor shows progress wherever you are.
		sspa_drive_run(resp.data.run_id);
	}).fail(function () {
		alert(wp.i18n.__("Could not start the analysis (request failed).", "super-speedy-performance-analysis"));
		btn.prop('disabled', false);
	});
}

jQuery(document).on('click', '#sspa-cancel-run, #sspa-runner-cancel', function () {
	if (!confirm(wp.i18n.__("Cancel the running analysis?", "super-speedy-performance-analysis"))) {
		return;
	}
	jQuery.post(ajaxurl, { action: 'sspa_cancel_run', nonce: sspa_admin.nonce }, function () {
		sspa_runner_dismiss();
		sspa_refresh_tabs(['overview', 'history']);
	});
});

// ---- Floating run monitor ----

jQuery(document).on('click', '#sspa-runner .sspa-runner-head', function (e) {
	if (jQuery(e.target).is('#sspa-runner-cancel')) {
		return;
	}
	var runner = jQuery('#sspa-runner');
	runner.toggleClass('sspa-runner-min');
	sspa_runner_backdrop(runner);
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
	sspa_runner_backdrop(runner);
}

// The dimmer belongs to the centred panel only. Minimised, the screen has to stay usable.
function sspa_runner_backdrop(runner) {
	var wanted = runner.is(':visible') && !runner.hasClass('sspa-runner-min');
	var backdrop = jQuery('#sspa-runner-backdrop');
	if (wanted && !backdrop.length) {
		jQuery('<div id="sspa-runner-backdrop">').insertBefore(runner);
	} else if (!wanted) {
		backdrop.remove();
	}
}

function sspa_fmt_duration(seconds) {
	if (seconds === null || seconds === undefined || isNaN(seconds)) {
		return null;
	}
	seconds = Math.max(0, Math.round(seconds));
	var h = Math.floor(seconds / 3600);
	var m = Math.floor((seconds % 3600) / 60);
	if (h > 0) {
		return wp.i18n.sprintf(/* translators: 1: hours, 2: minutes. */ wp.i18n.__("%1$sh %2$sm", "super-speedy-performance-analysis"), h, m);
	}
	if (m > 0) {
		return wp.i18n.sprintf(/* translators: Minutes. */ wp.i18n.__("%sm", "super-speedy-performance-analysis"), m);
	}
	return wp.i18n.sprintf(/* translators: Seconds. */ wp.i18n.__("%ss", "super-speedy-performance-analysis"), seconds);
}

// Highest job index already accepted into the feed, so a poll only queues what is new.
// A fast site can still finish two measurements between status polls; reveal those in order
// instead of making the monitor appear frozen and then dumping several lines at once.
var sspa_feed_seen = -1;
var sspa_feed_queue = [];
var sspa_feed_timer = null;
var sspa_feed_current = null;

function sspa_runner_feed_tail(feed, current) {
	var el = feed.get(0);
	var following = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
	feed.find('.sspa-feed-now').remove();
	if (current) {
		feed.append(jQuery('<li class="sspa-feed-now">').text(current + ' …'));
	}
	if (following) {
		el.scrollTop = el.scrollHeight;
	}
}

function sspa_runner_feed_drain() {
	var feed = jQuery('#sspa-runner .sspa-runner-feed');
	if (!feed.length || !sspa_feed_queue.length) {
		sspa_feed_timer = null;
		if (feed.length) {
			sspa_runner_feed_tail(feed, sspa_feed_current);
		}
		return;
	}

	var el = feed.get(0);
	var following = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
	feed.find('.sspa-feed-now').remove();
	feed.append(jQuery('<li>').text(sspa_feed_queue.shift().label));
	if (sspa_feed_current) {
		feed.append(jQuery('<li class="sspa-feed-now">').text(sspa_feed_current + ' …'));
	}
	if (following) {
		el.scrollTop = el.scrollHeight;
	}

	sspa_feed_timer = setTimeout(sspa_runner_feed_drain, 140);
}

function sspa_runner_feed(s) {
	var feed = jQuery('#sspa-runner .sspa-runner-feed');
	if (!feed.length) {
		return;
	}
	sspa_feed_current = s.current || null;

	(s.recent || []).forEach(function (item) {
		if (item.i > sspa_feed_seen) {
			sspa_feed_seen = item.i;
			sspa_feed_queue.push(item);
		}
	});

	if (!sspa_feed_timer && sspa_feed_queue.length) {
		sspa_runner_feed_drain();
	} else {
		// The in-flight measurement sits at the bottom until it completes and arrives in recent.
		sspa_runner_feed_tail(feed, sspa_feed_current);
	}
}

function sspa_runner_update(s) {
	var runner = jQuery('#sspa-runner');
	var pct = s.total ? Math.min(100, Math.round((s.done / s.total) * 100)) : 0;
	runner.find('.sspa-progress-fill').css('width', pct + '%');
	// The phase belongs next to the number, not only in the title: a total that jumps from 72
	// to 216 mid-run reads as a bug unless the screen says why.
	var counts = wp.i18n.sprintf(/* translators: 1: completed count, 2: total count, 3: progress percentage. */ wp.i18n.__("%1$s / %2$s measurements (%3$s%%)", "super-speedy-performance-analysis"), s.done, s.total, pct);
	if (s.run_type === 'deep' && s.phase) {
		counts = (s.phase === 1 ? wp.i18n.__("Phase 1/2, screening", "super-speedy-performance-analysis") : wp.i18n.__("Phase 2/2, confirming", "super-speedy-performance-analysis")) + ' \u00b7 ' + counts;
	}
	runner.find('.sspa-runner-counts').text(counts);
	runner.find('.sspa-runner-current').text(s.current ? wp.i18n.sprintf(/* translators: Current measurement label. */ wp.i18n.__("Now testing: %s", "super-speedy-performance-analysis"), s.current) : (s.status === 'analysing' ? wp.i18n.__("Analysing results…", "super-speedy-performance-analysis") : ''));
	sspa_runner_feed(s);
	var eta = sspa_fmt_duration(s.eta_seconds);
	var elapsed = sspa_fmt_duration(s.elapsed_seconds);
	var bits = [];
	if (elapsed) {
		bits.push(wp.i18n.sprintf(/* translators: Formatted elapsed duration. */ wp.i18n.__("Elapsed %s", "super-speedy-performance-analysis"), elapsed));
	}
	if (eta) {
		// Phase 1 is the fast screen; phase 2 length depends on what it finds.
		bits.push(wp.i18n.sprintf(s.run_type === 'deep' && s.phase === 1 ? /* translators: Formatted remaining duration. */ wp.i18n.__("~%s left in screening", "super-speedy-performance-analysis") : /* translators: Formatted remaining duration. */ wp.i18n.__("~%s left", "super-speedy-performance-analysis"), eta));
	}
	runner.find('.sspa-runner-eta').text(bits.join(' · '));
	runner.find('.sspa-runner-mini-summary').text(pct + '%' + (eta ? ' · ' + wp.i18n.sprintf(/* translators: Formatted remaining duration. */ wp.i18n.__("~%s left", "super-speedy-performance-analysis"), eta) : ''));
	var title = { deep: wp.i18n.__("Plugin impact analysis running", "super-speedy-performance-analysis"), cache_impact: wp.i18n.__("Cache impact analysis running", "super-speedy-performance-analysis") }[s.run_type] || wp.i18n.__("Analysis running", "super-speedy-performance-analysis");
	if (s.run_type === 'deep' && s.phase) {
		title += s.phase === 1 ? wp.i18n.__(" - phase 1/2: screening all plugins", "super-speedy-performance-analysis") : wp.i18n.__(" - phase 2/2: confirming impacted plugins", "super-speedy-performance-analysis");
	}
	runner.find('.sspa-runner-title').text(title);
}

// Take the panel down and release the dimmer, without touching the page.
function sspa_runner_dismiss() {
	var runner = jQuery('#sspa-runner').hide().removeClass('sspa-runner-min');
	runner.find('.sspa-runner-current, .sspa-runner-eta, .sspa-runner-actions').show();
	runner.find('.sspa-runner-feed').empty();
	sspa_feed_seen = -1;
	sspa_feed_queue = [];
	sspa_feed_current = null;
	if (sspa_feed_timer) {
		clearTimeout(sspa_feed_timer);
		sspa_feed_timer = null;
	}
	sspa_runner_backdrop(runner);
}

function sspa_runner_finish(status) {
	var runner = jQuery('#sspa-runner').removeClass('sspa-runner-min');
	var label = status === 'done' ? wp.i18n.__("Analysis complete ✓ loading results…", "super-speedy-performance-analysis") : wp.i18n.sprintf(/* translators: Analysis status returned by the server. */ wp.i18n.__("Analysis %s - loading results…", "super-speedy-performance-analysis"), status);
	runner.find('.sspa-runner-title').text(label);
	runner.find('.sspa-runner-mini-summary').text('');
	runner.find('.sspa-progress-fill').css('width', '100%');
	runner.find('.sspa-runner-current, .sspa-runner-eta, .sspa-runner-actions').hide();
	sspa_runner_backdrop(runner);
	// Every tab a run can change. The results appear under whichever tab the user is already
	// looking at, rather than throwing them back to Overview via a reload.
	sspa_refresh_tabs(['overview', 'pages', 'plugins', 'history', 'share'], function () {
		sspa_runner_dismiss();
		sspa_drive_submissions();
	});
}

// The browser drives batches sequentially; WP-Cron is the backup for headless progress,
// and the hourly janitor re-kicks a run whose driver disappeared.
//
// Browser-transport runs (loopbacks blocked at preflight - basic auth, WAF, CDN): the
// server-side pump no-ops while SSPATransport.drive() fetches pages from THIS browser one
// request at a time; the independent status poll below monitors both transport modes.
function sspa_drive_run(runId) {
	sspa_runner_show();
	jQuery('#sspa-run-analysis, #sspa-run-deep, #sspa-run-cache, .sspa-measure-plugin').prop('disabled', true);
	jQuery('#sspa-cancel-run').show();

	var failures = 0;
	var browserDriver = null;
	var stopped = false;
	var batchTimer = null;
	var statusTimer = null;
	var statusRequest = null;

	function finish(status) {
		if (stopped) {
			return;
		}
		stopped = true;
		if (batchTimer) {
			clearTimeout(batchTimer);
		}
		if (statusTimer) {
			clearTimeout(statusTimer);
		}
		if (statusRequest) {
			statusRequest.abort();
		}
		if (browserDriver) {
			browserDriver.stop();
		}
		sspa_runner_finish(status);
	}

	// process_batch deliberately keeps one request busy for up to 15 seconds. Poll the
	// read-only status endpoint alongside it so every saved measurement reaches the monitor
	// as it completes instead of all completed measurements arriving with the batch response.
	function pollStatus() {
		if (stopped) {
			return;
		}
		statusRequest = jQuery.post(ajaxurl, { action: 'sspa_run_status', nonce: sspa_admin.nonce, run_id: runId }, function (resp) {
			if (stopped || !resp.success || !resp.data) {
				return;
			}
			var s = resp.data;
			sspa_runner_update(s);
			if (s.status !== 'crawling' && s.status !== 'analysing') {
				finish(s.status);
			}
		}).always(function () {
			statusRequest = null;
			if (!stopped) {
				statusTimer = setTimeout(pollStatus, 350);
			}
		});
	}

	function step() {
		if (stopped) {
			return;
		}
		jQuery.post(ajaxurl, { action: 'sspa_process_batch', nonce: sspa_admin.nonce, run_id: runId }, function (resp) {
			if (stopped) {
				return;
			}
			failures = 0;
			if (!resp.success || !resp.data) {
				finish('finished');
				return;
			}
			var s = resp.data;
			sspa_runner_update(s);
			if (s.status === 'crawling' || s.status === 'analysing') {
				if (s.transport === 'browser' && s.status === 'crawling' && !browserDriver && window.SSPATransport) {
					console.info('[SSPA] loopbacks are blocked on this site - this browser is fetching the pages itself');
					browserDriver = SSPATransport.drive({
						ajaxurl: ajaxurl,
						nonce: sspa_admin.nonce,
						runId: runId,
						onFail: function (message) {
							console.error('[SSPA] browser transport stopped: ' + message);
						}
					});
				}
				batchTimer = setTimeout(step, 400);
			} else {
				finish(s.status);
			}
		}).fail(function () {
			if (stopped) {
				return;
			}
			// Transient network/server hiccups must not kill an hours-long run.
			failures++;
			jQuery('#sspa-runner .sspa-runner-current').text(failures > 1 ? wp.i18n.sprintf(/* translators: Consecutive connection-failure count. */ wp.i18n.__("Connection hiccup, retrying… (%s)", "super-speedy-performance-analysis"), failures) : '');
			batchTimer = setTimeout(step, Math.min(30000, 3000 * failures));
		});
	}

	pollStatus();
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
