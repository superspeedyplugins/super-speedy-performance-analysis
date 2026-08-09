# Option access tracking: which wp_options are actually read

Status: **design, nothing built**, 2026-08-09.

Question this answers: can we record which options a request actually reads, and use that to tell
a site owner which autoloaded options are dead weight? Feasibility was measured on the local dev
site before any of this was designed, and the measurement changed the shape of the feature.

## 1. Feasibility: yes, and the hook is clean

WordPress 6.1 added a generic `pre_option` filter. `wp-includes/option.php:132-153`:

```php
$pre = apply_filters( "pre_option_{$option}", false, $option, $default_value );
$pre = apply_filters( 'pre_option', $pre, $option, $default_value );
if ( false !== $pre ) { return $pre; }
```

Three properties make it the right observation point:

- it fires on **every** `get_option()` call, before the alloptions/cache/DB lookup;
- it receives `$option`, so one `add_filter` covers all option names (unlike `pre_option_{$option}`,
  which needs the name up front);
- it fires **even when the per-option filter already short-circuited**, because the
  `false !== $pre` test comes after both. Nothing hides from it.

Observing is a no-op provided the callback returns `$pre` exactly as received. The plugin requires
WordPress 6.2, so the hook is always present. No polyfill, no `$wpdb` interception.

### Proven, not assumed

A throwaway mu-plugin hooking `pre_option` was installed on the dev site, five page types were
requested, and it was removed. Real numbers from that run:

| Page | Distinct options read | `get_option()` calls |
|---|---|---|
| `/` (home) | 232 | 1,974 |
| `/shop/` | 234 | 1,818 |
| `/product/<slug>/` | 227 | 1,433 |
| `/hello-world/` (post) | 217 | 1,485 |
| `/?s=sofa` (search) | 226 | 1,628 |
| **Union across all five** | **265** | |

So a single page sees roughly 85% of the union. Page-specific tracking is immediately useful;
the union is what makes a *recommendation* safe.

## 2. The measurement that changes the design

Same site, comparing the union against the actual autoload set
(`autoload IN ('yes','on','auto-on','auto')`, per `wp_autoload_values_to_autoload()`):

| | Options | Bytes |
|---|---|---|
| Autoloaded, total | 327 | 71 KB |
| Autoloaded **and** read somewhere | 172 | 66 KB |
| Autoloaded, **never read anywhere** | **155** | **5 KB** |
| Read but **not** autoloaded | 93 | |

Biggest never-read autoloaded rows: `swatchly_options` 3 KB, then `rank_math_known_post_types`
238 B, `wp-reset` 205 B, `woocommerce_registration_privacy_policy_text` 175 B.

**The naive feature would tell this user to change 155 rows to save 5 KB, and 3 KB of that is one
row.** The rest is the core settings long tail: `users_can_register`, `mailserver_url`,
`posts_per_rss`, `comments_notify`, each a few dozen bytes. That is the advice most "unused
options" plugins give, and on a healthy site it is noise dressed up as an optimisation.

Three conclusions follow, and they should be baked into the design rather than discovered later:

1. **Rank by bytes, never by count.** The win is a single fat abandoned option (a departed
   plugin leaving 500 KB behind), not the tail. A finding that says "155 unused options" is worse
   than no finding, because it burns the user's trust on a 5 KB saving.
2. **There is a second, safer recommendation.** 93 options are read but not autoloaded. Each one
   costs a separate cache-get, or a separate query on a site with no object cache. Turning
   autoload *on* for an option read on every request cannot break anything and cannot make a page
   slower in the way the reverse can.
3. **Neither direction can break a site.** Autoload is purely a caching decision: an option that
   is not autoloaded is still returned correctly, just via its own lookup. So the risk of a wrong
   recommendation is a performance regression, never a functional failure. That is worth saying
   out loud in the UI, and it means the honest framing of the whole feature is
   *"classify each option by measured read frequency"*, not *"find unused options"*.

### On RAM and Redis, precisely

`alloptions` is one cache key holding every autoloaded option, populated by a single query at
`wp-includes/option.php:625` and fetched once per request thereafter. Without an external object
cache that is one query plus an unserialize per request. With Redis it is one large `GET` over the
socket plus an unserialize per request, every request, on every web node.

So **size** drives both the RAM and the Redis cost; **count** drives almost nothing. 155 tiny rows
are free. One 500 KB row is not.

## 3. Where it plugs in

### 3.1 The collector has to live in the db.php drop-in

This is the one non-obvious constraint. Verified load order in `wp-settings.php`:

```
:136  require_wp_db()           <- dropins/db.php runs here (earliest SSPA code)
:151  wp_start_object_cache()
:180  wp_not_installed()
        └ is_blog_installed()   wp-includes/functions.php:1787
            └ wp_load_alloptions()          <- FIRST alloptions load
:498  foreach ( wp_get_mu_plugins() )       <- mu/sspa-loader.php + profiler bootstrap
:574  active plugins
```

The mu-plugin loads at line 498, well after the first alloptions load and after core has already
read `siteurl`, `home`, `blog_charset`, `active_plugins` and the rest of the bootstrap set. A
collector armed from `SSPA_Capture::arm()` (`profiler/class-sspa-capture.php:39-84`) misses all of
it.

`dropins/db.php` already registers a filter this early for exactly this reason - the
`enable_loading_object_cache_dropin` short-circuit at `:65-67`, whose comment says "db.php is the
only code that runs early enough to register this filter in time". The options collector goes in
the same block, after token verification (`:61-85`). `plugin.php` is loaded at `wp-settings.php:53`,
so `add_filter()` is available; the object cache is not started yet, so the accumulator must be a
plain PHP static or global, not `wp_cache_*`.

### 3.2 Everything else follows the existing collector pattern

Mirror the HTTP collector (`profiler/class-sspa-capture.php:54-55`, `:86-139`, `:451-482`):
accumulate in memory, have `finalize()` read it, add one top-level key to the payload literal at
`:284-309`. The full touch list to get a new key from request to UI:

| Step | File | Note |
|---|---|---|
| Collect | `dropins/db.php` | new `pre_option` filter, global accumulator |
| Assemble | `profiler/class-sspa-capture.php:284-309` | new `options` key in `$payload` |
| Store | `includes/class-sspa-profile-store.php:101` | rides in `profile_blob` for free; a summary column needs a `DB_VERSION` bump (currently `'1.6'`) |
| Analyse | `includes/class-sspa-analysis-engine.php:48-59` | new heuristic in the list, calling `add()` at `:649` |
| Advise | `rules/rules-snapshot.json` | `thresholds.*` + `recommendations.*` |
| Render | `includes/admin/class-sspa-insights.php` | new `case` in the `render()` switch |
| Share | `includes/class-sspa-anonymiser.php:81` **and** `includes/community/class-sspa-community-privacy.php:77` | **any evidence key not whitelisted here is silently stripped from submissions** |

### 3.3 The gap this closes is already visible in the product

`autoload_bloat` already exists: `includes/class-sspa-analysis-engine.php:904-910` compares
`demographics.metrics.autoload_bytes` against a 1 MB threshold (`rules/rules-snapshot.json:11`) and
its recommendation tells the user to *"find the biggest autoloaded rows and set autoload=no"*. The
plugin currently cannot tell them which rows. That is precisely the hole.

Two aggregate measurements already exist and should be reused rather than duplicated:
`cache.alloptions_bytes` per request (`profiler/class-sspa-capture.php:281`) and
`autoload_bytes` per run (`includes/class-sspa-demographics.php:57`).

## 4. Data shape

Record **names only, never values.** Option values hold licence keys, API tokens, SMTP passwords
and customer email addresses. There is no version of this feature that stores values.

Per profiled request, in the capture:

```php
'options' => array(
    'distinct'   => 232,      // count of distinct names read
    'calls'      => 1974,     // total get_option() calls
    'reads'      => array( 'siteurl' => 3, 'active_plugins' => 1, ... ),  // name => call count
    'truncated'  => false,    // true if the distinct cap was hit
),
```

Call counts are cheap and worth keeping: an option read 400 times in one request is its own
finding (a missing static cache in some plugin), independent of autoload.

Sizing: 265 names at roughly 25 bytes each is about 7 KB raw and compresses well inside the
existing gzipped `profile_blob`. Cap distinct names at something like 2,000 and set `truncated`,
following the existing `MAX_LOGGED_QUERIES` pattern (`profiler/class-sspa-profiling-wpdb.php:9`).
Worth noting there is currently **no byte cap on the capture blob at all**, so a new unbounded
collector is the wrong thing to add.

Exclusions at collection time:
- the plugin's own `sspa_used_*` and `sspa_options` rows, so we do not measure ourselves;
- nothing else. Filter `_transient_*` at *analysis* time, not collection time, because the read
  pattern is itself interesting.

## 5. Phase 1: page-specific, from "Analyse this page"

The adhoc path (`includes/class-sspa-adhoc.php`) already profiles one arbitrary front-end URL from
the admin bar and renders a result panel (`ajax_result` at `:165-231`). Phase 1 adds a section to
that panel:

> **Options on this page**
> 232 options read, 1,974 lookups.
> Autoloaded on every request: 327 options, 71 KB.
> Not read on this page: 175 of them, 5 KB.
> Biggest not read here: `swatchly_options` 3 KB.

Deliberately worded as **"not read on this page"**, not "unused". One page is not evidence that an
option is unused, and the copy must not imply it is. Phase 1 makes no recommendation to change
anything; it reports, and it points at the Run Analysis path for a verdict.

This alone is useful: it answers "why is my alloptions 4 MB" with a named list for the first time.

Also worth surfacing here, because it needs no cross-page evidence at all:

> `some_plugin_settings` was read 412 times on this page.

That is a finding on a single page, and it is actionable immediately.

## 6. Phase 2: Run Analysis, the cross-context union

A `baseline` run already profiles a broad catalogue (`includes/class-sspa-catalogue.php:29-131`):
home, blog, post single, category, tag, search hit, search miss, 404, feed, REST; shop, product
category, product single, cart, checkout, my account; every public CPT archive and single; and a
dozen wp-admin pages under an `admin` variant, plus a `customer` variant. That breadth is what
makes a de-autoload recommendation defensible.

Phase 2 unions the per-profile `options.reads` across every profile in the run, joins to the
current autoload set and its byte sizes, and produces two findings.

### Finding A: `autoload_unread_bytes`

Fires when the never-read autoloaded rows exceed a **byte** threshold, not a count. Suggested
starting threshold 100 KB, and a per-option floor of about 1 KB so nothing tiny is ever named.

Evidence: `{ unread_bytes, unread_count, autoload_bytes, pages_covered, top: [{name, bytes}] }`.

Recommendation names the specific rows, ordered by bytes, and says plainly that setting autoload
off moves the cost rather than removing it, so an option read on any page should be left alone.

On the measured dev site this finding would **not fire** (5 KB against a 100 KB threshold), which
is the correct outcome and the main reason for choosing a byte threshold.

### Finding B: `autoload_missing`

The reverse. An option read on a large majority of profiled pages but not autoloaded costs an
extra lookup on each. Suggested rule: read on 80% or more of profiled pages, value under 20 KB,
not a transient. Evidence `{ candidates: [{name, bytes, pages_read, pages_total}] }`.

This is the safer half and, on the measured site, the one with 93 candidates behind it.

### Confidence, and what the run cannot see

The finding's `confidence` field must record coverage honestly. A run covering 30 page types is
better evidence than an adhoc run covering one, and the UI should say which.

Contexts a scan does **not** observe, all of which must appear in the recommendation copy:

- WP-Cron jobs, and anything on `admin-ajax.php` or a REST route not in the catalogue;
- webhooks: payment gateway callbacks, shipping quotes, ERP syncs;
- logged-in customer sessions beyond the profiled `customer` variant;
- seasonal or conditional code paths (a sale banner, a tax-year report, a plugin's yearly cleanup);
- direct reads that bypass `get_option()` entirely, i.e. code calling `wp_load_alloptions()` and
  indexing the array, or querying `wp_options` with `$wpdb`. These are invisible to the filter by
  construction, and are the one true blind spot;
- under an external object cache, transients never touch options at all, so their absence from the
  read list means nothing.

Given all of that, the product rule should be: **never auto-apply, always name the specific rows,
always state coverage, and never recommend an option seen even once.**

## 7. Where the boundary with Scalability Pro sits

Clean split, and it keeps the analyser's best property intact:

- **Performance Analysis measures and recommends.** It stays read-only with respect to site
  configuration, which is what makes it safe to run on a production store. It can name every row
  and quantify the saving.
- **Scalability Pro mutates.** Flipping `autoload` is a write to `wp_options` and wants
  `wp_set_option_autoload()`, a record of what was changed, and a one-click revert.

So the analyser is complete without the writer, and the writer is far more valuable with the
analyser's evidence behind it. If Scalability Pro is present, the finding can offer to hand the
list over; if not, the recommendation is a named list the user or a developer can act on.

## 8. Cost

The collector runs only inside token-armed profiled requests (`X-SSPA-Token`, verified in
`dropins/db.php:53-61`), so ordinary visitor traffic is completely unaffected - the filter is never
registered. The only cost is inside profiling, where it is one closure call and one array write per
`get_option()`, on the order of 1,500 to 2,000 calls per page.

**Not yet measured:** the in-request overhead of the filter against an unprobed baseline. It should
be quantified before this goes in, because the profiler's own overhead distorts `gen_ms`. Expect it
to be far below the SQL collector's backtrace cost, but expect is not measured.

## 9. Community submission

Option names are more sensitive than they look. Core names are fine. Third-party names largely
duplicate what the component inventory already shares. But a name like
`acme_client_48210_api_token` identifies a customer.

So for submission: send the **aggregate numbers only** in the first cut - `distinct`, `calls`,
`unread_bytes`, `unread_count`, `autoload_bytes` - and send names only where they match a known
core option or a slug already present in the payload's component inventory, dropping everything
else. Both `includes/class-sspa-anonymiser.php:81` and
`includes/community/class-sspa-community-privacy.php:77` need the new keys whitelisted or they are
stripped silently. A new evidence type or a payload schema minor bump is a separate decision, and
the wire contract in `.docs/performance-analysis-submission-implementation.md` has to be updated in
the same pass as the receiver.

## 10. Test plan

Following `.tests/README.md` conventions, in the disposable Docker site:

1. Plant a fixture plugin that registers a large autoloaded option it never reads, and a second
   option it reads on every request but does not autoload.
2. Run a real `baseline`, and assert the capture carries `options.reads` with the fixture's read
   option present and its unread option absent. Drive the real crawler, not a hand-built capture.
3. Assert `autoload_unread_bytes` fires naming the fat unread option, and does **not** fire when
   the fixture's option is shrunk below the threshold - the negative case is the one that matters,
   given the measurement in section 2.
4. Assert `autoload_missing` names the read-but-not-autoloaded option.
5. Assert an option read only on `/shop/` is **not** recommended after a run that covered `/shop/`.
6. Assert the privacy gate strips a fabricated `acme_client_48210_api_token` name from a submission.

## 11. Decisions needed before building

1. Byte thresholds: 100 KB total unread and a 1 KB per-option floor are starting guesses, not
   measured. They belong in `rules/rules-snapshot.json` so the signed feed can tune them once
   community data exists.
2. Does Phase 1 ship on its own? It is genuinely useful and carries no recommendation risk.
3. Do we store a per-profile summary column (`options_distinct`, `options_calls`) or leave it all
   in `profile_blob`? A column costs a `DB_VERSION` bump but makes trend queries possible.
4. Is `autoload_missing` in scope for the first cut? It is the safer finding and probably the
   bigger win, but it is a different recommendation with a different threshold.
5. How much does the filter cost per request? Section 8.
