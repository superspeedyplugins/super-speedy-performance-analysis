# Agents, CLI and the report API

### WP-CLI
**Since:** 0.7.0, 11 July 2026

`wp sspa` with five subcommands, all verified present in `includes/cli/class-sspa-cli.php`:
`run` (synchronous with progress, all run types), `status`, `findings`, `impacts` and `report`.
JSON output throughout. `wp sspa run --type=deep` allows 6 hours.

### WordPress Abilities API
**Since:** 0.7.0, 11 July 2026

Category `super-speedy-performance`, with eight abilities verified in the code: `get-status`,
`get-report`, `get-findings`, `get-plugin-impacts`, `get-site-metrics`, `run-analysis`,
`run-deep-analysis` and `submit-results`. Read-only abilities answer plain GETs on the core
abilities REST controller. With the MCP Adapter plugin installed the same abilities appear as
MCP tools automatically. Requires WordPress 6.9+.

### Agent-ready report JSON
**Since:** 0.7.0, 11 July 2026

A stable documented schema (`.docs/agent-api.md`) with plain-English headlines and explicit
recommendation objects per finding, built for LLM consumption.

### Documented delta sign convention
**Since:** 0.8.0, 24 July 2026

Positive means the plugin adds time, negative means it saves time, stated explicitly in the API
docs so an assistant cannot report a saving as a slowdown.

### Guard rails for agents
**Since:** 0.7.0, 11 July 2026

All abilities require `manage_options`. Submissions still require the site owner's opt-in on
the Share tab. `run-analysis` without `include_writes` performs GET requests only.

### Agent instructions shipped in the repo
**Since:** 0.7.0, 11 July 2026

`SKILL.md` for Claude agents plus OpenAI-equivalent instructions, and knowledge-base articles
covering getting started, understanding results, security whitelisting and methodology.
