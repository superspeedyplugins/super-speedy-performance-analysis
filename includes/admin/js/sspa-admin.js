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
		html += '</div>';
		detail.children('td').html(html);
	});
});

function sspa_esc(str) {
	return jQuery('<span>').text(str == null ? '' : String(str)).html();
}

// ---- Prune stored blobs ----

jQuery(document).on('click', '#sspa-prune-blobs', function () {
	var keep = jQuery(this).data('keep');
	if (!confirm('Delete detailed per-query data for all but the last ' + keep + ' runs?\n\nSummary metrics, findings and history are always kept. Soon you will be able to contribute this data anonymously to the community database at superspeedy.org before deleting - watch the Share tab.')) {
		return;
	}
	jQuery.post(ajaxurl, { action: 'sspa_prune_blobs', nonce: sspa_admin.nonce }, function (resp) {
		if (resp.success) {
			alert('Done. Detailed data now uses ' + resp.data.human + '.');
			window.location.reload();
		}
	});
});

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
