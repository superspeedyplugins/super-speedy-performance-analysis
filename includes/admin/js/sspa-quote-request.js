/* "Request a fix quote" on Performance Analysis screens.
 *
 * The server builds the dialog, including the exact Markdown that will be sent, and keeps
 * that Markdown under the dialog's request ID. This script only opens and closes the dialog
 * and posts the form to this site's own admin-ajax handler; the site's server makes the
 * request to superspeedyplugins.com. Nothing is posted until the consent box is ticked and
 * Send is pressed. A retry reuses the same request ID, so it is never stored twice.
 */
(function ($) {
	'use strict';
	if (typeof sspa_quote === 'undefined') {
		return;
	}
	var opener = null;

	function close() {
		$('#sspa-quote-backdrop').remove();
		if (opener && document.body.contains(opener)) {
			opener.focus();
		}
		opener = null;
	}

	function status(dialog, text, isError) {
		dialog.find('.sspa-quote-status').text(text).toggleClass('is-error', !!isError);
	}

	$(document).on('click', '.sspa-quote-open', function (event) {
		event.preventDefault();
		var button = $(this);
		if (button.prop('disabled')) {
			return;
		}
		opener = this;
		var label = button.text();
		button.prop('disabled', true).text(sspa_quote.i18n.opening);
		$.post(sspa_quote.ajaxurl, {
			action: 'sspa_quote_preview',
			nonce: sspa_quote.nonce,
			profile_id: Number(button.data('profile-id')) || 0,
			source: String(button.data('source') || '')
		}).done(function (resp) {
			if (!resp || !resp.success || !resp.data || !resp.data.html) {
				window.alert((resp && resp.data && resp.data.message) || sspa_quote.i18n.open_failed);
				return;
			}
			$('#sspa-quote-backdrop').remove();
			var backdrop = $('<div id="sspa-quote-backdrop" class="sspa-quote-backdrop"></div>').html(resp.data.html).appendTo('body');
			backdrop.find('#sspa-quote-name').trigger('focus');
		}).fail(function (xhr) {
			var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
			window.alert((data && data.message) || sspa_quote.i18n.open_failed);
		}).always(function () {
			button.prop('disabled', false).text(label);
		});
	});

	$(document).on('click', '.sspa-quote-dialog .sspa-quote-close', function () {
		close();
	});

	// Escape closes this dialog only, not the page-analysis pop-up underneath it.
	$(document).on('keydown', '.sspa-quote-dialog', function (event) {
		if ('Escape' === event.key) {
			event.stopPropagation();
			close();
		}
	});

	$(document).on('submit', '.sspa-quote-form', function (event) {
		event.preventDefault();
		var form = $(this);
		var dialog = form.closest('.sspa-quote-dialog');
		var send = form.find('.sspa-quote-send');
		var consent = form.find('input[name="consent"]');
		if (!consent.is(':checked')) {
			status(dialog, sspa_quote.i18n.consent_needed, true);
			consent.trigger('focus');
			return;
		}
		var flag = function (name) { return form.find('input[name="' + name + '"]').is(':checked') ? '1' : '0'; };
		send.prop('disabled', true);
		status(dialog, sspa_quote.i18n.sending, false);
		$.post(sspa_quote.ajaxurl, {
			action: 'sspa_quote_send',
			nonce: sspa_quote.nonce,
			request_uuid: String(dialog.data('request-uuid') || ''),
			name: String(form.find('input[name="name"]').val() || ''),
			email: String(form.find('input[name="email"]').val() || ''),
			site_url: String(form.find('input[name="site_url"]').val() || ''),
			message: String(form.find('textarea[name="message"]').val() || ''),
			ongoing_care: flag('ongoing_care'),
			marketing_opt_in: flag('marketing_opt_in'),
			consent: flag('consent')
		}).done(function (resp) {
			var data = (resp && resp.data) || {};
			if (resp && resp.success) {
				dialog.addClass('is-sent').attr('data-reference', data.reference || '');
				status(dialog, data.message || '', false);
				form.find('input, textarea').not('.sspa-quote-markdown').prop('disabled', true);
				send.remove();
				form.find('.sspa-quote-close').text(sspa_quote.i18n.close).trigger('focus');
				return;
			}
			status(dialog, data.message || sspa_quote.i18n.send_failed, true);
			send.prop('disabled', false);
		}).fail(function (xhr) {
			var data = xhr && xhr.responseJSON && xhr.responseJSON.data;
			status(dialog, (data && data.message) || sspa_quote.i18n.send_failed, true);
			send.prop('disabled', false);
		});
	});
})(jQuery);
