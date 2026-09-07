(function ($) {
	'use strict';
	var request = null;
	var serial = 0;
	var opener = null;
	var listScroll = 0;

	function selectedId() {
		var raw = new URL(window.location.href).searchParams.get('sspa_history_run');
		return raw && /^[1-9][0-9]*$/.test(raw) ? raw : '';
	}

	function route(id) {
		var url = new URL(window.location.href);
		if (id) { url.searchParams.set('sspa_history_run', id); }
		else { url.searchParams.delete('sspa_history_run'); }
		url.hash = 'history';
		window.history.pushState(null, '', url.href);
	}

	function closeReport() {
		serial++;
		if (request) { request.abort(); request = null; }
		$('#sspa-history-saved-run').prop('hidden', true).removeAttr('aria-busy').empty();
		$('#sspa-history-list').prop('hidden', false);
		if (opener && document.contains(opener)) {
			opener.focus({ preventScroll: true });
			window.scrollTo(0, listScroll);
		}
	}

	function openReport(id) {
		var view = $('#sspa-history-saved-run');
		if (!view.length) { return; }
		var token = ++serial;
		if (request) { request.abort(); }
		$('#sspa-history-list').prop('hidden', true);
		view.prop('hidden', false).attr('aria-busy', 'true').empty();
		$('<button>', { type: 'button', class: 'button sspa-history-back', text: sspa_history_run.back }).appendTo(view);
		var status = $('<p>', { role: 'status', tabindex: -1, text: sspa_history_run.loading }).appendTo(view);
		status.get(0).focus();
		function failed(message) {
			view.removeAttr('aria-busy');
			status.attr('role', 'alert').text(message || sspa_history_run.failed);
			$('<button>', { type: 'button', class: 'button sspa-history-retry', text: sspa_history_run.retry }).attr('data-run-id', id).appendTo(view);
		}
		request = $.post(ajaxurl, { action: 'sspa_history_run', nonce: sspa_admin.nonce, run_id: id })
			.done(function (response) {
				if (token !== serial) { return; }
				if (!response || !response.success || !response.data || String(response.data.run_id) !== String(id)) {
					failed(response && typeof response.data === 'string' ? response.data : '');
					return;
				}
				view.removeAttr('aria-busy').html(response.data.html);
				view.find('h2[tabindex]').first().trigger('focus');
			})
			.fail(function (xhr, state) {
				if (token !== serial || state === 'abort') { return; }
				var data = xhr.responseJSON && xhr.responseJSON.data;
				failed(typeof data === 'string' ? data : '');
			});
	}

	$(document).on('click', '.sspa-history-run-link', function (event) {
		if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.which > 1) { return; }
		event.preventDefault();
		opener = this;
		listScroll = window.scrollY;
		var id = String($(this).data('run-id'));
		route(id);
		openReport(id);
	});
	$(document).on('click', '.sspa-history-back', function (event) {
		event.preventDefault();
		route('');
		closeReport();
	});
	$(document).on('click', '.sspa-history-retry', function () { openReport(String($(this).data('run-id'))); });
	$(document).on('click', '.sspa-history-profile', function () {
		if (window.sspaPanel && window.sspaPanel.openProfile) {
			window.sspaPanel.openProfile($(this).data('profile-id'));
		} else {
			$('#sspa-history-saved-run .sspa-history-profile-status').text(sspa_history_run.profile_unavailable);
		}
	});
	$(document).on('sspa:tab-rendered', function (event, slug) {
		if (slug === 'history' && selectedId()) { openReport(selectedId()); }
	});
	$(window).on('popstate', function () {
		if (selectedId()) {
			sspa_click_tab('history');
			openReport(selectedId());
		} else { closeReport(); }
	});
	$(function () {
		if (selectedId()) {
			sspa_click_tab('history');
			openReport(selectedId());
		}
	});
})(jQuery);
