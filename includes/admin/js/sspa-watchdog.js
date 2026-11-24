/* Update watchdog settings (Pro). This file is deleted from the wordpress.org build. */
(function ($) {
	'use strict';
	var __ = wp.i18n.__;

	function box(el) {
		return $(el).closest('#sspa-watchdog-settings');
	}

	function busy(fieldset, on) {
		fieldset.find('button').prop('disabled', on);
		fieldset.find('.spinner').toggleClass('is-active', on);
	}

	function say(fieldset, text) {
		fieldset.find('.sspa-watchdog-feedback').text(text);
	}

	$(document).on('click', '#sspa-watchdog-settings .sspa-watchdog-save', function () {
		var fieldset = box(this);
		var data = {
			action: 'sspa_watchdog_settings',
			nonce: sspa_admin.nonce,
			enabled: fieldset.find('[name="enabled"]').is(':checked') ? 1 : 0,
			checkout: fieldset.find('[name="checkout"]').is(':checked') ? 1 : 0,
			recipients: fieldset.find('[name="recipients"]').val(),
			threshold_percent: fieldset.find('[name="threshold_percent"]').val(),
			threshold_ms: fieldset.find('[name="threshold_ms"]').val(),
			weekly_day: fieldset.find('[name="weekly_day"]').val()
		};
		busy(fieldset, true);
		say(fieldset, '');
		$.post(ajaxurl, data).done(function (resp) {
			if (resp && resp.success) {
				say(fieldset, __('Watchdog settings saved.', 'super-speedy-performance-analysis'));
			} else {
				say(fieldset, SSPAFeedback.response(resp, __('Could not save the watchdog settings.', 'super-speedy-performance-analysis')));
			}
		}).fail(function (xhr, textStatus) {
			say(fieldset, SSPAFeedback.xhr(xhr, __('Could not save the watchdog settings.', 'super-speedy-performance-analysis'), textStatus));
		}).always(function () {
			busy(fieldset, false);
		});
	});

	$(document).on('click', '#sspa-watchdog-settings .sspa-watchdog-run-now', function () {
		var fieldset = box(this);
		busy(fieldset, true);
		say(fieldset, '');
		$.post(ajaxurl, { action: 'sspa_watchdog_run_now', nonce: sspa_admin.nonce }).done(function (resp) {
			if (resp && resp.success) {
				say(fieldset, __('The watchdog is checking the site. It runs in the background; you can leave this screen, and an email arrives only if something got worse.', 'super-speedy-performance-analysis'));
				fieldset.find('.sspa-watchdog-run-now').prop('disabled', true);
				fieldset.find('.sspa-watchdog-save').prop('disabled', false);
				fieldset.find('.spinner').removeClass('is-active');
				return;
			}
			say(fieldset, SSPAFeedback.response(resp, __('Could not start the watchdog.', 'super-speedy-performance-analysis')));
			busy(fieldset, false);
		}).fail(function (xhr, textStatus) {
			say(fieldset, SSPAFeedback.xhr(xhr, __('Could not start the watchdog.', 'super-speedy-performance-analysis'), textStatus));
			busy(fieldset, false);
		});
	});
}(jQuery));
