# Super Speedy Performance Hub (superspeedy.org companion plugin) - Brainstorm

Status: brainstorm v1. 2026-07-11.
Client-side counterpart: `brainstorm-performance-analysis.md` (the free analysis plugin).

## 1. What it is

A WordPress plugin that runs on superspeedy.org and is the server side of the community
performance database. Three jobs:

1. **Receive**: ingest anonymised submissions from Super Speedy Performance Analysis installs.
2. **Serve**: the rules feed, the alternatives/recommendations API, and (later) the SMTP sink
   config - everything the analysis plugin pulls down.
3. **Show**: public pages - per-plugin performance profiles with charts, category comparisons,
   sector benchmarks, methodology, right of reply.

Development plan: build it as a normal plugin on this same localhost first (the analysis
plugin points its API base at the local site), move it to superspeedy.org when ready. The API
contract is identical either way. Working name: `super-speedy-performance-hub`, prefix
`ssph_`.

## 2. Transport: fast-ajax-style mu-plugin REST override

The hot endpoints must not boot full WordPress - practising what we preach, and superspeedy.org
will take sustained submission traffic if this works. Follow Dave's proven fast-ajax pattern
(reference: `mu-plugins/sss-fast-ajax.php` on the local install):

- An mu-plugin inspects `REQUEST_URI` at load time; if the path doesn't match
  `/wp-json/ssph/v1/...` it returns immediately (zero cost for normal traffic).
- If it matches a **hot route**, it handles the request right there with direct `$wpdb`,
  emits JSON, and `die()`s before themes/plugins load. Option-gated (`ssph_mu_enabled`) so it
  can be switched off to fall back to normal REST - same safety valve as the fast-ajax
  plugins.
- The same routes are ALSO registered as normal WP REST endpoints (shared handler classes
  required from both contexts), so everything works without the mu-plugin - it's an
  accelerator, not a dependency. This mirrors how sss/ssc/sse fast-ajax behave and keeps
  local dev simple.

Hot routes (mu-accelerated):

- `POST /ssph/v1/submissions` - the ingest firehose.
- `GET /ssph/v1/rules` - static-ish JSON, aggressively cacheable (also behind CDN).
- `GET /ssph/v1/alternatives` - read-only lookup against rollup tables.
- `GET /ssph/v1/plugin/<slug>/stats` - powers both the public charts and third parties later.

Cold routes (normal WP REST/admin): moderation, classifier review, rule editing.

## 3. Data model (custom tables, `ssph_`)

Ingest side:

- `ssph_installs`: install_uuid, first_seen, last_seen, domain_hash (salted, dedupe only),
  sector, size_bucket, wp/php/mysql versions, reputation score (see anti-abuse).
- `ssph_submissions`: id, install_uuid, schema_version, received_at, status
  (quarantined | accepted | rejected), raw payload (compressed, retained ~90 days for
  reprocessing when aggregation logic improves, then pruned).
- `ssph_plugin_impacts`: install_uuid, plugin_slug, plugin_version, page_type, method
  (single_out | bisect | cache_toggle), delta_ttfb_ms, delta_sql_ms, delta_mem_bytes,
  delta_queries, noise_floor_ms, confidence. The atomic unit of evidence.
- `ssph_component_observations`: passive (non-isolation) per-component stats - query counts,
  sql_ms, rows, mail_ms per page_type per install. Weaker evidence, vastly bigger sample.
- `ssph_query_fingerprints`: fingerprint_hash, normalised_sql, plugin_slug, plugin_version
  range, avg/percentile time, avg rows, observation count. **The receipts.** This is the
  evidence base for "please fix your shit" articles - normalised SQL proves the bad design
  (the joins, ORDER BY rand(), postmeta scans) with zero customer values in it.

Aggregation side (what public pages and APIs actually read - never aggregate live):

- `ssph_plugin_rollups`: plugin_slug, version_bucket, page_type, metric, p50/p75/p95, sample
  count, distinct installs, updated_at. Rebuilt by cron from impacts + observations.
- `ssph_cache_effectiveness`: plugin_slug, queries_saved_pct with object cache, sample count -
  the "who uses Redis well" leaderboard.
- `ssph_sector_benchmarks`: sector, size_bucket, page_type, metric percentiles.

Catalogue side:

- `ssph_plugins`: slug, name, source (wp.org | github | commercial), group_id, feature list
  (JSON), wp.org metadata (installs, rating), classifier status
  (auto | human_verified | disputed), right_of_reply_url.
- `ssph_plugin_groups`: id, name (caching, SEO, security, search, filtering, page builder,
  forms, coupons, multilingual, ...), description. Seeded by hand, extended by the classifier.
- `ssph_rules`: the editable source of the rules feed - whitelist/blacklist/fragile entries,
  dependency map, sector signatures, security-plugin whitelisting instructions,
  recommendation texts, each row versioned with author + review status.

## 4. Ingest pipeline

1. **Validate**: schema version known, payload size caps, field-level sanity (no negative
   times, deltas within physical possibility, plugin slugs syntactically valid).
2. **Quarantine by default**: new installs' submissions sit unaggregated until the install has
   a few consistent submissions (reputation). Cheap poisoning defence.
3. **Aggregate on cron**: fold accepted submissions into rollups using medians/trimmed means,
   never raw means; require N distinct installs before a plugin's numbers go public
   (N=10 to start); outlier rejection (a delta > p99 of its cohort gets flagged, not
   averaged in).
4. **Prune**: raw payloads after ~90 days; rollups forever.

**Anti-abuse** (anonymous submissions = poisoning risk; a competitor could submit fake "plugin
X is slow" data):

- Install UUID + submission signing (per-install secret issued on first contact) so one actor
  can't trivially pretend to be 500 installs; rate limits per UUID and per IP.
- Reputation: installs whose submissions repeatedly disagree with cohort consensus get
  down-weighted, not banned (could be a genuinely weird host).
- Public numbers always show sample size + distinct-install count + distribution, so a
  poisoned tail is visible rather than silently moving a headline number.
- Everything framed as measurements with methodology linked, never verdicts.

## 5. Plugin classification + the alternatives engine (Dave's #4)

Goal: "Plugin X costs you 480ms. Here are the plugins in the same group that measure faster
AND cover the features you actually use."

- **LLM classifier** (runs on superspeedy.org, cron/queue): when a new plugin slug appears in
  submissions, fetch its wp.org readme / GitHub README, then classify: group (one of
  `ssph_plugin_groups`), and an extracted **feature list** (structured: feature key + short
  label, e.g. `coupon-bogo`, `url-based-affiliate-tracking`). Claude Haiku-class model is
  plenty; batched; results land as `classifier status = auto` until a human glances at them
  in a review queue. Misclassification here is embarrassing (comparing a coupon plugin to a
  slider), so groups go live only after human verification; features can stay auto with a
  "community-reported" badge.
- **Feature usage detection** (client side, opt-in - designed in the main doc, 3.7): the
  analysis plugin submits which features of a plugin the site actually has enabled (bucketed
  settings snapshot). The classifier maintains the mapping from settings keys -> feature keys,
  improving as submissions arrive. Fallback when no snapshot: user ticks the features they
  need in the UI on superspeedy.org or in the plugin.
- **Alternatives API**: `GET /alternatives?plugin=X&features=a,b,c` returns group members
  whose feature list covers {a,b,c}, ranked by measured cost (rollups), each with sample size
  and confidence. Dave's plugins win where they genuinely measure faster - which is the whole
  point: it comes out in the wash, credibly.
- **Charts on the public pages**: per-plugin page shows its cost distribution per page type,
  version trend, and a group comparison chart (this plugin vs its competitors, p50 bars with
  sample sizes). Follow the dataviz skill when building these.

## 6. Public pages (the SEO flywheel)

- `/plugin-performance/<slug>/` - per-plugin profile: headline p50 cost per page type, charts,
  cache effectiveness, worst query fingerprints (the receipts, pretty-printed), version trend,
  alternatives box, right-of-reply section, sample sizes everywhere.
- `/plugin-performance/category/<group>/` - the comparison table + chart for a whole group
  ("fastest WooCommerce coupon plugins", "fastest multilingual plugins"). These pages are the
  organic-search catnip ("is <plugin> slow", "<plugin> alternatives").
- `/benchmarks/<sector>/` - sector benchmark pages ("how fast should a job board be?").
- `/methodology/` - exactly how numbers are gathered (median-of-3, noise gates, isolation
  technique), links to the open-source analysis plugin. Credibility page; also the
  right-of-reply process lives here.
- Site-lookup page: an install UUID lets a user view their own site's submitted history.
- All rendered from rollup tables only; page-cache friendly; zero live aggregation.

## 7. Email infrastructure (parked until analysis plugin phase 3+)

- **SMTP sink** `sink.superspeedy.org`: accepts any RCPT, discards DATA, logs
  connect/accept timings keyed by the GUID in the recipient
  (`order-processed-analysis+<guid>@sink.superspeedy.org`). Not WordPress - a tiny Postfix
  discard transport or Haraka instance. The hub plugin only stores the timing rows the sink
  reports (private API between sink and hub).
- **Receiver bucket** (deliverability verification): only for synthetic test emails, store
  headers + DKIM/SPF verdicts, discard bodies on arrival. Explicitly NOT for real customer
  mail; the analysis plugin's forced recipient-override guarantees profiled sends only ever
  target the sink. Park until there's demand.

## 8. Admin/moderation UI (normal wp-admin, JS tab pattern as always)

- Submission monitor: volume, quarantine queue, rejection reasons.
- Classifier review queue: approve/correct groups + features (approve-all-obvious flow).
- Rules editor: whitelist/blacklist/fragile/dependencies/signatures/recommendation texts with
  versioning and a "publish feed" action (regenerates + signs rules.json).
- Disputes: mark a plugin as disputed, attach right-of-reply text, optionally suppress
  headline numbers pending review.

## 9. Rules feed publishing

- Editable rows in `ssph_rules` -> "publish" compiles to a single `rules.json`, bumps the
  feed version, **signs it** (private key on superspeedy.org, public key shipped in the
  analysis plugin - a spoofed feed that tells sites to disable security plugins is a real
  attack vector), and pushes it to a static path/CDN. The GET route just serves the static
  file.
- The same compiled feed is committed to the analysis plugin's GitHub repo periodically as the
  bundled offline snapshot - and GitHub PRs against the rules data files can be imported back
  into `ssph_rules` (community contribution loop).

## 10. Open questions

1. Does the hub need accounts for plugin authors (right of reply, claiming a plugin page), or
   is email-based verification enough to start? (Lean: email verification, no accounts.)
2. Charting: server-rendered SVG (cacheable, no JS) vs Chart.js on the public pages?
   (Lean: server-rendered for SEO pages, JS charts in wp-admin only.)
3. Which LLM provider/model for the classifier, and budget cap per month? Volume is tiny
   (new plugin slugs only), so likely pennies.
4. Retention/GDPR statement: submissions are anonymous by design, but write the data policy
   page before launch, not after.
5. Reuse the `sspa` schema JSON for submissions verbatim, or define a separate wire schema?
   (Lean: shared versioned schema package, one source of truth in the analysis repo.)
