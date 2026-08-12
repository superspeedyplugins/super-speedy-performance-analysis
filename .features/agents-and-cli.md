# Agents, CLI and the report API

### WP-CLI
**Since:** 0.7.0, 11 July 2026 (`checkout-flow` added 0.11.0)

`wp sspa` with six subcommands, all verified present in `includes/cli/class-sspa-cli.php`:
`run` (synchronous with progress, all run types), `checkout-flow`, `status`, `findings`,
`impacts` and `report`. JSON output throughout. `wp sspa run --type=deep` allows 6 hours.

Options added since:

- `--pages=` on `--type=deep` (0.12.0) - re-measure one plugin on one page instead of sweeping
  every page: `wp sspa run --type=deep --suspects=my-plugin --pages=admin-orders-search`
- `--url=` and `--no-cache-modes` on `--type=deep` (0.14.0)
- `--dry-run` on `checkout-flow` (0.11.0) - prints the pre-flight without buying anything

### WordPress Abilities API
**Since:** 0.7.0, 11 July 2026

Category `super-speedy-performance`, with **ten** abilities verified in the code: `get-status`,
`get-report`, `get-findings`, `get-plugin-impacts`, `get-site-metrics`, `run-analysis`,
`run-deep-analysis`, `run-checkout-flow`, `get-checkout-flow` and `submit-results`. The last two
of the checkout pair arrived in 0.11.0.

Read-only abilities answer plain GETs on the core abilities REST controller. Requires WordPress
6.9+; the bootstrap gates on `function_exists('wp_register_ability')`, so on older WordPress they
simply do not register.

`run-deep-analysis` accepts `url`, `pages` and `cache_modes` (0.14.0), matching the CLI.

### MCP tools, discoverable
**Since:** 0.7.0, 11 July 2026 (`meta.mcp.public` since 0.17.3, 11 August 2026)

With the MCP Adapter plugin installed, the same abilities appear as MCP tools automatically at
`/wp-json/mcp/super-speedy-performance`, dash-joined (e.g. `super-speedy-performance-get-report`).
Since 0.17.3 every ability is flagged `meta.mcp.public = true` so agent tooling can discover
them rather than needing them named up front.

### Agent-ready report JSON
**Since:** 0.7.0, 11 July 2026

A stable documented schema (`.docs/agent-api.md`, `SSPA_Report::SCHEMA` version 1) with
plain-English headlines and explicit recommendation objects per finding, built for LLM
consumption.

<!-- internal -->
`.docs/agent-api.md` is stale in one respect: it still lists eight abilities and omits
`run-checkout-flow` and `get-checkout-flow`. The doc is the published contract for agent
builders, so this is worth a five-minute fix.

### Documented delta sign convention
**Since:** 0.8.0, 24 July 2026

Positive means the plugin adds time, negative means it saves time, stated explicitly in the API
docs so an assistant cannot report a saving as a slowdown.

### Guard rails for agents
**Since:** 0.7.0, 11 July 2026

All abilities require `manage_options`. Submissions still require the site owner's opt-in on the
Share tab. `run-analysis` without `include_writes` performs GET requests only. A checkout flow
started by an agent is subject to the same disclosure and consent as one started from the admin
screen.

### Agent instructions shipped in the repo
**Since:** 0.7.0, 11 July 2026

`SKILL.md` for Claude agents plus OpenAI-equivalent instructions
(`.docs/agent-instructions-openai.md`), and knowledge-base articles covering getting started,
understanding results, security whitelisting, geolocation speed boosts, function-level profiling
and methodology.
