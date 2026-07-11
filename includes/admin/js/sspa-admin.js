jQuery(function () {
	var hash = window.location.hash.replace('#', '');
	sspa_click_tab(hash || 'overview');

	// Resume progress display if a run is already active when the page loads.
	var active = parseInt(jQuery('#sspa-run-panel').data('active-run'), 10);
	if (active) {
		sspa_drive_run(active);
	}
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

// ---- Run Analysis ----

jQuery(document).on('click', '#sspa-run-analysis', function () {
	var btn = jQuery(this);
	btn.prop('disabled', true);
	jQuery.post(ajaxurl, {
		action: 'sspa_start_run',
		nonce: sspa_admin.nonce,
		swap_dropin: jQuery('#sspa-swap-dropin').is(':checked') ? 1 : 0
	}, function (resp) {
		if (!resp.success) {
			alert(resp.data || 'Could not start the analysis.');
			btn.prop('disabled', false);
			return;
		}
		jQuery('#sspa-cancel-run').show();
		sspa_drive_run(resp.data.run_id);
	});
});

jQuery(document).on('click', '#sspa-cancel-run', function () {
	jQuery.post(ajaxurl, { action: 'sspa_cancel_run', nonce: sspa_admin.nonce }, function () {
		window.location.reload();
	});
});

// The browser drives batches sequentially; WP-Cron is the backup for headless progress.
function sspa_drive_run(runId) {
	jQuery('#sspa-progress').show();
	jQuery('#sspa-run-analysis').prop('disabled', true);
	jQuery('#sspa-cancel-run').show();

	function step() {
		jQuery.post(ajaxurl, { action: 'sspa_process_batch', nonce: sspa_admin.nonce, run_id: runId }, function (resp) {
			if (!resp.success || !resp.data) {
				window.location.reload();
				return;
			}
			var s = resp.data;
			var pct = s.total ? Math.round((s.done / s.total) * 100) : 0;
			jQuery('#sspa-progress .sspa-progress-fill').css('width', pct + '%');
			jQuery('#sspa-progress .sspa-progress-text').text(
				s.status === 'crawling'
					? s.done + ' / ' + s.total + (s.current ? ' - profiling: ' + s.current : '')
					: s.status
			);
			if (s.status === 'crawling') {
				setTimeout(step, 500);
			} else {
				window.location.reload();
			}
		}).fail(function () {
			setTimeout(step, 3000);
		});
	}
	step();
}
