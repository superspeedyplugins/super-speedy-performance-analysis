# Features - Super Speedy Performance Analysis

Compiled 2026-07-28, refreshed 2026-08-02 against 0.9.2 (git c9d785a, working tree clean apart
from these folders). What the plugin factually does today, verified against the code, not the
changelog.

**This plugin is free.** There is no licence gate anywhere in `includes/` and no edition split,
so nothing here is marked Pro. Updates come from superspeedyplugins.com (since 0.9.2), ungated,
no key required.

The plugin is at 0.9.x and **not yet published** - the download does not exist on the website
yet, and neither does the update JSON.

| Doc | Covers |
|---|---|
| `profiling.md` | How it measures: the drop-in, sampling discipline, attribution, what it captures |
| `analysis.md` | Turning captures into plain-English findings, query plans, scoring, the tabs |
| `deep-analysis.md` | Culprit isolation, virtual disabling, cache modes, the noise gate |
| `server-tools.md` | The Tools tab: capability detection and generated install steps |
| `agents-and-cli.md` | WP-CLI, the Abilities API surface, the agent report JSON |
| `community.md` | Opt-in anonymised sharing, the signed rules feed, the hub |

## Re-run map

| Changed path | Re-verify |
|---|---|
| `profiler/`, `dropins/`, `mu/` | `profiling.md` |
| `profiler/class-sspa-component-map.php`, `includes/class-sspa-attribution.php` | `profiling.md` (attribution), `analysis.md` (the mode switch) |
| `includes/class-sspa-analysis-engine.php`, `class-sspa-explain.php`, `class-sspa-digests.php`, `rules/rules-snapshot.json` | `analysis.md` |
| `includes/admin/tabs/`, `includes/admin/class-sspa-insights.php` | `analysis.md`, plus `server-tools.md` for `tools.php` |
| `includes/class-sspa-tools.php` | `server-tools.md` and `.compatibility/` |
| `includes/class-sspa-run-controller.php`, `class-sspa-probes.php` | `deep-analysis.md` |
| `includes/cli/`, `includes/class-sspa-abilities.php` | `agents-and-cli.md` |
| `includes/class-sspa-submitter.php`, `class-sspa-rules-feed.php`, `hub/` | `community.md` |
| `.docs/implementation-plan-profilers-and-digests.md` | `.roadmap/planned.md` |
