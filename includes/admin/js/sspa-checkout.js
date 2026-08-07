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
			'<button type="button" class="sspa-adhoc-close" aria-label="Close">&times;</button></div>' +
			'<div class="sspa-adhoc-body"></div>' +
			'<div class="sspa-adhoc-foot">Powered by <strong>Super Speedy Performance Analysis</strong> &middot; free from <a href="https://www.superspeedyplugins.com/" target="_blank" rel="noopener">superspeedyplugins.com</a></div>' +
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
			'<p><button type="button" class="sspa-adhoc-btn sspa-ck-preflight">Try again</button></p>');
	}

	// ---------------- the disclosure ----------------

	function renderDisclosure(d) {
		var inv = d.inventory;
		var html = '<div class="sspa-adhoc-span"><h3 class="sspa-ck-h">Before this runs, here is exactly what it will do</h3>';

		if (!inv.woocommerce) {
			body('<p class="sspa-adhoc-error">WooCommerce is not active, so there is no checkout to profile.</p>');
			return;
		}
		if ('block' !== inv.checkout_type && 'classic' !== inv.checkout_type) {
			html += '<p class="sspa-adhoc-error">This store\'s checkout page uses neither the checkout block ' +
				'nor the <code>[woocommerce_checkout]</code> shortcode, so there is no purchase flow to drive. ' +
				'If a page builder renders your checkout, this cannot measure it yet.</p></div>';
			body(html);
			return;
		}

		html += '<p class="sspa-adhoc-note">Measuring your <strong>' +
			('block' === inv.checkout_type ? 'block' : 'shortcode') + '</strong> checkout. ' +
			'It buys something, for real, with every plugin on your site active. ' +
			'That is the point: a run that switches your integrations off measures a store nobody has. ' +
			'The order is <strong>cancelled and deleted</strong> afterwards and stock is put back.</p>';

		html += '<ul class="sspa-ck-list">';
		if (inv.product) {
			html += '<li><strong>Buys</strong> ' + esc(inv.product.name) + ' (' + inv.product.price + ')' +
				(inv.product.manages_stock ? ', taking 1 off your stock and putting it back' : '') + '</li>';
		}
		var emails = (inv.emails || []).filter(function (e) { return e.enabled; });
		if (emails.length) {
			// "Can send up to", not "sends": which of these fire depends on the status the
			// order reaches. On a typical run it is the new-order and processing ones.
			html += '<li><strong>Can send up to ' + emails.length + ' order email' + (1 === emails.length ? '' : 's</strong>, depending on the status the order reaches') + ': ' +
				esc(emails.map(function (e) { return e.title + (e.recipient ? ' → ' + e.recipient : ''); }).join('; ')) +
				'. Customer emails go to <code>' + esc(inv.email_to) + '</code>' +
				(inv.emails_deferred
					? '. Your store defers these to the background, so they cost your customer nothing'
					: '. Your store sends these <strong>inside the checkout request</strong>, so your customer waits for them') +
				'</li>';
		}
		if ((inv.webhooks || []).length) {
			var hosts = {};
			inv.webhooks.forEach(function (w) { hosts[w.host] = 1; });
			html += '<li><strong>Fires ' + inv.webhooks.length + ' webhook' + (1 === inv.webhooks.length ? '' : 's') + '</strong> to ' +
				esc(Object.keys(hosts).join(', ')) +
				(inv.webhooks_inline
					? ' (delivered inside the request, so your customer waits for them)'
					: ' (queued in the background, so not part of your customer\'s wait)') + '</li>';
		}
		if ((inv.order_hooks || []).length) {
			html += '<li><strong>' + inv.order_hooks.length + ' component' + (1 === inv.order_hooks.length ? '' : 's') +
				' will run code</strong> when this order is created: ' +
				esc(inv.order_hooks.map(function (c) { return c.component; }).join(', ')) +
				(inv.order_hooks_more ? ' and ' + inv.order_hooks_more + ' more' : '') + '</li>';
		}
		if (inv.needs_payment_filtered) {
			html += '<li class="sspa-ck-warn">Another plugin already filters WooCommerce\'s "needs payment" checks. ' +
				'This run uses those same filters to skip the gateway, so the two may interact.</li>';
		}
		html += '</ul>';

		// Payment mode. no_payment is always offered and preselected.
		html += '<h4 class="sspa-ck-h4">Payment</h4>';
		(inv.payment_modes || []).forEach(function (mode) {
			if (!mode.available) {
				html += '<p class="sspa-adhoc-note sspa-ck-unavailable"><em>' + esc(mode.label) + '</em> &mdash; ' + esc(mode.note) + '</p>';
				return;
			}
			html += '<label class="sspa-ck-opt"><input type="radio" name="sspa-ck-pm" value="' + esc(mode.key) + '"' +
				('no_payment' === mode.key ? ' checked' : '') + '> <strong>' + esc(mode.label) + '</strong> ' +
				'<span class="sspa-adhoc-note">' + esc(mode.note) + '</span></label>';
		});

		html += '<h4 class="sspa-ck-h4">Leave everything on for a real measurement</h4>' +
			'<label class="sspa-ck-opt"><input type="checkbox" class="sspa-ck-mail" checked> Send the order emails for real (measures your mail server too)</label>' +
			'<label class="sspa-ck-opt"><input type="checkbox" class="sspa-ck-integrations" checked> Let third-party integrations run</label>' +
			'<label class="sspa-ck-opt"><input type="checkbox" class="sspa-ck-webhooks" checked> Let webhooks fire</label>' +
			'<p class="sspa-adhoc-note">Turning any of these off is recorded with the result, so a partial run cannot later be mistaken for a full one.</p>';

		html += '<p class="sspa-ck-actions">' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-ck-go">Buy something and measure it</button> ' +
			'<button type="button" class="sspa-adhoc-btn sspa-ck-cancel">Cancel</button></p></div>';
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
	function rows(list, max, slowest) {
		var html = '';
		list.forEach(function (row) {
			var isSlowest = row.label === slowest;
			html += '<tr' + (isSlowest ? ' class="is-slowest"' : '') + '>' +
				'<td>' + esc(row.label) + (isSlowest ? ' <span class="sspa-ck-tag">slowest step</span>' : '') + '</td>' +
				'<td class="sspa-ck-num">' + ms(row.gen_ms) + '</td>' +
				'<td class="sspa-ck-barcell">' + bar(row.gen_ms, max) + '</td>' +
				'<td class="sspa-ck-num">' + (row.sql_count == null ? '&mdash;' : esc(row.sql_count) + ' q') + '</td>' +
				'</tr>';
		});
		return html;
	}

	function renderResult(d) {
		var all = d.at_risk.concat(d.secured);
		var max = 0;
		all.forEach(function (r) { if (r.gen_ms > max) { max = r.gen_ms; } });

		var html = '<div class="sspa-adhoc-topbar sspa-adhoc-span">' +
			'<button type="button" class="sspa-adhoc-btn sspa-adhoc-btn-primary sspa-ck-preflight">Run again</button>' +
			'<span class="sspa-adhoc-note">' + esc(d.created || '') + '</span>' +
			'<a class="sspa-adhoc-open" href="' + esc(sspa_checkout.results_url) + '">Open in Performance Analysis &rarr;</a></div>';

		if ('failed' === d.status) {
			html += '<p class="sspa-adhoc-error sspa-adhoc-span">The purchase did not complete: <code>' +
				esc(d.notes && d.notes.outcome ? d.notes.outcome : 'unknown') + '</code>' +
				(d.notes && d.notes.flow && d.notes.flow.error ? ' &mdash; ' + esc(d.notes.flow.error) : '') +
				'. The steps below are what was measured before it stopped.</p>';
		}

		html += '<div class="sspa-adhoc-span"><h3 class="sspa-ck-h sspa-ck-atrisk">At risk &mdash; your customer can still walk away during this</h3>' +
			'<table class="sspa-adhoc-table sspa-ck-table">' + rows(d.at_risk, max, d.slowest) +
			'<tr class="sspa-ck-total"><td>At-risk total</td><td class="sspa-ck-num">' + ms(d.at_risk_ms) +
			'</td><td colspan="2" class="sspa-ck-wide">every second here can cost you the sale</td></tr></table>';

		html += '<h3 class="sspa-ck-h sspa-ck-secured">Secured &mdash; the money is taken, they are waiting on a confirmation</h3>' +
			'<table class="sspa-adhoc-table sspa-ck-table">' + rows(d.secured, max, d.slowest) +
			'<tr class="sspa-ck-total"><td>Post-capture</td><td class="sspa-ck-num">' + ms(d.secured_ms) +
			'</td><td colspan="2" class="sspa-ck-wide">a bad impression, not a lost sale</td></tr></table>';

		html += '<table class="sspa-adhoc-table sspa-ck-table sspa-ck-grand"><tr><td>Your customer waited</td>' +
			'<td class="sspa-ck-num">' + ms(d.total_ms) + '</td>' +
			'<td colspan="2" class="sspa-ck-wide">&nbsp;</td></tr></table>' +
			'<p class="sspa-adhoc-note">' + esc(d.basis) + '</p>';

		if (false === d.boundary_known) {
			html += '<p class="sspa-adhoc-note">The payment boundary could not be determined for this run &mdash; ' +
				'nothing fired <code>woocommerce_payment_complete</code>, so place-order is shown as one row rather than guessed at.</p>';
		}
		if (d.payment_caveat) {
			html += '<p class="sspa-adhoc-note">&#9432; ' + esc(d.payment_caveat) + '</p>';
		}
		d.excluded.forEach(function (row) {
			html += '<p class="sspa-adhoc-note">' + esc(row.label) + ': ' + ms(row.gen_ms) + ' &mdash; measured, but nobody waits for it, so it is not in the total.</p>';
		});

		// What the findings engine made of it.
		if ((d.findings || []).length) {
			// Deliberately not a table: the panel's table styling right-aligns and
			// monospaces the last cell, which is wrong for a paragraph of advice.
			html += '<h4 class="sspa-ck-h4">What to fix</h4>';
			d.findings.forEach(function (f) {
				html += '<div class="sspa-ck-finding"><span class="sspa-ck-sev is-' + esc(f.severity) + '">' + esc(f.severity) + '</span> ' +
					'<strong>' + esc(f.recommendation.title) + '</strong>' + (f.component ? ' <code>' + esc(f.component) + '</code>' : '') +
					(f.evidence && f.evidence.label ? ' <span class="sspa-adhoc-note">at ' + esc(f.evidence.label) + '</span>' : '') +
					'<div class="sspa-adhoc-note">' + esc(f.recommendation.body) + '</div></div>';
			});
		}

		if (d.mail && d.mail.count) {
			var deferred = d.notes && d.notes.inventory && d.notes.inventory.emails_deferred;
			html += '<p class="sspa-adhoc-note"><strong>Mail:</strong> ' + esc(d.mail.count) + ' message' +
				(1 === d.mail.count ? '' : 's') + ' in ' + ms(d.mail.ms) + ', ' +
				(deferred ? 'deferred to the background' : 'sent inside the customer\'s wait') + '.</p>';
		}
		if ((d.http || []).length) {
			html += '<h4 class="sspa-ck-h4">Outbound calls during the purchase</h4><table class="sspa-adhoc-table sspa-ck-table">';
			d.http.forEach(function (c) {
				html += '<tr><td><code>' + esc(c.url) + '</code><div class="sspa-adhoc-note">' + esc(c.step) + ' &middot; ' + esc(c.component) + '</div></td>' +
					'<td class="sspa-ck-num">' + ms(c.ms) + '</td></tr>';
			});
			html += '</table>';
		}

		if (d.profile && d.profile.components && d.profile.components.length) {
			html += '<h4 class="sspa-ck-h4">Where the PHP time went across the whole purchase</h4><table class="sspa-adhoc-table sspa-ck-table">';
			d.profile.components.slice(0, 10).forEach(function (c) {
				html += '<tr><td><code>' + esc(c.component) + '</code></td><td class="sspa-ck-num">' + ms(c.ms) + '</td></tr>';
			});
			html += '</table><p class="sspa-adhoc-note">' + esc(d.profile.note) + '</p>';
		} else {
			html += '<p class="sspa-adhoc-note">No sampling profile: the Excimer extension is not installed. The Tools tab explains how to add it.</p>';
		}

		// The safety report, stated rather than assumed.
		var safety = d.notes && d.notes.safety;
		if (safety) {
			html += '<p class="sspa-adhoc-note"><strong>Cleanup:</strong> ' +
				esc(safety.orders_deleted) + ' order' + (1 === safety.orders_deleted ? '' : 's') + ' deleted, ' +
				(safety.orders_left ? '<strong class="sspa-ck-warn">' + esc(safety.orders_left) + ' still present</strong>' : 'none left behind') +
				(null === safety.stock_before
					? ', stock is not managed for this product'
					: ', stock ' + esc(safety.stock_before) + ' &rarr; ' + esc(safety.stock_after) +
					  (safety.stock_restored_manually ? ' (put back explicitly - cancelling the order did not restore it)' : '')) +
				'.</p>';
		}
		(d.notes && d.notes.skipped ? d.notes.skipped : []).forEach(function (s) {
			html += '<p class="sspa-adhoc-note">Skipped <code>' + esc(s.step) + '</code>: ' + esc(s.why) + '</p>';
		});
		html += '</div>';
		body(html);
	}

	// ---------------- driving a run ----------------

	function renderProgress(status) {
		var elapsed = status && status.elapsed_seconds ? Math.round(status.elapsed_seconds) + 's' : '';
		body('<p class="sspa-adhoc-running sspa-adhoc-span"><span class="sspa-adhoc-spin"></span>Buying something and measuring it' +
			(elapsed ? ' <span class="sspa-adhoc-elapsed">' + esc(elapsed) + '</span>' : '') + '</p>' +
			'<p class="sspa-adhoc-note sspa-adhoc-span">One purchase, start to finish, then the order is deleted again. Usually under a minute.</p>');
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
					error('The run could not be advanced.');
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
				error('The run ended as "' + status.status + '".');
			}).fail(function () {
				if (++failures < 4) {
					window.setTimeout(step, 2000);
					return;
				}
				driving = false;
				error('Request failed.');
			});
		}
		renderProgress(null);
		step();
	}

	function showResult(runId) {
		post('sspa_checkout_result', { run_id: runId || 0 }, function (resp) {
			if (!resp.success) {
				error(resp.data || 'Request failed.');
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
		body('<p class="sspa-adhoc-running sspa-adhoc-span"><span class="sspa-adhoc-spin"></span>Checking what a purchase would set off on this store&hellip;</p>');
		post('sspa_checkout_preflight', {}, function (resp) {
			if (!resp.success) {
				error(resp.data || 'Request failed.');
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
			mail_mode: pop.find('.sspa-ck-mail').is(':checked') ? 'deliver' : 'construct',
			allow_integrations: pop.find('.sspa-ck-integrations').is(':checked') ? 1 : 0,
			allow_webhooks: pop.find('.sspa-ck-webhooks').is(':checked') ? 1 : 0
		}, function (resp) {
			if (!resp.success) {
				error(resp.data || 'Request failed.');
				return;
			}
			drive(resp.data.run_id);
		});
	});
})(jQuery);
