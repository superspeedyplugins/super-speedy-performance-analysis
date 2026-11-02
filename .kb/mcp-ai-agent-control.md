# Controlling Super Speedy Performance Analysis with MCP

Connect Super Speedy Performance Analysis to Codex or Claude Code to run measurements, monitor progress, interpret findings and plugin impact, collect real traffic observations and safely profile a checkout workflow.

*Requires WordPress 6.9 or newer, Super Speedy Performance Analysis 0.58.2 or newer for the full set below (0.23.1 for the original 21), and Node.js 18 or newer on the AI-client computer. Checkout profiling also requires WooCommerce.*

## Connect once for all Super Speedy plugins

Open **Super Speedy > MCP setup**, choose execute access only if the assistant may start analyses, traffic collection, submissions or checkout runs, generate the command, paste it into your terminal and restart the client. The setup page creates or reuses a dedicated **Super Speedy Agent** user and a revocable Application Password. No Performance-specific bridge or endpoint is needed.

## The abilities

The namespace is `sspa`. Since 0.58.2 everything the Performance Analysis screens do is an ability, so an assistant with only the MCP connection can run the whole tool. Start with `get-capabilities`: it lists every workflow and its abilities, whether the profiler is on, which optional instruments (such as Excimer) are installed and how to install the missing ones, whether WP-Cron runs, and the page keys `run-analysis` accepts.

### Read and interpret

- Discovery and runs: `get-capabilities`, `get-status`, `list-runs`, `get-run`
- Results: `get-report`, `get-findings`, `list-page-profiles`, `get-page-profile` (the full page analysis panel), `get-plugins-table`, `get-plugin-impacts`, `get-plugin-impact-detail`, `get-impact-plan`, `get-page-plugin-usage`, `get-http-calls`, `get-archive-profile`, `get-site-metrics`
- Caching: `get-cache-optimisation-analysis`, `get-cache-safety-report`
- History and reports: `compare-history`, `get-history-series`, `get-markdown-report`
- Checkout, AJAX and traffic: `get-checkout-flow`, `get-endpoint-profiles`, `get-admin-save-targets`, `get-traffic-collection-status`, `get-traffic-observations`, `compare-traffic-collections`
- Settings and sharing: `get-settings`, `get-submission-queue`, `get-payload-preview`, `get-community-figures` (downloads public figures from superspeedy.org)

### Run and change

- Measure: `run-analysis`, `run-deep-analysis`, `profile-url`, `run-checkout-flow`, `run-cache-check`, `profile-endpoint`, `start-ajax-window`, `stop-ajax-window`, `compare-ajax-windows`, `start-traffic-collection`, `stop-traffic-collection`
- Keep it moving: `advance-run`, `advance-cache-check`, `cancel-run`
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
6. For page caching, `run-cache-check`, then `advance-cache-check` until done, then `get-cache-optimisation-analysis`. Over MCP the browser's own timing is not measured; every server-side request is.

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
- If checkout is refused, run the pre-flight and pass `confirm: true`; live side effects remain off unless explicitly enabled.
- If a plugin impact run is too expensive, scope suspects/pages and disable cache-mode expansion when the comparison does not need it.

:::related{slugs="super-speedy-performance-analysis,super-speedy-archives,scalability-pro"}
:::

## Further reading

- [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) - interpreting what the abilities return
- [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) - how a run produces its numbers

As of 0.43.16 the ability namespace and category are `sspa`; clients using the old namespace must update their ability names.
