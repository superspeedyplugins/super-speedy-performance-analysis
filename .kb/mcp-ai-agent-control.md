# Controlling Super Speedy Performance Analysis with MCP

Connect Super Speedy Performance Analysis to Codex or Claude Code to run measurements, monitor progress, interpret findings and plugin impact, collect real traffic observations and safely profile a checkout workflow.

*Requires WordPress 6.9 or newer, Super Speedy Performance Analysis 0.23.1 or newer, and Node.js 18 or newer on the AI-client computer. Checkout profiling also requires WooCommerce.*

## Connect once for all Super Speedy plugins

Open **Super Speedy > MCP setup**, choose execute access only if the assistant may start analyses, traffic collection, submissions or checkout runs, generate the command, paste it into your terminal and restart the client. The setup page creates or reuses a dedicated **Super Speedy Agent** user and a revocable Application Password. No Performance-specific bridge or endpoint is needed.

## The 17 abilities

The namespace is `super-speedy-performance`.

### Read and interpret

- `get-status`
- `get-report`
- `get-archive-profile`
- `get-cache-optimisation-analysis`
- `get-cache-safety-report`
- `get-findings`
- `get-plugin-impacts`
- `get-site-metrics`
- `get-checkout-flow`
- `get-traffic-collection-status`
- `get-traffic-observations`

### Execute

- `run-analysis`
- `run-deep-analysis`
- `run-checkout-flow`
- `start-traffic-collection`
- `stop-traffic-collection`
- `submit-results`

## Recommended analysis workflow

Start a normal analysis, poll `get-status`, then read the full report, findings and site metrics. Use deep analysis only when baseline evidence identifies suspects or when you deliberately scope the run to selected plugins/pages. Compare like with like and report measured changes, not generic performance claims.

Traffic collection is a separate observational workflow. Start it for an explicit duration, poll status, stop it when appropriate and read the observations. Explain retention and privacy choices before submitting any result.

## Real checkout safety

`run-checkout-flow` can create a real order and exercise order handling. The safe MCP workflow is:

1. call it with `dry_run: true` and show the pre-flight inventory;
2. review the product, mail, integrations and webhooks with the owner;
3. for a real run, pass a genuine boolean `confirm: true`;
4. enable `allow_integrations`, `allow_webhooks` or `mail_mode: deliver` only when those live side effects are explicitly required for the measurement;
5. poll status and read `get-checkout-flow`, including the cleanup report.

The MCP defaults are `mail_mode: suppress`, `allow_integrations: false` and `allow_webhooks: false`. Guidance alone is not the safety gate: a non-dry run without `confirm: true` is rejected with `sspa_confirm_required`.

## Permissions

Reading reports requires `sspa_manage`. Starting or stopping work, submitting results and running checkout require `sspa_execute`. Administrators receive both implicitly. The shared agent receives execute access only when it is selected on MCP setup.

## Deliberately outside MCP

Stored-run retention and deletion are not exposed as abilities. Deleting measured evidence needs its own confirmed ability and a clear retention contract. Browser-driven transport experiments and external server telemetry stay in their own specialist workflows rather than being folded into the WordPress ability surface.

## Troubleshooting

- If an execute ability is denied, enable execute access for the dedicated agent.
- If a run is active, poll it rather than starting another.
- If checkout is refused, run the pre-flight and pass `confirm: true`; live side effects remain off unless explicitly enabled.
- If a deep run is too expensive, scope suspects/pages and disable cache-mode expansion when the comparison does not need it.

:::related{slugs="super-speedy-performance-analysis,super-speedy-archives,scalability-pro"}
:::
