// Edit-screen "Analyse update/save": profiles the real write request. Classic editors put
// the signed token in a hidden form field and redirect; block editors get the same token on
// their final fetch request as a header. The editor reload is never measured.
(function ($) {
	'use strict';

	var cfg = window.sspa_admin_save || {};
	var rawFetch = window.fetch ? window.fetch.bind(window) : null;
	var armedFetch = null;
	var STORAGE_KEY = 'sspa_admin_save_pending';
	var workflow = cfg.workflow && cfg.workflow.active ? cfg.workflow : null;

	function notifyWorkflow(type, data) {
		if (!workflow && !(data && data.workflow)) {
			return;
		}
		if (window.parent && window.parent !== window) {
			window.parent.postMessage(Object.assign({ source: 'sspa-workflow', type: type }, data || {}), window.location.origin);
		}
	}

	function status(message, detail) {
		if (window.sspaPanel && window.sspaPanel.openStatus) {
			window.sspaPanel.openStatus(message, detail);
		}
	}

	function error(message) {
		if (workflow) {
			notifyWorkflow('error', { message: message });
			return;
		}
		if (window.sspaPanel && window.sspaPanel.openError) {
			window.sspaPanel.openError(message);
		} else {
			window.alert(message);
		}
	}

	function contextData() {
		return {
			screen_id: cfg.screen_id || '',
			object_type: cfg.object_type || '',
			object_id: cfg.object_id || 0
		};
	}

	function ajax(data) {
		var body = new URLSearchParams(data);
		return rawFetch(cfg.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		});
	}

	// A completed opted-in save has just created an outbox row. Drain it while this browser is
	// definitely present; the server returns immediately when sharing is off or nothing is due.
	function driveSubmissions() {
		ajax({ action: 'sspa_submission_tick', nonce: cfg.nonce }).then(function (response) {
			if (response && response.success && response.data && response.data.more) {
				window.setTimeout(driveSubmissions, 1500);
			}
		}).catch(function () {});
	}

	function prepare(url, method) {
		var data = contextData();
		data.action = 'sspa_admin_save_prepare';
		data.nonce = cfg.nonce;
		data.url = url;
		data.method = method;
		if (workflow) {
			data.mail_mode = workflow.mail_mode || 'suppress';
			data.trigger = 'workflow';
			data.workflow_transport = workflow.transport || '';
		}
		return ajax(data).then(function (response) {
			if (!response || !response.success) {
				var detail = response && response.data ? response.data : {};
				throw new Error(detail.message || detail || cfg.i18n.failed);
			}
			return response.data;
		});
	}

	function finish(prepared, report, attempt) {
		attempt = attempt || 0;
		return ajax({
			action: 'sspa_admin_save_finish',
			nonce: cfg.nonce,
			run_id: prepared.run_id,
			token_id: prepared.token_id,
			code: report.code || 0,
			duration_ms: report.duration_ms || 0
		}).then(function (response) {
			if (response && response.success) {
				window.sessionStorage.removeItem(STORAGE_KEY);
				driveSubmissions();
				if (workflow || prepared.workflow) {
					notifyWorkflow('complete', {
						profile_id: response.data.profile_id,
						run_id: response.data.run_id,
						workflow: true
					});
				} else if (window.sspaPanel) {
					window.sspaPanel.openProfile(response.data.profile_id);
				}
				return response.data;
			}
			var detail = response && response.data ? response.data : {};
			if ('sspa_admin_save_pending' === detail.code && attempt < 12) {
				return new Promise(function (resolve) {
					window.setTimeout(function () {
						resolve(finish(prepared, report, attempt + 1));
					}, 250);
				});
			}
			throw new Error(detail.message || detail || cfg.i18n.failed);
		});
	}

	function requestUrl(input) {
		return String(input && input.url ? input.url : input || '');
	}

	function requestMethod(input, init) {
		return String((init && init.method) || (input && input.method) || 'GET').toUpperCase();
	}

	function isTargetWrite(url, method) {
		if (!armedFetch || !/^(POST|PUT|PATCH)$/.test(method)) {
			return false;
		}
		var parsed;
		try {
			parsed = new URL(url, window.location.href);
		} catch (e) {
			return false;
		}
		if (parsed.origin !== window.location.origin || parsed.href.indexOf(cfg.ajaxurl) === 0 || parsed.pathname.indexOf('/autosaves') !== -1) {
			return false;
		}
		var id = String(cfg.object_id || '');
		var restRoute = parsed.searchParams.get('rest_route') || '';
		return !!id && (
			new RegExp('/' + id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?:/|$)').test(parsed.pathname)
			|| new RegExp('/' + id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '(?:/|$)').test(restRoute)
			|| parsed.searchParams.get('id') === id
			|| parsed.searchParams.get('post') === id
		);
	}

	// apiFetch reaches window.fetch only after its REST-root, nonce and locale middlewares have
	// produced the final URL. Intercepting here lets the server bind the HMAC to the exact URI.
	if (rawFetch) {
		window.fetch = function (input, init) {
			var url = requestUrl(input);
			var method = requestMethod(input, init);
			if (!isTargetWrite(url, method)) {
				return rawFetch(input, init);
			}

			armedFetch = null; // exactly one save request
			var started = window.performance.now();
			return prepare(url, method).then(function (prepared) {
				var headers = new Headers((init && init.headers) || (input && input.headers) || {});
				headers.set(prepared.header_name, prepared.token);
				var request;
				if (input instanceof Request) {
					request = new Request(input, Object.assign({}, init || {}, { headers: headers }));
				} else {
					request = input;
					init = Object.assign({}, init || {}, { headers: headers });
				}
				return rawFetch(request, init).then(function (response) {
					finish(prepared, {
						code: response.status,
						duration_ms: Math.round(window.performance.now() - started)
					}).catch(function (e) { error(e.message || cfg.i18n.failed); });
					return response;
				}, function (e) {
					finish(prepared, {
						code: 0,
						duration_ms: Math.round(window.performance.now() - started)
					}).catch(function () {});
					throw e;
				});
			});
		};
	}

	function classicControl(allowHidden) {
		var selectors = [
			'#publish:enabled',
			'button.save_order:enabled',
			'button[name="save"]:enabled',
			'input[name="save"]:enabled'
		];
		for (var i = 0; i < selectors.length; i++) {
			var found = $(selectors[i]);
			if (!allowHidden) {
				found = found.filter(':visible');
			}
			found = found.first();
			if (found.length && found[0].form) {
				return found[0];
			}
		}
		return null;
	}

	function classicSave(submitter) {
		var form = submitter.form;
		if (form.reportValidity && !form.reportValidity()) {
			return;
		}
		var target = new URL(form.getAttribute('action') || window.location.href, window.location.href).href;
		var method = String(form.getAttribute('method') || 'POST').toUpperCase();
		status(cfg.i18n.saving, workflow ? cfg.i18n.workflow_detail : cfg.i18n.detail);
		prepare(target, method).then(function (prepared) {
			var field = form.querySelector('input[name="' + cfg.field_name + '"]');
			if (!field) {
				field = document.createElement('input');
				field.type = 'hidden';
				field.name = cfg.field_name;
				form.appendChild(field);
			}
			field.value = prepared.token;
			window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
				run_id: prepared.run_id,
				token_id: prepared.token_id,
				started: Date.now(),
				workflow: !!workflow
			}));
			submitter.click();
		}).catch(function (e) {
			error(e.message || cfg.i18n.failed);
		});
	}

	function blockSave() {
		var dispatch = window.wp && window.wp.data ? window.wp.data.dispatch('core/editor') : null;
		if (!dispatch || !dispatch.savePost || !rawFetch) {
			error(cfg.i18n.no_control);
			return;
		}
		status(cfg.i18n.saving, cfg.i18n.detail);
		armedFetch = { started: Date.now() };
		dispatch.savePost();
		window.setTimeout(function () {
			if (armedFetch) {
				armedFetch = null;
				error(cfg.i18n.no_request);
			}
		}, 15000);
	}

	// A workflow REST save must issue a request even when the editor has no dirty fields.
	// An empty update reaches the real controller and fires its normal save cascade without
	// SSPA changing a title, taxonomy or meta value merely to make the editor look dirty.
	function workflowRestSave() {
		if (!workflow || !workflow.rest_url || !window.wp || !window.wp.apiFetch || !rawFetch) {
			error(cfg.i18n.no_control);
			return;
		}
		status(cfg.i18n.saving, cfg.i18n.workflow_detail);
		armedFetch = { started: Date.now() };
		window.wp.apiFetch({
			url: workflow.rest_url,
			method: 'POST',
			data: {}
		}).catch(function (e) {
			if (armedFetch) {
				armedFetch = null;
			}
			error((e && e.message) || cfg.i18n.failed);
		});
		window.setTimeout(function () {
			if (armedFetch) {
				armedFetch = null;
				error(cfg.i18n.no_request);
			}
		}, 15000);
	}

	function runEditorSave(event) {
		event.preventDefault();
		var submitter = classicControl();
		if (submitter) {
			classicSave(submitter);
			return;
		}
		blockSave();
	}

	$(document).on('click', '#wp-admin-bar-sspa-admin-save > a, .sspa-admin-save-editor-button', runEditorSave);

	// Gutenberg's fullscreen mode deliberately hides the WordPress admin bar. Put the same
	// action in the block editor header so the profiler remains reachable without changing the
	// administrator's editor preference. Gutenberg can rebuild this header, so restore the
	// button after React removes it rather than assuming the first render is permanent.
	function addBlockEditorButton() {
		if (!$('body').hasClass('block-editor-page') || $('.sspa-admin-save-editor-button').length) {
			return;
		}
		var toolbar = $('.editor-header__toolbar, .edit-post-header-toolbar').first();
		if (!toolbar.length) {
			return;
		}
		$('<button>', {
			type: 'button',
			'class': 'components-button is-compact sspa-admin-save-editor-button',
			text: cfg.i18n.editor_button,
			title: cfg.i18n.editor_button_title,
			'aria-label': cfg.i18n.editor_button_title
		}).appendTo(toolbar);
	}

	if (window.MutationObserver) {
		var editorObserver = new MutationObserver(addBlockEditorButton);
		editorObserver.observe(document.body, { childList: true, subtree: true });
	}
	addBlockEditorButton();

	// A classic save has already redirected by the time this script runs again. The token id
	// in sessionStorage identifies the capture; no timings from this editor reload are used.
	var pendingRaw = window.sessionStorage.getItem(STORAGE_KEY);
	var resumedPending = false;
	if (pendingRaw) {
		try {
			var pending = JSON.parse(pendingRaw);
			if (pending.run_id && pending.token_id && Date.now() - pending.started < 10 * 60 * 1000) {
				resumedPending = true;
				if (pending.workflow) {
					workflow = { active: true };
				}
				status(cfg.i18n.saving, pending.workflow ? cfg.i18n.workflow_detail : cfg.i18n.detail);
				finish(pending, { code: 302, duration_ms: 0 }).catch(function (e) {
					error(e.message || cfg.i18n.failed);
				});
			} else {
				window.sessionStorage.removeItem(STORAGE_KEY);
			}
		} catch (e) {
			window.sessionStorage.removeItem(STORAGE_KEY);
		}
	}

	if (workflow && !resumedPending) {
		window.setTimeout(function () {
			if ('rest' === workflow.transport) {
				workflowRestSave();
				return;
			}
			var submitter = classicControl(true);
			if (!submitter) {
				error(cfg.i18n.no_control);
				return;
			}
			classicSave(submitter);
		}, 0);
	}
})(jQuery);
