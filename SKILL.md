---
name: wordpress-performance-analysis
description: Analyse a WordPress/WooCommerce site's performance using the free Super Speedy Performance Analysis plugin - profile key pages, attribute SQL/RAM/time to individual plugins, isolate culprits by virtually disabling plugins for test requests only, and explain the findings in plain English. Use when someone asks why their WordPress site is slow, which plugin is slowing it down, or wants a performance audit.
---

# WordPress performance analysis

You are driving Super Speedy Performance Analysis - a free diagnostic plugin
(https://www.superspeedyplugins.com/). It profiles the
site's key pages via signed loopback requests, attributes SQL time / row counts / RAM /
query counts to individual plugins and the theme, and can PROVE a plugin's cost by
re-measuring with it virtually disabled (test requests only - visitors are never affected,
nothing is ever really deactivated).

## 1. Install (skip if already active)

With wp-cli access:

```bash
wp plugin install https://www.superspeedyplugins.com/assets/plugins/super-speedy-performance-analysis.zip --activate
```

Or download that zip and upload via Plugins -> Add New. Verify: `wp plugin is-active
super-speedy-performance-analysis`.

## 2. Run the analysis

Preferred (wp-cli - synchronous, shows progress):

```bash
wp sspa run            # full baseline: every key page, read-only, non-destructive
wp sspa report         # full JSON report of the latest run
```

Via MCP/Abilities (when connected to the site's MCP server or the core abilities REST
controller): call `run-analysis` (async), poll `get-status` until `active` is false, then
`get-report`. Readonly abilities also answer plain GETs at
`/wp-json/wp-abilities/v1/abilities/super-speedy-performance/<name>/run`.

The first run is read-only: it sends roughly four GET requests per page profiled and
changes nothing. Expect 1-5 minutes on a typical site.

## 3. Interpret the report

The report JSON (`wp sspa report`, or the `get-report` ability - schema in
`.docs/agent-api.md`) contains:

- `score` - 0-100; below 80 means real findings exist.
- `insights` - the top findings, each with a plain-English `headline`, `evidence` and an
  explicit `recommendation` object. Lead your summary with these.
- `pages` - per-page medians: `generation_ms`, `sql_ms`, `sql_count`, `rows_fetched`,
  `peak_mem_bytes`. Pages over ~500ms generation deserve attention; `rows_fetched` in the
  thousands explains high RAM.
- `findings` - the full list. Key types: `slow_query` (with a `shape` - postmeta scans,
  ORDER BY rand() etc), `big_result_set` (RAM culprits), `query_loop` (N+1 - grows with
  content), `dupe_queries`, `slow_http` (blocking remote calls), `cache_blind` (ignores
  Redis), `blocking_mail`, `security_block` (profiling was blocked - relay the whitelist
  advice).
- `impacts` - only present after deep analysis: PROVEN per-plugin costs with a noise floor.

When summarising for the user: name the responsible plugin, quote the measured numbers,
and include the recommendation. Do not soften "confidence: measured" findings - they were
proven by re-measurement. Treat `confidence: inferred` as attribution, not proof, and
offer deep analysis to confirm.

## 4. Offer deep analysis (culprit isolation)

If findings name suspect plugins, offer to prove their real cost:

```bash
wp sspa run --type=deep                       # all suspects + bisection of the slowest page
wp sspa run --type=deep --suspects=some-slug  # one plugin only
wp sspa impacts                               # the measured results
```

Explain honestly: suspect plugins are disabled FOR THE PLUGIN'S OWN TEST REQUESTS ONLY via
a per-request filter; real visitors always get the full site; a few dozen extra requests
hit the site while it runs.

If the site has Redis/Memcached, `wp sspa run --type=cache_impact` additionally reveals
which plugins ignore the object cache.

## 5. Cautions

- Do not enable community sharing yourself - only the site owner may opt in (Share tab).
- If pages report `blocked_by`, a security plugin blocked the loopbacks: relay the
  whitelisting advice from the finding, then re-run.
- On measurement noise: deltas below the reported `noise_floor_ms` are reported as "no
  measurable impact" - trust that, do not re-interpret tiny deltas as findings.
