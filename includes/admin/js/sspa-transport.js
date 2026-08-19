/**
 * Browser transport driver: when a run's preflight found loopbacks blocked (basic
 * auth, WAF, CDN), the server hands this loop one prepared request at a time and the
 * admin's browser fetches it - carrying the basic-auth credentials and clearance
 * cookies the server's own requests lack. Same-origin, with credentials; identity is
 * decided server-side by the signed token, not by the cookies.
 *
 * Design: .docs/2026-08-13-browser-driven-transport.md. Loaded by sspa-admin.js,
 * sspa-adhoc.js and Scalability Pro's good-settings popover; all three start it via
 * SSPATransport.drive() when run status reports transport 'browser'.
 */
window.SSPATransport = (function () {
	'use strict';

	var RETRY_MAX = 6;

	// One driver per run, page-wide. The admin screen's pump can be (re)started by
	// more than one code path; a second concurrent driver would double-fetch every
	// request and supersede the first's tokens, polluting samples with no_canary.
	var active = {};

	function retryDelay(attempt) {
		return Math.min(2000 * Math.pow(2, attempt), 15000);
	}

	function post(ajaxurl, data) {
		return fetch(ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: new URLSearchParams(data).toString()
		}).then(function (r) {
			if (!r.ok) {
				var err = new Error('HTTP ' + r.status);
				err.transient = (r.status === 503 || r.status === 429 || r.status === 502 || r.status === 504);
				throw err;
			}
			return r.json();
		});
	}

	function fetchPage(job) {
		var opts = {
			credentials: 'include',
			cache: 'no-store',
			redirect: 'follow',
			headers: {}
		};
		opts.headers[job.header_name] = job.header_value;
		var start = performance.now();
		return fetch(job.url, opts).then(function (response) {
			// Read the body to completion: PHP must never be cut off before the
			// shutdown hook stores the capture. The snippet also feeds the
			// security-layer classifier server-side.
			return response.text().then(function (body) {
				var headers = {};
				(job.echo_headers || []).forEach(function (name) {
					var value = response.headers.get(name);
					if (value !== null) {
						headers[name] = value;
					}
				});
				return {
					code: response.status,
					error: '',
					redirected: response.redirected ? 1 : 0,
					final_url: response.redirected ? response.url : '',
					duration_ms: Math.round(performance.now() - start),
					headers: JSON.stringify(headers),
					body_snippet: body.substring(0, 20000)
				};
			});
		}).catch(function (e) {
			return {
				code: 0,
				error: String(e && e.message ? e.message : e),
				redirected: 0,
				final_url: '',
				duration_ms: Math.round(performance.now() - start),
				headers: '{}',
				body_snippet: ''
			};
		});
	}

	/**
	 * Drive a browser-transport run to completion.
	 *
	 * @param {Object} opts {ajaxurl, nonce, runId, onProgress(progress), onDone(), onFail(message)}
	 */
	function drive(opts) {
		if (active[opts.runId]) {
			return active[opts.runId];
		}
		var driving = true;
		// This driver's identity for the server-side ownership claim: only one tab may
		// drive a run at a time (the server holds others off and lets them take over
		// when the owner goes quiet for 30s).
		var driverId = 'd' + Math.random().toString(36).slice(2) + Date.now().toString(36);

		function fail(message) {
			if (driving) {
				driving = false;
				delete active[opts.runId];
				if (opts.onFail) { opts.onFail(message); }
			}
		}

		function done() {
			if (driving) {
				driving = false;
				delete active[opts.runId];
				if (opts.onDone) { opts.onDone(); }
			}
		}

		function record(job, result, attempt) {
			var payload = {
				action: 'sspa_browser_record',
				nonce: opts.nonce,
				run_id: opts.runId,
				driver: driverId,
				seq: job.seq,
				code: result.code,
				error: result.error,
				redirected: result.redirected,
				final_url: result.final_url,
				duration_ms: result.duration_ms,
				headers: result.headers,
				body_snippet: result.body_snippet
			};
			post(opts.ajaxurl, payload).then(function (resp) {
				if (!resp || resp.success === false) {
					var data = resp && resp.data ? resp.data : {};
					// The run finished or was cancelled elsewhere - not a failure here.
					if (data.code === 'sspa_not_crawling') { done(); return; }
					fail(data.message || 'record refused');
					return;
				}
				var progress = resp.data || {};
				if (opts.onProgress) { opts.onProgress(progress); }
				if (progress.outcome) {
					var o = progress.outcome;
					var line = '[SSPA] ' + (progress.label || '') + ' -> HTTP ' + o.code;
					if (o.blocked_by) {
						console.warn(line + ' BLOCKED by ' + o.blocked_by);
					} else if (o.error) {
						console.warn(line + ' - ' + o.error);
					} else if (o.cached) {
						console.info(line + ' (cache answered, retrying)');
					} else {
						console.info(line + (o.note ? ' (' + o.note + ')' : ' ok'));
					}
				}
				next(0);
			}).catch(function (e) {
				// Safe to resend: the server acknowledges a duplicate seq without
				// advancing twice.
				if (e.transient !== false && attempt < RETRY_MAX) {
					setTimeout(function () { record(job, result, attempt + 1); }, retryDelay(attempt));
					return;
				}
				fail(String(e && e.message ? e.message : e));
			});
		}

		function next(attempt) {
			if (!driving) { return; }
			post(opts.ajaxurl, { action: 'sspa_browser_next', nonce: opts.nonce, run_id: opts.runId, driver: driverId }).then(function (resp) {
				if (!resp || resp.success === false) {
					var data = resp && resp.data ? resp.data : {};
					if (data.code === 'sspa_not_crawling') { done(); return; }
					fail(data.message || 'next-job refused');
					return;
				}
				var job = resp.data || {};
				if (job.status === 'done') { done(); return; }
				if (job.status === 'busy') {
					// Another tab is driving this run. Hold off; if it goes quiet the
					// server lets this tab take over.
					setTimeout(function () { next(0); }, (job.retry_in || 15) * 1000);
					return;
				}
				if (job.status !== 'request') { fail('unexpected response'); return; }
				fetchPage(job).then(function (result) {
					record(job, result, 0);
				});
			}).catch(function (e) {
				// Safe to resend: next never advances the queue.
				if (e.transient !== false && attempt < RETRY_MAX) {
					setTimeout(function () { next(attempt + 1); }, retryDelay(attempt));
					return;
				}
				fail(String(e && e.message ? e.message : e));
			});
		}

		next(0);
		active[opts.runId] = {
			stop: function () {
				driving = false;
				delete active[opts.runId];
			}
		};
		return active[opts.runId];
	}

	return { drive: drive };
})();
