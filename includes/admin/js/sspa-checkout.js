// Admin-bar "Analyse checkout flow" panel.
//
// Two clicks, deliberately. The first runs the pre-flight and shows the disclosure - what
// this run will actually set off in the store, gathered live because the answer changes
// whenever the store's plugins do. Only the second click buys anything.
//
// Renders into the same popover container as sspa-adhoc.js (one panel is open at a time)
// so it inherits that panel's CSS wholesale. Interactive elements use sspa-ck-* class
// names so the two scripts' delegated handlers can never collide.
(function ($) {
	'use strict';

	var driving = false;
	var lastRunId = 0;

	function esc(str) {
		return $('<span>').text(str == null ? '' : String(str)).html();
	}

	function ms(value) {
		if (value == null) {
			return '&mdash;';
		}
		return Number(value) >= 1000
			? esc((Number(value) / 1000).toFixed(2)) + ' s'
			: esc(Number(value).toFixed(0)) + ' ms';
	}

	function panel() {
		var el = $('#sspa-adhoc-pop');
		if (el.length) {
			return el;
		}
		var logo = sspa_checkout.logo_url
			? '<img class="sspa-adhoc-logo" src="' + esc(sspa_checkout.logo_url) + '" alt="Super Speedy Plugins">'
			: '<span class="sspa-adhoc-logo-text">Super Speedy Plugins</span>';
		el = $(
			'<div id="sspa-adhoc-pop" style="display:none">' +
			'<div class="sspa-adhoc-head"><span class="sspa-adhoc-title">Super Speedy Performance Analysis' +
			'<span class="sspa-adhoc-version">v' + esc(sspa_checkout.version) + '</span></span>' + logo +
			'<button type="button" class="sspa-adhoc-close" aria-label="' + esc(wp.i18n.__('Close', 'super-speedy-performance-analysis')) + '">&times;</button></div>' +
			'<div class="sspa-adhoc-body"></div>' +
			'<div class="sspa-adhoc-foot">' + wp.i18n.sprintf(/* translators: 1: linked product name, 2: linked publisher website. */ wp.i18n.__('Powered by %1$s · free from %2$s', 'super-speedy-performance-analysis'), '<strong>Super Speedy Performance Analysis</strong>', '<a href="https://www.superspeedyplugins.com/" target="_blank" rel="noopener">superspeedyplugins.com</a>') + '</div>' +
			'</div>'
		);
		$('body').append(el);
		return el;
	}

	function body(html) {
		panel().find('.sspa-adhoc-body').html(html).scrollTop(0);
		panel().show();
	}

	// Returns the jqXHR so callers can chain .fail() for the retry path.
	function post(action, data, done) {
		return $.post(sspa_checkout.ajaxurl, $.extend({ action: action, nonce: sspa_checkout.nonce }, data || {}), done);
	}

	function error(msg) {
		body('<p class="sspa-adhoc-error sspa-adhoc-span">' + esc(msg) + '</p>' +
			('<p><button type="button" class="sspa-adhoc-btn sspa-ck-preflight">' + wp.i18n.__('Try again', 'super-speedy-performance-analysis') + '</button></p>'));
	}

	// ---------------- the disclosure ----------------

	function renderDisclosure(d) {
		var inv = d.inventory;
		var html = '<div class="sspa-adhoc-span"><h3 class="sspa-ck-h">' + esc(wp.i18n.__('Before this runs, here is exactly what it will do', 'super-speedy-performance-analysis')) + '</h3>';
		if (inv.mail_warning) html += '<p class="sspa-adhoc-error">' + esc(inv.mail_warning) + '</p>';
		if (!inv.woocommerce) {
			body('<p class="sspa-adhoc-error">' + esc(wp.i18n.__('WooCommerce is not active, so there is no checkout to profile.', 'super-speedy-performance-analysis')) + '</p>');
			return;
		}
		if ('block' !== inv.checkout_type && 'classic' !== inv.checkout_type) {
			html += '<p class="sspa-adhoc-error">' + esc(wp.i18n.__('This store\'s checkout page uses neither the checkout block nor the [woocommerce_checkout] shortcode, so there is no purchase flow to drive. If a page builder renders your checkout, this cannot measure it yet.', 'super-speedy-performance-analysis')) + '</p></div>';
			body(html);
			return;
		}
		// translators: %s is the checkout type (block or shortcode).
		html += '<p class="sspa-adhoc-note">' + wp.i18n.sprintf(wp.i18n.__('Measuring your <strong>%s</strong> checkout <strong>and what you do with the order after</strong>.', 'super-speedy-performance-analysis'), esc('block' === inv.checkout_type ? wp.i18n.__('block', 'super-speedy-performance-analysis') : wp.i18n.__('shortcode', 'super-speedy-performance-analysis'))) + ' ' +
			wp.i18n.__('It checks out using the hidden, non-stock-managed SSPA test product, with every plugin on your site active, then <strong>opens the order in wp-admin and marks it completed</strong>.', 'super-speedy-performance-analysis') + ' ' +
			esc(wp.i18n.__('That is the point: a run that switches your integrations off measures a store nobody has.', 'super-speedy-performance-analysis')) + ' ' +
			esc(wp.i18n.__('Marking it completed sends the completed-order email and fires everything hooking order completion.', 'super-speedy-performance-analysis')) + ' ' +
			wp.i18n.__('It then records a full local refund and moves the order to <strong>Trash</strong>, where you can inspect or restore it.', 'super-speedy-performance-analysis') + ' ' +
			esc(wp.i18n.__('No catalogue product, stock level or payment gateway refund is touched.', 'super-speedy-performance-analysis')) + '</p><ul class="sspa-ck-list">';
		if (inv.product) {
			// translators: 1: product name, 2: formatted product price.
			html += '<li>' + wp.i18n.sprintf(wp.i18n.__('<strong>Buys</strong> %1$s (%2$s)', 'super-speedy-performance-analysis'), esc(inv.product.name), inv.product.price) +
				(inv.product.manages_stock ? esc(wp.i18n.__(', taking 1 off your stock and putting it back', 'super-speedy-performance-analysis')) : '') + '</li>';
		}
		var emails = (inv.emails || []).filter(function (e) { return e.enabled; });
		if (emails.length) {
			// translators: %d is the maximum number of enabled order emails.
			html += '<li><strong>' + esc(wp.i18n.sprintf(wp.i18n._n('Can send up to %d order email, depending on the status the order reaches', 'Can send up to %d order emails, depending on the status the order reaches', emails.length, 'super-speedy-performance-analysis'), emails.length)) + '</strong>: ' +
				esc(emails.map(function (e) { return e.title + (e.recipient ? ' → ' + e.recipient : ''); }).join('; ')) + '. ' +
				// translators: %s is the destination email address.
				wp.i18n.sprintf(wp.i18n.__('Customer emails go to <code>%s</code>', 'super-speedy-performance-analysis'), esc(inv.email_to)) + '. ' +
				(inv.emails_deferred ? esc(wp.i18n.__('Your store defers these to the background, so they cost your customer nothing', 'super-speedy-performance-analysis')) : wp.i18n.__('Your store sends these <strong>inside the checkout request</strong>, so your customer waits for them', 'super-speedy-performance-analysis')) + '</li>';
		}
		if ((inv.webhooks || []).length) {
			var hosts = {};
			inv.webhooks.forEach(function (w) { hosts[w.host] = 1; });
			// translators: 1: webhook count, 2: destination hosts.
			html += '<li><strong>' + esc(wp.i18n.sprintf(wp.i18n._n('Fires %1$d webhook to %2$s', 'Fires %1$d webhooks to %2$s', inv.webhooks.length, 'super-speedy-performance-analysis'), inv.webhooks.length, Object.keys(hosts).join(', '))) + '</strong> ' +
				esc(inv.webhooks_inline ? wp.i18n.__('(delivered inside the request, so your customer waits for them)', 'super-speedy-performance-analysis') : wp.i18n.__('(queued in the background, so not part of your customer\'s wait)', 'super-speedy-performance-analysis')) + '</li>';
		}
		if ((inv.order_hooks || []).length) {
			// translators: 1: component count, 2: component names.
			html += '<li>' + esc(wp.i18n.sprintf(wp.i18n._n('%1$d component will run code when this order is created: %2$s', '%1$d components will run code when this order is created: %2$s', inv.order_hooks.length, 'super-speedy-performance-analysis'), inv.order_hooks.length, inv.order_hooks.map(function (c) { return c.component; }).join(', '))) +
				// translators: %d is the number of additional components.
				(inv.order_hooks_more ? esc(wp.i18n.sprintf(wp.i18n.__(' and %d more', 'super-speedy-performance-analysis'), inv.order_hooks_more)) : '') + '</li>';
		}
		if (inv.creates_account) html += '<li>' + wp.i18n.__('Guest checkout is disabled on this store, so the purchase <strong>creates a customer account</strong>. The account is deleted after the run.', 'super-speedy-performance-analysis') + '</li>';
		if (inv.needs_payment_filtered) html += '<li class="sspa-ck-warn">' + esc(wp.i18n.__('Another plugin already filters WooCommerce\'s "needs payment" checks. This run uses those same filters to skip the gateway, so the two may interact.', 'super-speedy-performance-analysis')) + '</li>';
		if (inv.human_verification_bypassed) html += '<li>' + wp.i18n.__('<strong>Cloudflare Turnstile stays active for real customers.</strong> This signed synthetic checkout cannot solve an interactive challenge, so Turnstile\'s documented programmatic bypass is used for the measured place-order request only.', 'super-speedy-performance-analysis') + '</li>';
		html += '</ul><h4 class="sspa-ck-h4">' + esc(wp.i18n.__('Payment', 'super-speedy-performance-analysis')) + '</h4>';
		(inv.payment_modes || []).forEach(function (mode) {
			if (!mode.available) {
				html += '<p class="sspa-adhoc-note sspa-ck-unavailable"><em>' + esc(mode.label) + '</em> &mdash; ' + esc(mode.note) + '</p>';
				return;
			}
			html += '<label class="sspa-ck-opt"><input type="radio" name="sspa-ck-pm" value="' + esc(mode.key) + '"' + ('no_payment' === mode.key ? ' checked' : '') + '> <strong>' + esc(mode.label) + '</strong> <span class="sspa-adhoc-note">' + esc(mode.note) + '</span></label>';
		});
		html += '<h4 class="sspa-ck-h4">' + esc(wp.i18n.__('Leave everything on for a real measurement', 'super-speedy-performance-analysis')) + '</h4><p class="sspa-adhoc-note">' +
			esc(wp.i18n.__('Emails go to the site administrator; send/queue time is measured.', 'super-speedy-performance-analysis')) + '</p>' +
			'<label class="sspa-ck-opt"><input type="checkbox" class="sspa-ck-integrations" checked> ' + esc(wp.i18n.__('Let third-party integrations run', 'super-speedy-performance-analysis')) + '</label>' +
			'<label class="sspa-ck-opt"><input type="checkbox" class="sspa-ck-webhooks" checked> ' + esc(wp.i18n.__('Let webhooks fire', 'super-speedy-performance-analysis')) + '</label><p class="sspa-adhoc-note">' +
			esc(wp.i18n.__('Turning any of these off is recorded with the result, so a partial run cannot later be mistaken for a full one.', 'super-speedy-performance-analysis')) + '</p><p class="sspa-ck-actions">' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-ck-go">' + esc(wp.i18n.__('Buy something and measure it', 'super-speedy-performance-analysis')) + '</button> ' +
			'<button type="button" class="sspa-adhoc-btn sspa-ck-cancel">' + esc(wp.i18n.__('Cancel', 'super-speedy-performance-analysis')) + '</button></p></div>';
		body(html);
	}

	// ---------------- the result ----------------

	function bar(value, max) {
		var pct = (max > 0 && value != null) ? Math.max(1, Math.round(100 * value / max)) : 0;
		return '<span class="sspa-ck-bar" style="width:' + pct + '%"></span>';
	}

	// `slowest` is matched on the row's own label, not its page key: the place-order step
	// contributes two rows (before and after the payment boundary) that share a key, and
	// tagging both of them as the slowest step would be wrong on the face of it.
	function detailTable(title, headings, body) {
		if (!body) {
			return '';
		}
		return '<h5>' + esc(title) + '</h5><table class="sspa-adhoc-table sspa-ck-detail-table">' +
			'<tr class="sspa-adhoc-hrow">' + headings.map(function (heading) {
				return '<td>' + esc(heading) + '</td>';
			}).join('') + '</tr>' + body + '</table>';
	}

	function stepDetails(row) {
		var d = row.details;
		if (!d) {
			return ('<p class="sspa-adhoc-note">' + wp.i18n.__('No detailed capture is stored for this step.', 'super-speedy-performance-analysis') + '</p>');
		}
		var html = '';
		if ('flow-place-order' === row.page_key) {
			html += ('<p class="sspa-adhoc-note">' + wp.i18n.__('The payment boundary splits the elapsed time, but SQL, HTTP, mail and component attribution belong to the complete place-order request and cannot be divided honestly between its two halves.', 'super-speedy-performance-analysis') + '</p>');
		}

		var componentRows = (d.components || []).map(function (component, index) {
			return '<tr><td><code>' + esc(component.component) + '</code>' +
				(0 === index ? (' <span class="sspa-ck-tag">' + wp.i18n.__('dominant measured component', 'super-speedy-performance-analysis') + '</span>') : '') +
				'</td><td>' + esc(component.queries) + '</td><td>' + ms(component.sql_ms) +
				'</td><td>' + ms(component.http_ms) + '</td><td>' + esc(component.rows) + '</td></tr>';
		}).join('');
		html += detailTable(wp.i18n.__('Component attribution', 'super-speedy-performance-analysis'), [wp.i18n.__('Component', 'super-speedy-performance-analysis'), wp.i18n.__('Queries', 'super-speedy-performance-analysis'), 'SQL', 'HTTP', wp.i18n.__('Rows', 'super-speedy-performance-analysis')], componentRows);

		var duplicateRows = (d.duplicates || []).map(function (query) {
			return '<tr><td><code>' + esc(query.component) + '</code><br><small><code>' + esc(query.sql) +
				'</code></small></td><td>' + esc(wp.i18n.sprintf(/* translators: %d is the number of executions of a repeated query. */ wp.i18n._n('%d time', '%d times', query.count, 'super-speedy-performance-analysis'), query.count)) + '</td><td>' + ms(query.ms) + '</td></tr>';
		}).join('');
		html += detailTable(wp.i18n.__('Repeated queries', 'super-speedy-performance-analysis'), [wp.i18n.__('Component and query', 'super-speedy-performance-analysis'), wp.i18n.__('Executions', 'super-speedy-performance-analysis'), wp.i18n.__('Total', 'super-speedy-performance-analysis')], duplicateRows);

		var queryRows = (d.queries || []).map(function (query) {
			var meta = query.component + (query.caller ? ' · ' + query.caller : '') +
				(null === query.rows ? '' : ' · ' + wp.i18n.sprintf(/* translators: %d is the measured number of database rows. */ wp.i18n._n('%d row', '%d rows', query.rows, 'super-speedy-performance-analysis'), query.rows)) +
				(query.fingerprint_only ? wp.i18n.__(' · normalised fingerprint', 'super-speedy-performance-analysis') : '') +
				(query.error ? wp.i18n.__(' · ERROR: ', 'super-speedy-performance-analysis') + query.error : '');
			return '<tr><td><code>' + esc(query.sql) + '</code><br><small>' + esc(meta) +
				'</small></td><td>' + ms(query.ms) + '</td></tr>';
		}).join('');
		html += detailTable(wp.i18n.__('Slowest queries', 'super-speedy-performance-analysis'), [wp.i18n.__('Query', 'super-speedy-performance-analysis'), wp.i18n.__('Time', 'super-speedy-performance-analysis')], queryRows);

		var httpRows = (d.http || []).map(function (call) {
			var meta = call.component + (call.caller ? ' · ' + call.caller : '') +
				(call.blocking ? wp.i18n.__(' · blocking', 'super-speedy-performance-analysis') : wp.i18n.__(' · non-blocking', 'super-speedy-performance-analysis')) +
				(call.code ? ' · ' + call.code : '');
			return '<tr><td><code>' + esc(call.method + ' ' + call.url) + '</code><br><small>' +
				esc(meta) + '</small></td><td>' + ms(call.ms) + '</td></tr>';
		}).join('');
		html += detailTable(wp.i18n.__('Outbound HTTP calls', 'super-speedy-performance-analysis'), [wp.i18n.__('Call', 'super-speedy-performance-analysis'), wp.i18n.__('Time', 'super-speedy-performance-analysis')], httpRows);

		if (d.mail && d.mail.count) {
			var senders = Object.keys(d.mail.by_component || {}).map(function (component) {
				return component + ' (' + d.mail.by_component[component] + ')';
			}).join(', ');
			html += ('<p class="sspa-adhoc-note">' + wp.i18n.__('<strong>Mail:</strong> ', 'super-speedy-performance-analysis')) +
				' ' + esc(wp.i18n.sprintf(/* translators: %d is the number of measured emails. */ wp.i18n._n('%d message', '%d messages', d.mail.count, 'super-speedy-performance-analysis'), d.mail.count)) + ('deliver' === d.mail.mode ? wp.i18n.__(' sent/queued in ', 'super-speedy-performance-analysis') + ms(d.mail.ms) : ('construct' === d.mail.mode ? wp.i18n.__(' constructed in ', 'super-speedy-performance-analysis') + ms(d.mail.ms) : wp.i18n.__(' attempted; transport time not measured', 'super-speedy-performance-analysis'))) +
				(senders ? esc(wp.i18n.sprintf(/* translators: %s lists the components responsible for sending mail. */ wp.i18n.__(' by %s', 'super-speedy-performance-analysis'), senders)) : '') +
				(d.mail.mode ? wp.i18n.__(' · mode: ', 'super-speedy-performance-analysis') + esc(d.mail.mode) : '') + '.</p>';
		}

		var functionRows = (d.functions || []).map(function (fn) {
			return '<tr><td><code>' + esc(fn.function) + '</code><br><small><code>' + esc(fn.component) +
				'</code></small></td><td>' + ms(fn.self_ms) + '</td><td>' + ms(fn.inclusive_ms) + '</td></tr>';
		}).join('');
		html += detailTable(wp.i18n.__('Sampled functions', 'super-speedy-performance-analysis'), [wp.i18n.__('Function', 'super-speedy-performance-analysis'), wp.i18n.__('Self', 'super-speedy-performance-analysis'), wp.i18n.__('Inclusive', 'super-speedy-performance-analysis')], functionRows);
		if (!d.sampling_available) {
			html += ('<p class="sspa-adhoc-note">' + wp.i18n.__('No function-level sampling is available for this step because the Excimer extension is not installed. SQL, HTTP, mail and component attribution above are still measured.', 'super-speedy-performance-analysis') + '</p>');
		}
		return html || ('<p class="sspa-adhoc-note">' + wp.i18n.__('This step recorded no attributable SQL, HTTP, mail or sampled function work.', 'super-speedy-performance-analysis') + '</p>');
	}

	function rows(list, max, slowest, prefix) {
		var html = '';
		list.forEach(function (row, index) {
			var isSlowest = row.label === slowest;
			var detailId = 'sspa-ck-detail-' + prefix + '-' + index;
			var toggle = row.details
				? '<button type="button" class="sspa-ck-step-toggle" aria-expanded="false" aria-controls="' + detailId + '">' +
					'<span class="sspa-adhoc-caret">&#9656;</span><span>' + esc(row.label) + '</span></button>'
				: esc(row.label);
			html += '<tr' + (isSlowest ? ' class="is-slowest"' : '') + '>' +
				'<td>' + toggle + (isSlowest ? (' <span class="sspa-ck-tag">' + wp.i18n.__('slowest step', 'super-speedy-performance-analysis') + '</span>') : '') + '</td>' +
				'<td class="sspa-ck-num">' + ms(row.gen_ms) + '</td>' +
				'<td class="sspa-ck-barcell">' + bar(row.gen_ms, max) + '</td>' +
				'<td class="sspa-ck-num">' + (row.sql_count == null ? '&mdash;' : esc(row.sql_count) + ' q') + '</td>' +
				'</tr>';
			if (row.details) {
				html += '<tr id="' + detailId + '" class="sspa-ck-step-detail" style="display:none"><td colspan="4"><div>' +
					stepDetails(row) + '</div></td></tr>';
			}
		});
		return html;
	}

	function renderResult(d) {
		var management = d.management || [];
		var all = d.at_risk.concat(d.secured).concat(management);
		var max = 0;
		all.forEach(function (r) { if (r.gen_ms > max) { max = r.gen_ms; } });

		var html = '<div class="sspa-adhoc-topbar sspa-adhoc-span">' +
			('<button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-ck-preflight">' + wp.i18n.__('Run again', 'super-speedy-performance-analysis') + '</button>') +
			'<button type="button" class="sspa-adhoc-btn sspa-markdown-download" data-kind="checkout" data-id="' + esc(d.run_id) + ('">' + wp.i18n.__('Download Markdown', 'super-speedy-performance-analysis') + '</button>') +
			'<button type="button" class="sspa-adhoc-btn sspa-markdown-copy" data-kind="checkout" data-id="' + esc(d.run_id) + ('">' + wp.i18n.__('Copy Markdown', 'super-speedy-performance-analysis') + '</button>') +
			'<span class="sspa-markdown-status" aria-live="polite"></span>' +
			'<span class="sspa-adhoc-note">' + esc(d.created || '') + '</span>' +
			'<a class="sspa-adhoc-open" href="' + esc(sspa_checkout.results_url) + ('">' + (wp.i18n.__('Open in Performance Analysis &rarr;', 'super-speedy-performance-analysis') + '</a>') + '</div>');

		if ('failed' === d.status) {
			// translators: %s is the recorded outcome of the failed purchase.
			html += '<p class="sspa-adhoc-error sspa-adhoc-span">' + wp.i18n.sprintf(wp.i18n.__('The purchase did not complete: <code>%s</code>', 'super-speedy-performance-analysis'), esc(d.notes && d.notes.outcome ? d.notes.outcome : wp.i18n.__('unknown', 'super-speedy-performance-analysis'))) +
				(d.notes && d.notes.flow && d.notes.flow.error ? ' &mdash; ' + esc(d.notes.flow.error) : '') + '. ' + esc(wp.i18n.__('The steps below are what was measured before it stopped.', 'super-speedy-performance-analysis')) + '</p>';
		}

		var order = d.order_details || {};
		var orderItems = order.items || [];
		if (!orderItems.length && order.planned_product) {
			orderItems = [{ name: order.planned_product, quantity: order.planned_quantity || 1 }];
		}
		var orderNumber = order.order_number || (order.order_id ? String(order.order_id) : '');
		var coupons = (order.coupon_codes || []).length ? order.coupon_codes.join(', ') : wp.i18n.__('None', 'super-speedy-performance-analysis');
		html += ('<div class="sspa-adhoc-span"><h3 class="sspa-ck-h">' + wp.i18n.__('Synthetic order details for fulfilment', 'super-speedy-performance-analysis') + '</h3>') +
			('<p class="sspa-adhoc-note">' + wp.i18n.__('If a fulfilment, warehouse or webhook integration received this test order, ask them to ignore the following:', 'super-speedy-performance-analysis') + '</p>') +
			'<table class="sspa-adhoc-table sspa-ck-table sspa-ck-order-details">' +
			('<tr><td>' + (wp.i18n.__('Email address', 'super-speedy-performance-analysis') + '</td><td>' + '<code>')) + esc(order.email || wp.i18n.__('Unavailable', 'super-speedy-performance-analysis')) + '</code></td></tr>' +
			('<tr><td>' + (wp.i18n.__('Order number', 'super-speedy-performance-analysis') + '</td><td>')) + (orderNumber ? '<strong>#' + esc(orderNumber) + '</strong>' : wp.i18n.__('No order created', 'super-speedy-performance-analysis')) +
				(order.order_id && String(order.order_id) !== String(orderNumber) ? '<span class="sspa-adhoc-note">' + esc(wp.i18n.sprintf(/* translators: %s is the internal order identifier. */ wp.i18n.__('(internal ID %s)', 'super-speedy-performance-analysis'), order.order_id)) + '</span>' : '') + '</td></tr>' +
			('<tr><td>' + (wp.i18n.__('Coupon', 'super-speedy-performance-analysis') + '</td><td>' + '<code>')) + esc(coupons) + '</code></td></tr>' +
			('<tr><td>' + (wp.i18n.__('Item ordered', 'super-speedy-performance-analysis') + '</td><td>')) + (orderItems.length ? orderItems.map(function (item) {
				return esc((item.quantity || 1) + ' × ' + item.name);
			}).join('<br>') : wp.i18n.__('Unavailable', 'super-speedy-performance-analysis')) + '</td></tr>' +
			'</table></div>';

		html += ('<div class="sspa-adhoc-span"><h3 class="sspa-ck-h sspa-ck-atrisk">' + wp.i18n.__('At risk &mdash; your customer can still walk away during this', 'super-speedy-performance-analysis') + '</h3>') +
			'<table class="sspa-adhoc-table sspa-ck-table">' + rows(d.at_risk, max, d.slowest, 'risk') +
			('<tr class="sspa-ck-total"><td>' + (wp.i18n.__('At-risk total', 'super-speedy-performance-analysis') + '</td><td class="sspa-ck-num">')) + ms(d.at_risk_ms) +
			(('</td><td colspan="2" class="sspa-ck-wide">' + wp.i18n.__('every second here can cost you the sale', 'super-speedy-performance-analysis')) + '</td></tr></table>');

		if (d.secured.length) {
			html += ('<h3 class="sspa-ck-h sspa-ck-secured">' + wp.i18n.__('Secured &mdash; the money is taken, they are waiting on a confirmation', 'super-speedy-performance-analysis') + '</h3>') +
				'<table class="sspa-adhoc-table sspa-ck-table">' + rows(d.secured, max, d.slowest, 'secured') +
				('<tr class="sspa-ck-total"><td>' + (wp.i18n.__('Post-capture', 'super-speedy-performance-analysis') + '</td><td class="sspa-ck-num">')) + ms(d.secured_ms) +
				(('</td><td colspan="2" class="sspa-ck-wide">' + wp.i18n.__('a bad impression, not a lost sale', 'super-speedy-performance-analysis')) + '</td></tr></table>');
		} else if ('failed' === d.status) {
			html += ('<p class="sspa-adhoc-error">' + wp.i18n.__('No post-capture or order-management steps ran because the purchase failed before an order was created.', 'super-speedy-performance-analysis') + '</p>');
		}

		html += ('<table class="sspa-adhoc-table sspa-ck-table sspa-ck-grand"><tr><td>' + wp.i18n.__('Your customer waited', 'super-speedy-performance-analysis') + '</td>') +
			'<td class="sspa-ck-num">' + ms(d.total_ms) + '</td>' +
			'<td colspan="2" class="sspa-ck-wide">&nbsp;</td></tr></table>' +
			'<p class="sspa-adhoc-note">' + esc(d.basis) + '</p>';

		// Order management: the shop owner's own time, after the sale. Its own section and
		// subtotal, deliberately below the customer total - "your order screen takes 3s" is a
		// real cost, but not one a customer waits through.
		if (management.length) {
			var transition = (d.management_from_status && d.management_to_status)
				? ' (' + esc(d.management_from_status) + ' &rarr; ' + esc(d.management_to_status) + ')'
				: '';
			html += ('<h3 class="sspa-ck-h">' + wp.i18n.__('Order management &mdash; what YOU wait through handling the order', 'super-speedy-performance-analysis')) + transition + '</h3>' +
				'<table class="sspa-adhoc-table sspa-ck-table">' + rows(management, max, d.slowest, 'management') +
				('<tr class="sspa-ck-total"><td>' + (wp.i18n.__('Order-management total', 'super-speedy-performance-analysis') + '</td><td class="sspa-ck-num">')) + ms(d.management_ms) +
				(('</td><td colspan="2" class="sspa-ck-wide">' + wp.i18n.__('your staff time per order, not the customer\'s', 'super-speedy-performance-analysis')) + '</td></tr></table>');
		}

		if (false === d.boundary_known) {
			html += ('<p class="sspa-adhoc-note">' + wp.i18n.__('The payment boundary could not be determined for this run &mdash; ', 'super-speedy-performance-analysis')) +
				(wp.i18n.__('nothing fired <code>woocommerce_payment_complete</code>, so place-order is shown as one row rather than guessed at.', 'super-speedy-performance-analysis') + '</p>');
		}
		if (d.payment_caveat) {
			html += '<p class="sspa-adhoc-note">&#9432; ' + esc(d.payment_caveat) + '</p>';
		}
		d.excluded.forEach(function (row) {
			html += '<p class="sspa-adhoc-note">' + esc(row.label) + ': ' + ms(row.gen_ms) + (wp.i18n.__(' &mdash; measured, but nobody waits for it, so it is not in the total.', 'super-speedy-performance-analysis') + '</p>');
		});

		// What the findings engine made of it.
		if ((d.findings || []).length) {
			// Deliberately not a table: the panel's table styling right-aligns and
			// monospaces the last cell, which is wrong for a paragraph of advice.
			html += ('<h4 class="sspa-ck-h4">' + wp.i18n.__('What to fix', 'super-speedy-performance-analysis') + '</h4>');
			d.findings.forEach(function (f) {
				html += '<div class="sspa-ck-finding"><span class="sspa-ck-sev is-' + esc(f.severity) + '">' + esc(f.severity) + '</span> ' +
					'<strong>' + esc(f.recommendation.title) + '</strong>' + (f.component ? ' <code>' + esc(f.component) + '</code>' : '') +
					(f.evidence && f.evidence.label ? ' <span class="sspa-adhoc-note">' + esc(wp.i18n.sprintf(/* translators: %s identifies the measured step associated with the finding. */ wp.i18n.__('at %s', 'super-speedy-performance-analysis'), f.evidence.label)) + '</span>' : '') +
					'<div class="sspa-adhoc-note">' + esc(f.recommendation.body) + '</div></div>';
			});
		}
		// Offers come pre-escaped from the server, only on the Performance Analysis screens.
		if (d.offers_html) {
			html += '<div class="sspa-ck-offers">' + d.offers_html + '</div>';
		}

		if (d.mail && d.mail.count) {
			var deferred = d.notes && d.notes.inventory && d.notes.inventory.emails_deferred;
			var mailLine = wp.i18n.__('<strong>Mail:</strong> ', 'super-speedy-performance-analysis') + ' ' + esc(wp.i18n.sprintf(/* translators: %d is the number of measured emails. */ wp.i18n._n('%d message', '%d messages', d.mail.count, 'super-speedy-performance-analysis'), d.mail.count));
			if (d.mail.untimed >= d.mail.count) {
				// A mail plugin replaced WordPress delivery wholesale, so the send is not
				// separately hook-timed - but its API calls ARE in the outbound list.
				mailLine += wp.i18n.__(', sent through a mail plugin\'s own transport - its time appears under outbound calls below', 'super-speedy-performance-analysis');
			} else {
				mailLine += wp.i18n.sprintf(/* translators: %s is the measured mail duration with its unit. */ wp.i18n.__(' in %s', 'super-speedy-performance-analysis'), ms(d.mail.ms));
			}
			mailLine += deferred ? wp.i18n.__('. Deferred to the background.', 'super-speedy-performance-analysis') : wp.i18n.__('. Handled synchronously inside the measured flow; expand a step to see where.', 'super-speedy-performance-analysis');
			html += '<p class="sspa-adhoc-note">' + mailLine + '</p>';
		}
		if ((d.http || []).length) {
			html += ('<h4 class="sspa-ck-h4">' + (wp.i18n.__('Outbound calls across the measured flow', 'super-speedy-performance-analysis') + '</h4>' + '<table class="sspa-adhoc-table sspa-ck-table">'));
			d.http.forEach(function (c) {
				// The query keys matter: "GET /?p=…" is an order-permalink purge, and
				// without them it displays as a fetch of the bare homepage.
				var url = c.url + (c.q ? '?' + c.q + '=&hellip;' : '');
				var failed = c.code && String(c.code).indexOf('error:') === 0;
				html += '<tr><td><code>' + esc((c.method || 'GET') + ' ' + url) + '</code>' +
					(failed ? (' <span class="sspa-ck-sev is-critical">' + wp.i18n.__('failed', 'super-speedy-performance-analysis') + '</span>') : '') +
					'<div class="sspa-adhoc-note">' + esc(c.step) + ' &middot; ' + esc(c.component) +
					(c.trace ? ' &middot; ' + esc(c.trace) : (c.caller ? ' &middot; ' + esc(c.caller) : '')) + '</div></td>' +
					'<td class="sspa-ck-num">' + ms(c.ms) + '</td></tr>';
			});
			html += '</table>';
		}

		if (d.profile && d.profile.components && d.profile.components.length) {
			html += ('<h4 class="sspa-ck-h4">' + (wp.i18n.__('Where the PHP time went across the whole measured flow', 'super-speedy-performance-analysis') + '</h4>' + '<table class="sspa-adhoc-table sspa-ck-table">'));
			d.profile.components.slice(0, 10).forEach(function (c) {
				html += '<tr><td><code>' + esc(c.component) + '</code></td><td class="sspa-ck-num">' + ms(c.ms) + '</td></tr>';
			});
			html += '</table><p class="sspa-adhoc-note">' + esc(d.profile.note) + '</p>';
		} else {
			html += ('<p class="sspa-adhoc-note">' + wp.i18n.__('No function-level sampling was available for this measurement. ', 'super-speedy-performance-analysis')) +
				'<a class="sspa-excimer-prompt" href="' + esc(sspa_checkout.tools_url) + '">' + esc(sspa_checkout.excimer_prompt) + '</a>.</p>';
		}

		// State the recorded cleanup outcome, including partial failures.
		var safety = d.notes && d.notes.safety;
		if (safety) {
			var cleanup = '';
			var hasTrashReport = Object.prototype.hasOwnProperty.call(safety, 'orders_trashed');
			if (!hasTrashReport) {
				var deleted = Number(safety.orders_deleted || 0);
				// translators: %d is the number of permanently deleted synthetic orders.
				cleanup = esc(wp.i18n.sprintf(wp.i18n._n('This older result permanently deleted %d synthetic order.', 'This older result permanently deleted %d synthetic orders.', deleted, 'super-speedy-performance-analysis'), deleted));
				if (safety.orders_left) cleanup += ' <strong class="sspa-ck-warn">' + esc(wp.i18n.sprintf(/* translators: %d is the number of synthetic orders not removed. */ wp.i18n.__('%d left behind.', 'super-speedy-performance-analysis'), safety.orders_left)) + '</strong>';
			} else if ('failed' === d.status && 0 === Number(safety.orders_trashed) && 0 === Number(safety.orders_not_trashed)) {
				cleanup = esc(wp.i18n.__('No order was created, so there was no order, customer account or stock change to clean up.', 'super-speedy-performance-analysis'));
			} else {
				// translators: %d is the number of orders moved to Trash.
				cleanup = esc(wp.i18n.sprintf(wp.i18n._n('%d order moved to Trash.', '%d orders moved to Trash.', safety.orders_trashed, 'super-speedy-performance-analysis'), safety.orders_trashed)) + ' ' +
					(safety.orders_not_trashed ? '<strong class="sspa-ck-warn">' + esc(wp.i18n.sprintf(/* translators: %d is the number of synthetic orders that could not be moved to Trash. */ wp.i18n.__('%d could not be trashed.', 'super-speedy-performance-analysis'), safety.orders_not_trashed)) + '</strong>' : esc(wp.i18n.__('None permanently deleted.', 'super-speedy-performance-analysis')));
				if (null === safety.stock_before) {
					cleanup += ' ' + esc(wp.i18n.__('This product does not use managed stock, so there was no stock level to restore.', 'super-speedy-performance-analysis'));
				} else {
					// translators: 1: stock before, 2: stock after the measurement.
					cleanup += ' ' + esc(wp.i18n.sprintf(wp.i18n.__('Stock %1$s → %2$s.', 'super-speedy-performance-analysis'), safety.stock_before, safety.stock_after));
					if (safety.stock_restored_manually) cleanup += ' ' + esc(wp.i18n.__('(put back explicitly - cancelling the order did not restore it)', 'super-speedy-performance-analysis'));
				}
				if (safety.users_deleted) {
					// translators: %d is the number of synthetic customer accounts deleted.
					cleanup += ' ' + esc(wp.i18n.sprintf(wp.i18n._n('%d auto-created customer account deleted.', '%d auto-created customer accounts deleted.', safety.users_deleted, 'super-speedy-performance-analysis'), safety.users_deleted));
					if (safety.users_left) cleanup += ' <strong class="sspa-ck-warn">' + esc(wp.i18n.sprintf(/* translators: %d is the number of synthetic customer accounts that could not be removed. */ wp.i18n.__('%d could not be removed.', 'super-speedy-performance-analysis'), safety.users_left)) + '</strong>';
				}
			}
			html += '<p class="sspa-adhoc-note"><strong>' + esc(wp.i18n.__('Cleanup:', 'super-speedy-performance-analysis')) + '</strong> ' + cleanup + '</p>';
		}

		if (d.notes && d.notes.flow && d.notes.flow.human_verification_bypassed) {
			html += ('<p class="sspa-adhoc-note">' + wp.i18n.__('<strong>Human verification:</strong> Cloudflare Turnstile was bypassed only for SSPA\'s signed synthetic checkout request. It remained active for real visitors.', 'super-speedy-performance-analysis') + '</p>');
		}
		(d.notes && d.notes.skipped ? d.notes.skipped : []).forEach(function (s) {
			// translators: 1: skipped step identifier, 2: recorded reason.
			html += '<p class="sspa-adhoc-note">' + wp.i18n.sprintf(wp.i18n.__('Skipped <code>%1$s</code>: %2$s', 'super-speedy-performance-analysis'), esc(s.step), esc(s.why)) + '</p>';
		});
		html += '</div>';
		body(html);
	}

	$(document).on('click', '#sspa-adhoc-pop .sspa-ck-step-toggle', function () {
		var button = $(this);
		var detail = $('#' + button.attr('aria-controls'));
		var opening = 'true' !== button.attr('aria-expanded');
		button.attr('aria-expanded', opening ? 'true' : 'false').toggleClass('is-open', opening);
		detail.toggle(opening);
	});

	// ---------------- driving a run ----------------

	function renderProgress(status) {
		var elapsed = status && status.elapsed_seconds ? Math.round(status.elapsed_seconds) + 's' : '';
		body(('<p class="sspa-adhoc-running sspa-adhoc-span"><span class="sspa-adhoc-spin">' + '</span>' + wp.i18n.__('Buying something and measuring it', 'super-speedy-performance-analysis')) +
			(elapsed ? ' <span class="sspa-adhoc-elapsed">' + esc(elapsed) + '</span>' : '') + '</p>' +
			('<p class="sspa-adhoc-note sspa-adhoc-span">' + wp.i18n.__('One purchase, then completion, full local refund and a move to WooCommerce Trash. Usually under a minute.', 'super-speedy-performance-analysis') + '</p>'));
	}

	function drive(runId) {
		if (driving) {
			return;
		}
		driving = true;
		lastRunId = runId;
		var failures = 0;
		function step() {
			post('sspa_process_batch', { run_id: runId }, function (resp) {
				if (!resp.success || !resp.data) {
					driving = false;
					error(wp.i18n.__('The run could not be advanced.', 'super-speedy-performance-analysis'));
					return;
				}
				var status = resp.data;
				if ('crawling' === status.status || 'analysing' === status.status) {
					renderProgress(status);
					window.setTimeout(step, 800);
					return;
				}
				driving = false;
				if ('done' === status.status || 'failed' === status.status) {
					showResult(runId);
					return;
				}
				error(wp.i18n.sprintf(/* translators: %s is the recorded terminal analysis status. */ wp.i18n.__('The run ended as "%s".', 'super-speedy-performance-analysis'), status.status));
			}).fail(function () {
				if (++failures < 4) {
					window.setTimeout(step, 2000);
					return;
				}
				driving = false;
				error(wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'));
			});
		}
		renderProgress(null);
		step();
	}

	function showResult(runId) {
		post('sspa_checkout_result', { run_id: runId || 0, pa_screen: document.getElementById('sspa_main') ? 1 : 0 }, function (resp) {
			if (!resp.success) {
				error(resp.data || wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'));
				return;
			}
			if (resp.data.running) {
				drive(resp.data.running);
				return;
			}
			if (!resp.data.found) {
				preflight();
				return;
			}
			renderResult(resp.data);
		});
	}

	function preflight() {
		body(('<p class="sspa-adhoc-running sspa-adhoc-span"><span class="sspa-adhoc-spin">' + '</span>' + wp.i18n.__('Checking what a purchase would set off on this store&hellip;', 'super-speedy-performance-analysis') + '</p>'));
		post('sspa_checkout_preflight', {}, function (resp) {
			if (!resp.success) {
				error(resp.data || wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'));
				return;
			}
			renderDisclosure(resp.data);
		});
	}

	// ---------------- events ----------------

	$(document).on('click', '#wp-admin-bar-sspa-checkout > a, .sspa-ck-open', function (e) {
		e.preventDefault();
		var el = $('#sspa-adhoc-pop');
		if (el.length && el.is(':visible') && el.find('.sspa-ck-h').length) {
			el.hide();
			return;
		}
		showResult(lastRunId);
	});

	$(document).on('click', '.sspa-ck-preflight', preflight);

	$(document).on('click', '.sspa-ck-cancel', function () {
		panel().hide();
	});

	$(document).on('click', '.sspa-ck-go', function () {
		var pop = panel();
		post('sspa_checkout_start', {
			payment_mode: pop.find('input[name="sspa-ck-pm"]:checked').val() || 'no_payment',
			mail_mode: 'deliver',
			allow_integrations: pop.find('.sspa-ck-integrations').is(':checked') ? 1 : 0,
			allow_webhooks: pop.find('.sspa-ck-webhooks').is(':checked') ? 1 : 0
		}, function (resp) {
			if (!resp.success) {
				error(resp.data || wp.i18n.__('Request failed.', 'super-speedy-performance-analysis'));
				return;
			}
			drive(resp.data.run_id);
		});
	});
})(jQuery);
