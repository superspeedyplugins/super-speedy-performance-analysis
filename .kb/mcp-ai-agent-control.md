# Controlling Super Speedy Performance Analysis with MCP

Connect Super Speedy Performance Analysis to Codex or Claude Code to run measurements, monitor progress, interpret findings and plugin impact, collect real traffic observations and safely profile a checkout workflow.

*Requires WordPress 6.9 or newer, Super Speedy Performance Analysis 0.60.1 or newer for the full set below (0.59.2 without the three parameter check abilities, 0.58.2 without `profile-admin-save` and the loopback settings, 0.23.1 for the original 21), and Node.js 18 or newer on the AI-client computer. Checkout profiling also requires WooCommerce.*

## Connect once for all Super Speedy plugins

Open **Super Speedy > MCP setup**, choose execute access only if the assistant may start analyses, traffic collection, submissions or checkout runs, generate the command, paste it into your terminal and restart the client. The setup page creates or reuses a dedicated **Super Speedy Agent** user and a revocable Application Password. No Performance-specific bridge or endpoint is needed.

## The abilities

The namespace is `sspa`. Since 0.58.2 everything the Performance Analysis screens do is an ability, so an assistant with only the MCP connection can run the whole tool. Start with `get-capabilities`: it lists every workflow and its abilities, whether the profiler is on, which optional instruments (such as Excimer) are installed and how to install the missing ones, whether WP-Cron runs, whether Performance Analysis can reach its own site (and over which route), and the page keys `run-analysis` accepts.

### Read and interpret

- Discovery and runs: `get-capabilities`, `get-status`, `list-runs`, `get-run`
- Results: `get-report`, `get-findings`, `list-page-profiles`, `get-page-profile` (the full page analysis panel), `get-plugins-table`, `get-plugin-impacts`, `get-plugin-impact-detail`, `get-impact-plan`, `get-page-plugin-usage`, `get-http-calls`, `get-archive-profile`, `get-site-metrics`
- Caching: `get-cache-optimisation-analysis`, `get-cache-safety-report`, `get-parameter-check`
- History and reports: `compare-history`, `get-history-series`, `get-markdown-report`
- Checkout, AJAX and traffic: `get-checkout-flow`, `get-endpoint-profiles`, `get-admin-save-targets`, `get-traffic-collection-status`, `get-traffic-observations`, `compare-traffic-collections`
- Settings and sharing: `get-settings`, `get-submission-queue`, `get-payload-preview`, `get-community-figures` (downloads public figures from superspeedy.org)

### Run and change

- Measure: `run-analysis`, `run-deep-analysis`, `profile-url`, `profile-admin-save`, `run-checkout-flow`, `run-cache-check`, `run-parameter-check`, `profile-endpoint`, `start-ajax-window`, `stop-ajax-window`, `compare-ajax-windows`, `start-traffic-collection`, `stop-traffic-collection`
- Keep it moving: `advance-run`, `advance-cache-check`, `advance-parameter-check`, `cancel-run`
- Configure: `set-profiler`, `update-settings`, `set-history-expectation`
- Sharing and quotes: `submit-results`, `process-submissions`, `manage-submission`, `prepare-quote-request`

### Delete or send (need `confirm: true`)

`prune-stored-captures`, `delete-traffic-collection`, `cleanup-workflow-objects`, `send-quote-request`. Enabling the profiler with `set-profiler` also needs `confirm: true`, because it writes the profiling loader into `wp-content/mu-plugins`.

## Recommended analysis workflow

1. `get-capabilities`, and enable the profiler with the owner's agreement if it is off.
2. `run-analysis`, then call `advance-run` until the run is no longer active. Do not rely on polling `get-status`: a run started over MCP otherwise only moves when WP-Cron fires.
3. Read `get-report` and `get-findings`, then `list-page-profiles` and `get-page-profile` for the slow pages, and `get-plugins-table` for per-plugin costs on one page or all.
4. To prove which plugin costs the time, `get-impact-plan`, then `run-deep-analysis` with the suspects and `advance-run`, then `get-plugin-impacts` and `get-plugin-impact-detail`.
5. For any other URL, `profile-url`, `advance-run`, then `get-page-profile` with the URL.
6. For page caching, `run-cache-check`, then `advance-cache-check` until done, then `get-cache-optimisation-analysis`. Over MCP the browser's own timing is not measured; every server-side request is. When the first anonymous response sets a cookie, the check also requests each page as a second new visitor and as a returning visitor carrying only that cookie, and `new_visitors` names the cookie and the plugin that sets it.
7. To measure what saving a post, page, product or order costs, `get-admin-save-targets`, then `profile-admin-save` with the `object_type` (and `transport`, `rest` or `classic`, when more than one is offered), then `get-page-profile` with the returned `profile_id`.
8. For visitors arriving from ads and campaign emails, `run-parameter-check`, then `advance-parameter-check` until done, then `get-parameter-check`: per parameter (`fbclid`, `gclid`, `utm_source` and others) the cookies it makes the site set, which plugin sets them, and whether the page cache ignores it, keys it separately or bypasses it. It sends up to 118 requests to the site, so ask the owner first.

## Profiling an editor save

`profile-admin-save` does over MCP what the Workflows tab does in the browser. It creates a temporary object of the chosen type, loads its edit screen as the agent user, and sends the editor's real save request once, with the profiler watching: the block editor's REST update, or the classic editor form submitted with its Update button. The save runs every normal hook, so emails, webhooks and integrations behave as they would for a person clicking Update; emails go to the site administrator. The temporary object is deleted afterwards, and `temporary_object_removed` in the result confirms it. Existing content is never touched. The call is synchronous: there is no run to advance.

## Sites that block their own requests

Every measurement is Performance Analysis requesting the site from its own server. A firewall, CDN or security plugin in front of the site often refuses those requests, and a staging site behind HTTP basic auth answers 401 to them. In the admin screens the browser can make the requests instead; over MCP there is no browser, so Performance Analysis tries these in order, and `get-capabilities` reports the result in `prerequisites.loopback`:

1. **The public route**: the site's own URL, as before.
2. **Direct to the origin server**: the same URL, host name and HTTPS certificate check, but the connection goes straight to the server's address, past whatever sits in front of it. It tries the `loopback_origin` setting first, then `127.0.0.1`, then the server's own address. This needs PHP's cURL, which almost every host has.

`prerequisites.loopback` says whether a route works (`healthy`, `transport`), which one (`route`: `public` or `direct`, and `origin_address`), every attempt with its result (`attempts`), and a one-sentence `reason`. When nothing works, the reason names each failure, for example a 403 from the firewall on the public route and a 401 on the direct one.

- If the origin server is not on `127.0.0.1` (a load balancer or separate web server), ask the host for its address and set it with `update-settings` `loopback_origin`, for example `10.0.0.5` or `10.0.0.5:8080`. Pass `""` to clear it.
- If the site is behind HTTP basic auth, store the user name and password with `update-settings` `loopback_credentials: {user, password}`, or remove them with `{clear: true}`. They are sent only on requests to this site, never returned by any ability (`get-settings` reports only whether they are set), and deleted when the plugin is uninstalled. The cache check sends them too, but always takes the public route, because it measures the path visitors take.

Then call `get-capabilities` again. The route is chosen again at the start of every run, so a site whose block is lifted goes back to the public route by itself.

Compare like with like and report measured changes, not generic performance claims. [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) explains how to read what comes back.

Traffic collection is a separate observational workflow. Start it for an explicit duration, poll status, stop it when appropriate and read the observations. Explain retention and privacy choices before submitting any result.

## Real checkout safety

`run-checkout-flow` can create a real order and exercise order handling. The safe MCP workflow is:

1. call it with `dry_run: true` and show the pre-flight inventory;
2. review the product, mail, integrations and webhooks with the owner;
3. for a real run, pass a genuine boolean `confirm: true`;
4. enable `allow_integrations` or `allow_webhooks` only when those side effects are required for the measurement;
5. poll status and read `get-checkout-flow`, including the cleanup report.

Checkout mail goes to the site administrator and send/queue time is measured; `allow_integrations` and `allow_webhooks` default to false. Guidance alone is not the safety gate: a non-dry run without `confirm: true` is rejected with `sspa_confirm_required`.

## Permissions

Reading reports requires `sspa_manage`. Starting or stopping work, submitting results and running checkout require `sspa_execute`. Administrators receive both implicitly. The shared agent receives execute access only when it is selected on MCP setup.

## Outside MCP

- Server-level changes, such as installing Excimer or changing PHP-FPM, OPcache or MySQL settings, need your host or SSH. `get-capabilities` returns the exact install steps to pass on.
- Turning sharing with superspeedy.org on or off stays on the Share tab: it is the site owner's decision.
- The cache check's browser timing needs a browser; started over MCP it is recorded as not measured.

## Troubleshooting

- If an execute ability is denied, enable execute access for the dedicated agent.
- If a run is active, call `advance-run` until it finishes rather than starting another.
- If an analysis is refused with `sspa_loopback_blocked`, read `prerequisites.loopback.reason` from `get-capabilities` and see [Sites that block their own requests](#sites-that-block-their-own-requests).
- On a basic-auth site, logged-out REST endpoints answer 401 to profiling, as they do to a visitor's browser there: WordPress reads the basic-auth user name as an Application Password login attempt. Logged-in requests, including editor saves, are unaffected.
- If checkout is refused, run the pre-flight and pass `confirm: true`; live side effects remain off unless explicitly enabled.
- If a plugin impact run is too expensive, scope suspects/pages and disable cache-mode expansion when the comparison does not need it.

:::related{slugs="super-speedy-performance-analysis,super-speedy-archives,scalability-pro"}
:::

## Further reading

- [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) - interpreting what the abilities return
- [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) - how a run produces its numbers

As of 0.43.16 the ability namespace and category are `sspa`; clients using the old namespace must update their ability names.
