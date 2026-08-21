(function ($) {
	'use strict';

	var cfg = window.sspa_workflows || {};
	var running = false;

	function status(message, failed) {
		$('#sspa-workflow-status').text(message || '').toggleClass('sspa-workflow-error', !!failed);
	}

	function post(action, data) {
		return $.post(cfg.ajaxurl, $.extend({ action: action, nonce: cfg.nonce }, data || {}));
	}

	function fill(select, rows, emptyLabel) {
		select.empty();
		(rows || []).forEach(function (row) {
			select.append($('<option>').val(row.id == null ? row.key : row.id).text(row.label));
		});
		if (!rows || !rows.length) {
			select.append($('<option>').val('').text(emptyLabel));
		}
		select.prop('disabled', !rows || !rows.length);
	}

	function loadTargets() {
		var type = $('#sspa-workflow-object-type').val();
		var target = $('#sspa-workflow-object-id');
		var transport = $('#sspa-workflow-transport');
		$('#sspa-workflow-run').prop('disabled', true);
		target.prop('disabled', true);
		transport.prop('disabled', true);
		status(cfg.i18n.loading);
		post('sspa_workflow_targets', { object_type: type }).done(function (response) {
			if (!response || !response.success) {
				status((response && response.data && response.data.message) || cfg.i18n.failed, true);
				return;
			}
			fill(target, response.data.targets, cfg.i18n.no_targets);
			fill(transport, response.data.transports, cfg.i18n.no_transports);
			var ready = response.data.targets.length && response.data.transports.length;
			$('#sspa-workflow-run').prop('disabled', !ready);
			status(ready ? cfg.i18n.ready : cfg.i18n.no_targets, !ready);
		}).fail(function () {
			status(cfg.i18n.failed, true);
		});
	}

	function run() {
		if (running) {
			return;
		}
		running = true;
		$('#sspa-workflow-run').prop('disabled', true);
		status(cfg.i18n.launching);
		post('sspa_workflow_launch', {
			object_type: $('#sspa-workflow-object-type').val(),
			object_id: $('#sspa-workflow-object-id').val(),
			transport: $('#sspa-workflow-transport').val(),
			suppress_mail: $('#sspa-workflow-suppress-mail').is(':checked') ? 1 : 0
		}).done(function (response) {
			if (!response || !response.success) {
				running = false;
				$('#sspa-workflow-run').prop('disabled', false);
				status((response && response.data && response.data.message) || cfg.i18n.failed, true);
				return;
			}
			status(cfg.i18n.running);
			$('#sspa-workflow-frame').attr('src', response.data.editor_url);
		}).fail(function () {
			running = false;
			$('#sspa-workflow-run').prop('disabled', false);
			status(cfg.i18n.failed, true);
		});
	}

	window.addEventListener('message', function (event) {
		if (event.origin !== window.location.origin || !event.data || 'sspa-workflow' !== event.data.source) {
			return;
		}
		if ('error' === event.data.type) {
			running = false;
			$('#sspa-workflow-run').prop('disabled', false);
			status(event.data.message || cfg.i18n.failed, true);
			return;
		}
		if ('complete' !== event.data.type) {
			return;
		}
		running = false;
		$('#sspa-workflow-run').prop('disabled', false);
		$('#sspa-workflow-frame').attr('src', 'about:blank');
		status(cfg.i18n.complete);
		if (window.sspaPanel && window.sspaPanel.openProfile) {
			window.sspaPanel.openProfile(event.data.profile_id);
		}
	});

	$(document).on('change', '#sspa-workflow-object-type', loadTargets);
	$(document).on('click', '#sspa-workflow-run', run);
	$(function () {
		if ($('#sspa-workflow-object-type').length) {
			loadTargets();
		}
	});
})(jQuery);
