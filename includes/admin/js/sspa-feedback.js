/**
 * Shared error wording and clipboard handling for every Performance Analysis screen (0.70.6).
 *
 * Errors: one fallback sentence used to cover an HTTP failure, an expired nonce, an unknown
 * action, a server refusal and an empty answer alike, so the real cause was hidden. These
 * helpers keep the server's own message when it sent one, and otherwise say which of those
 * happened, with the HTTP status.
 *
 * Clipboard: browsers only allow a clipboard write while the click's user activation is still
 * active (five seconds in Chromium). copyText() writes inside the click; copyLater() starts a
 * ClipboardItem write inside the click from a promise of text that arrives later. When the
 * browser still refuses, the text is shown selected in a dialog to copy by hand.
 */
window.SSPAFeedback = (function () {
	'use strict';

	var D = 'super-speedy-performance-analysis';
	function __(text) { return window.wp && wp.i18n ? wp.i18n.__(text, D) : text; }
	function sprintf(format) {
		var args = Array.prototype.slice.call(arguments, 1);
		if (window.wp && wp.i18n) { return wp.i18n.sprintf.apply(null, [format].concat(args)); }
		var i = 0;
		return format.replace(/%(\d+\$)?[sd]/g, function () { return String(args[i++]); });
	}
	function join(first, second) {
		return first ? (second ? first + ' ' + second : first) : second;
	}

	// The message a WordPress JSON error carries: a string, a WP_Error (array of
	// {code, message}) or an object with a message.
	function serverText(data) {
		if (typeof data === 'string') { return data.trim(); }
		if (typeof data === 'number') { return String(data); }
		if (Array.isArray(data)) { return data.map(serverText).filter(Boolean).join(' '); }
		if (data && typeof data === 'object') { return serverText(data.message || data.error || ''); }
		return '';
	}

	// admin-ajax answers -1 when check_ajax_referer() fails and 0 when no handler exists.
	function bareAnswer(body) {
		body = String(body == null ? '' : body).trim();
		if ('-1' === body) { return __('The security token for this screen has expired. Reload the page and try again.'); }
		if ('0' === body) { return __('The server did not recognise this request; the plugin may have been updated or deactivated since the page loaded. Reload the page and try again.'); }
		return '';
	}

	// A request that reached the server and was answered, but not with success:true.
	function response(resp, fallback) {
		if (resp && typeof resp === 'object') {
			return serverText(resp.data) || join(fallback, __('The server gave no reason.'));
		}
		var bare = bareAnswer(resp);
		if (bare) { return join(fallback, bare); }
		if (null == resp || '' === String(resp).trim()) { return join(fallback, __('The server sent an empty answer.')); }
		return join(fallback, __('The server answered, but not with the expected JSON.'));
	}

	// A jQuery request that failed: network, timeout, an HTTP error status or unparseable JSON.
	function xhr(request, fallback, textStatus) {
		var status = request && request.status ? request.status : 0;
		if ('abort' === textStatus) { return join(fallback, __('The request was cancelled.')); }
		if (!status) {
			return join(fallback, 'timeout' === textStatus
				? __('The request timed out.')
				: __('The request did not reach the server (the connection failed or was blocked).'));
		}
		var raw = request.responseText || '';
		var body = request.responseJSON;
		if (!body && raw) { try { body = JSON.parse(raw); } catch (e) { body = null; } }
		var text = body && typeof body === 'object' ? serverText(body.data) : '';
		if (!text) { text = bareAnswer(raw); }
		if (!text && 'parsererror' === textStatus) { text = __('The server answered, but not with the expected JSON.'); }
		/* translators: %d: HTTP status code */
		return join(join(text ? '' : fallback, text), sprintf(__('(HTTP %d)'), status));
	}

	// fetch(): a Response that was not ok, or a network error thrown by fetch itself.
	function fetchFailure(errorOrResponse, fallback) {
		if (errorOrResponse && typeof errorOrResponse.status === 'number' && errorOrResponse.text) {
			return errorOrResponse.text().then(function (raw) {
				return xhr({ status: errorOrResponse.status, responseText: raw }, fallback, 'error');
			}, function () {
				return xhr({ status: errorOrResponse.status, responseText: '' }, fallback, 'error');
			});
		}
		var reason = errorOrResponse && errorOrResponse.message ? errorOrResponse.message : String(errorOrResponse || '');
		return Promise.resolve(join(fallback, reason));
	}

	// ---- clipboard ----

	function execCopy(text) {
		var area = document.createElement('textarea');
		area.value = text;
		area.setAttribute('readonly', '');
		area.style.position = 'fixed';
		area.style.left = '-9999px';
		document.body.appendChild(area);
		area.select();
		var ok = false;
		try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
		document.body.removeChild(area);
		return ok;
	}

	// Copy text that is already in hand. Call it inside the click, before any await.
	function writeNow(text) {
		if (navigator.clipboard && navigator.clipboard.writeText) {
			return navigator.clipboard.writeText(text).catch(function (error) {
				if (execCopy(text)) { return; }
				throw error;
			});
		}
		return execCopy(text) ? Promise.resolve() : Promise.reject(new Error(__('The browser refused the copy command.')));
	}

	/**
	 * Copy text that arrives later. Call it inside the click: it starts the clipboard write at
	 * once from the promise, so the click's user activation is used now. textPromise rejecting
	 * is not a clipboard failure; it rejects the returned promise with {exportError}.
	 * Resolves when copied; if the browser refuses, shows the text to copy by hand and resolves
	 * with {manual: true}.
	 */
	function copyLater(textPromise) {
		var early = null;
		if (window.ClipboardItem && navigator.clipboard && navigator.clipboard.write) {
			try {
				early = navigator.clipboard.write([new window.ClipboardItem({
					'text/plain': Promise.resolve(textPromise).then(function (text) {
						return new Blob([text], { type: 'text/plain' });
					})
				})]);
			} catch (e) {
				early = Promise.reject(e);
			}
			early.catch(function () {}); // settled below, once the text is known
		}
		return Promise.resolve(textPromise).then(function (text) {
			var attempt = early ? early.catch(function () { return writeNow(text); }) : writeNow(text);
			return attempt.then(function () { return { manual: false }; }, function (error) {
				manualCopy(text, error);
				return { manual: true };
			});
		}, function (error) {
			throw { exportError: error };
		});
	}

	// Copy text in hand; on refusal show it to copy by hand. For click handlers.
	function copyText(text) {
		return writeNow(text).then(function () { return { manual: false }; }, function (error) {
			manualCopy(text, error);
			return { manual: true };
		});
	}

	var opener = null;
	function closeManual() {
		var box = document.getElementById('sspa-copy-manual');
		if (box) { box.parentNode.removeChild(box); }
		document.removeEventListener('keydown', escape, true);
		if (opener && opener.focus) { opener.focus(); }
		opener = null;
	}
	function escape(e) {
		if ('Escape' === e.key) { e.preventDefault(); closeManual(); }
	}

	// The browser refused the clipboard: the text, selected, with how to copy it.
	function manualCopy(text, error) {
		closeManual();
		opener = document.activeElement;
		var mac = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent || '');
		var box = document.createElement('div');
		box.id = 'sspa-copy-manual';
		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');
		box.setAttribute('aria-labelledby', 'sspa-copy-manual-title');
		box.style.cssText = 'position:fixed;inset:0;z-index:1000000;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:16px;box-sizing:border-box';
		var panel = document.createElement('div');
		panel.style.cssText = 'background:#fff;color:#1d2327;max-width:760px;width:100%;max-height:90vh;display:flex;flex-direction:column;gap:10px;padding:16px;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.3);font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif';
		var title = document.createElement('h2');
		title.id = 'sspa-copy-manual-title';
		title.style.cssText = 'margin:0;font-size:16px';
		title.textContent = __('Copy the report');
		var hint = document.createElement('p');
		hint.style.margin = '0';
		var reason = error && error.name ? ' (' + error.name + ')' : '';
		hint.textContent = sprintf(
			/* translators: 1: the browser's error name in brackets, or nothing, 2: the copy shortcut */
			__('Your browser did not allow the automatic copy%1$s. The report is selected below: press %2$s to copy it.'),
			reason,
			mac ? __('Cmd+C') : __('Ctrl+C (Cmd+C on a Mac)')
		);
		var area = document.createElement('textarea');
		area.readOnly = true;
		area.value = text;
		area.setAttribute('aria-label', __('Report text'));
		area.style.cssText = 'width:100%;min-height:240px;flex:1;font:12px/1.4 Menlo,Consolas,monospace;box-sizing:border-box';
		var actions = document.createElement('div');
		actions.style.cssText = 'display:flex;gap:8px;align-items:center;flex-wrap:wrap';
		var copy = document.createElement('button');
		copy.type = 'button';
		copy.className = 'button button-primary';
		copy.textContent = __('Copy');
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'button';
		close.textContent = __('Close');
		var status = document.createElement('span');
		status.setAttribute('aria-live', 'polite');
		copy.addEventListener('click', function () {
			area.focus();
			area.select();
			writeNow(text).then(function () {
				status.textContent = __('Copied.');
			}, function (e) {
				area.focus();
				area.select();
				status.textContent = sprintf(
					/* translators: 1: the browser's error name, 2: the copy shortcut */
					__('Still refused (%1$s). Press %2$s instead.'),
					e && e.name ? e.name : __('unknown error'),
					mac ? __('Cmd+C') : __('Ctrl+C')
				);
			});
		});
		close.addEventListener('click', closeManual);
		box.addEventListener('click', function (e) { if (e.target === box) { closeManual(); } });
		actions.appendChild(copy);
		actions.appendChild(close);
		actions.appendChild(status);
		panel.appendChild(title);
		panel.appendChild(hint);
		panel.appendChild(area);
		panel.appendChild(actions);
		box.appendChild(panel);
		document.body.appendChild(box);
		document.addEventListener('keydown', escape, true);
		area.focus();
		area.select();
		area.setSelectionRange(0, text.length);
	}

	return {
		serverText: serverText,
		response: response,
		xhr: xhr,
		fetchFailure: fetchFailure,
		copyText: copyText,
		copyLater: copyLater,
		manualCopy: manualCopy
	};
})();
