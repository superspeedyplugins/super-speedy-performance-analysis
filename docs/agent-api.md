# Agent API - report schema and surfaces

Schema version: 1 (SSPA_Report::SCHEMA). Bump on breaking changes and note here.

## Surfaces (identical data)

- **WP-CLI**: `wp sspa run|checkout-flow|status|findings|impacts|cache-scan|report` (report/findings/impacts
  take `--format=json` or emit JSON directly).
- **Abilities API** (WP 6.9+): category `super-speedy-performance`, abilities
  `get-status`, `get-report`, `get-cache-safety-report`, `get-findings`, `get-plugin-impacts`, `get-site-metrics`,
  `get-archive-profile`, `run-analysis`, `run-deep-analysis`, `run-checkout-flow`,
  `get-checkout-flow`, `submit-results`. Readonly ones answer GET at
  `/wp-json/wp-abilities/v1/abilities/super-speedy-performance/<name>/run`.
- **MCP**: when the MCP Adapter plugin is installed, a server is registered at
  `/wp-json/mcp/super-speedy-performance` exposing the same abilities as tools
  (dash-joined names, e.g. `super-speedy-performance-get-report`).

Runs started via abilities are asynchronous: poll `get-status` until `active` is false.
`wp sspa run` is synchronous and drives the batches itself.

## Report object (get-report / wp sspa report)

```json
{
  "schema": 1,
  "generated_at": "2026-07-11T12:00:00+00:00",
  "run": {"id": 12, "type": "baseline", "status": "done", "started": "...", "finished": "..."},
  "score": 76,
  "site": {"sector": "e-commerce", "wp": "7.0.1", "php": "8.3.x",
            "object_cache": true, "active_plugins": ["woocommerce", "..."], "theme": "..."},
  "summary": {"pages_profiled": 27, "findings": {"critical": 2, "warn": 5, "info": 1}},
  "insights": [ /* first 10 findings, most severe first */ ],
  "findings": [ /* all findings, shape below */ ],
  "cache_safety": {"headline": "...", "detail": "...", "assessment": {"shared_cache_status": "visitor_specific_content_review_recommended", "difficulty": "moderate", "hazards": [], "candidate_components": []}},
  "pages": [ /* per-page medians, shape below */ ],
  "impacts": [ /* measured isolation deltas, shape below */ ]
}
```

### Finding

```json
{
  "type": "slow_query | big_result_set | query_loop | dupe_queries | slow_http |
           blocking_mail | cache_blind | cache_friendly | autoload_bloat | environment |
           duplicate_functionality | security_block | cache_safety",
  "severity": "critical | warn | info",
  "component": "plugin-slug | theme-slug | core | null (site-level)",
  "page_key": "home | shop | ... | null",
  "confidence": "inferred | measured",
  "headline": "plain-English one-liner naming the culprit",
  "detail": "supporting detail (often the offending SQL)",
  "evidence": { "ms": 210.5, "rows": 3400, "shape": "postmeta", "...": "type-specific" },
  "recommendation": {"key": "slow_query_postmeta", "title": "...", "body": "...", "link": "https://... or empty"}
}
```

### Shared-cache safety report

`get-cache-safety-report`, `wp sspa cache-scan` and the report's
`cache_safety` member expose the same local-only assessment. It is produced from
one anonymous response per shared-cache page candidate plus a bounded active-source scan.
`shared_cache_status` is `visitor_specific_content_review_recommended` or
`no_visitor_specific_content_hazards_detected`; `difficulty` is `low`, `moderate` or `high`.

The assessment includes potential `hazards`, existing Type A/Type B/fragment `coverage`,
per-page application and infrastructure cookie names, nonce contexts, and ranked
`candidate_components` with review priority, observed page keys and component-relative file/line
evidence. It never includes HTML, cookie values, nonce values or customer text. Treat
candidate components as the place to inspect first, not as proven owners. The Overview tab
can download the same evidence as a versioned `sspa/shared-cache-safety-report@2` JSON report.
Repeated site-wide signals are scored once, while their affected page count remains evidence.
The `source_scan` block states component coverage and every component limited by the fair
per-component scan ceiling. A controlled
guest/customer/basket comparison is still required before enabling shared caching.

### Page

`page_key`, `variant` (anon|customer|admin), `generation_ms`, `ttfb_ms`, `sql_ms`,
`sql_count`, `rows_fetched`, `http_ms`, `php_ms`, `peak_mem_bytes`, `duplicate_queries`,
`mail_count`, `response_code`, `blocked_by` (security layer name or null). Special keys:
`baseline` (server noise floor), `mail-probe` (mail construction cost), `write-*` (opt-in
write cascades).

### Impact (deep analysis)

Deep analysis is a two-phase sweep: every eligible plugin is screened on its busiest
pages, and plugins showing measurable impact are then measured on every page plus extra
cache modes. Expect one impact row per plugin per measured page per cache mode - many
rows for impactful plugins, a few screening rows for innocent ones.

`plugin`, `page_key`, `method` (single_out; bisect appears only in pre-0.8 data),
`object_cache_mode` (`normal` = the cache in its natural warmed state - the steady-state
number to headline; `disabled` and `prime` appear for impacted plugins on sites with a
persistent object cache; `warm` only in pre-0.9.1 data), `delta_generation_ms`,
`delta_sql_ms`, `delta_http_ms`, `delta_mem_bytes`, `delta_queries`, `noise_floor_ms`,
`confidence` (measured = proven; none = |delta| below noise floor), `measured_at`.

When summarising a plugin, aggregate its `normal` rows: net delta across pages, plus the
single biggest cost or saving. A plugin whose `disabled` deltas are much worse than its
`normal` deltas depends heavily on the object cache.

**Sign convention (all `delta_*` fields):** baseline (all plugins active) minus the
measurement with the plugin virtually excluded. **Positive = the plugin ADDS that much**
(cost). **Negative = the plugin SAVES that much** - the page got slower without it, i.e.
the plugin is speeding the site up (common for search/filter replacement plugins). Never
describe a negative delta as the plugin being slow.

## Archive profile object (get-archive-profile)

Separate from the report and additive to it, so `SSPA_Report::SCHEMA` does not move. It has its
own `schema` (currently 1, `SSPA_Archive_Profile::SCHEMA`).

What it answers: which composite database indexes this site's archive pages would actually use,
and which columns have to exist before those indexes can be built. Intended for auto-configuring
Super Speedy Archives.

**The full field-by-field contract is a single document: `.kb/archive-query-profile.md`**, also
published at https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/ - build
against that, not against this summary. It is deliberately the only place the shape is
specified, so there is nothing to drift out of sync.

## Guarantees agents can rely on

- Page keys are stable across runs - compare runs by page_key.
- `confidence: measured` numbers came from re-measurement with a verified plugin-set
  canary; deltas whose absolute value is below `noise_floor_ms` are never reported as
  measured.
- Single-out impacts compare against a baseline re-measured seconds before the excluded
  measurement (drift control), and the excluded plugin set gets its own warm-up request.
- Profiled requests never send mail and never change the live plugin set.
- `run-analysis` without `include_writes` performs GET requests only.
- An archive profile never claims a query is unindexed without a query plan to say so. A
  `mixed` or `low` type confidence must not be auto-applied - either choice is wrong for part
  of the data. `complete: false` means insufficient evidence, never "nothing needed": a run
  whose CPT archive timed out has proved nothing about that archive.
