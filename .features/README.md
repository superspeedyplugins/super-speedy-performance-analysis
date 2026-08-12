# Features - Super Speedy Performance Analysis

Compiled 2026-07-28, refreshed 2026-08-12 against **0.18.0** (git 70f5123, working tree clean).
What the plugin factually does today, verified against the code, not the changelog.

**This plugin is free.** There is no licence gate anywhere in `includes/` and no edition split,
so nothing here is marked Pro. Updates come from superspeedyplugins.com (restored in 0.10.11),
ungated, no key required. The public download is the GitHub Release asset.

**0.18.0 is released to customers** - verified 2026-08-12: the update JSON, the deployed
`readme.txt` and the GitHub Release all read 0.18.0, and the JSON's `download_url` is the
authorising endpoint. An installed site will be offered the update.

| Doc | Covers |
|---|---|
| `profiling.md` | How it measures: the drop-in, sampling discipline, attribution, phase decomposition, function-level profiling |
| `analysis.md` | Turning captures into plain-English findings, query plans, autoloaded options, the page panel, the tabs |
| `deep-analysis.md` | **Plugin Impact Analysis** - measured culprit isolation, dependency groups, reaction guards |
| `checkout-flow.md` | Checkout & order flow analysis: the customer's wait, and the shop owner's |
| `server-tools.md` | The Tools tab: capability detection and generated install steps |
| `agents-and-cli.md` | WP-CLI, the Abilities API/MCP surface, the agent report JSON |
| `community.md` | Opt-in anonymised sharing, the outbox, site cohorts, the signed rules feed |

## Naming, so copy stays consistent

- **Plugin Impact Analysis** is the current name (renamed from Deep Analysis in 0.13.1). The
  doc filename stays `deep-analysis.md` so the re-run map and downstream skills keep resolving;
  the user-facing name in any copy is Plugin Impact Analysis.
- **Site characteristics** is the user-facing term for what kind of site this is;
  **site cohort dimensions** is what superspeedy.org groups by. Never "demographics" in
  anything user-facing - `SSPA_Demographics` keeps the name internally only.

## Re-run map

| Changed path | Re-verify |
|---|---|
| `profiler/`, `dropins/`, `mu/` | `profiling.md` |
| `profiler/class-sspa-excimer.php` | `profiling.md` (function view), `server-tools.md`, `.compatibility/` |
| `profiler/class-sspa-component-map.php`, `includes/class-sspa-attribution.php` | `profiling.md` (attribution), `analysis.md` (the mode switch) |
| `profiler/class-sspa-boot-timer.php` | `profiling.md` (phase decomposition) |
| `includes/class-sspa-analysis-engine.php`, `class-sspa-explain.php`, `class-sspa-digests.php`, `rules/rules-snapshot.json` | `analysis.md` |
| `includes/admin/tabs/`, `includes/admin/class-sspa-insights.php` | `analysis.md`, plus `server-tools.md` for `tools.php` |
| `includes/admin/class-sspa-profile-panel.php`, `class-sspa-adhoc.php` | `analysis.md` (the page panel) |
| `includes/class-sspa-tools.php` | `server-tools.md` and `.compatibility/` |
| `includes/class-sspa-run-controller.php`, `class-sspa-probes.php`, `class-sspa-dependency-map.php` | `deep-analysis.md` |
| `includes/class-sspa-checkout-flow.php`, `class-sspa-checkout-preflight.php` | `checkout-flow.md`, `.compatibility/woocommerce.md` |
| `includes/cli/`, `includes/class-sspa-abilities.php` | `agents-and-cli.md` |
| `includes/community/`, `includes/class-sspa-submitter.php`, `class-sspa-anonymiser.php`, `class-sspa-site-characteristics.php`, `class-sspa-rules-feed.php` | `community.md` |
| `defines.php`, `includes/admin/tabs/settings.php` | `profiling.md` (measurement timeout), `checkout-flow.md` (checkout settings) |
| `.docs/` (any add, archive or delete) | `.roadmap/planned.md` - always |
