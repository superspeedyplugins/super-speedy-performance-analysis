# Community submission review - 0.12.0

Date: 2026-08-09. Reviewed at commit `f3e82ff` plus the working-tree changes made the same day.

Prompted by a live error on superspeedyplugins.com:

```
2026-08-09 11:33:33 - The community hub did not accept the registration.
Install ID: 2ae292b8-f8d9-4623-9db2-8a5ed306c647 (random - not derived from your site).
```

## 1. The live error was stale code, not a defect in the current client

That message does not exist anywhere in the current tree. It only exists in history, in
`includes/class-sspa-submitter.php` at `836c7b5^`, i.e. **0.11.4 and earlier**:

```php
return new WP_Error('sspa_register_failed', __('The community hub did not accept the registration.', ...));
```

superspeedyplugins.com is running **0.11.4** (checked via the site's own plugin list). 0.11.4
registers against `https://superspeedy.org/?rest_route=/ssph/v1/register`, which was removed
with the rest of the schema-1 hub in `d1e0b1d`. Confirmed live on 2026-08-09:

```
$ curl -X POST 'https://superspeedy.org/?rest_route=/ssph/v1/register' -d '{"install":"x"}'
{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}
```

The 0.12.0 collector is healthy and rejects malformed input correctly:

```
$ curl -X POST https://collector.superspeedy.org/v1/installations -d '{"install_uuid":"00000000-0000-4000-8000-000000000000"}'
{"error":{"code":"invalid_registration","message":"invalid installation UUID or client version"}}   # HTTP 400
```

**Nothing fixes that site except deploying 0.12.0 to it.** Version spread at the time of review:
local repo 0.12.0 (committed), superspeedyplugins.com 0.12.0's predecessor 0.11.4, public GitHub
Release 0.10.12.

### Fingerprinting a reported message to a version

Useful when a customer pastes an error and does not know their version:

| Message | Version |
| --- | --- |
| "The community hub did not accept the registration." | 0.11.4 and earlier only |
| "The hub rejected the submission (HTTP %d)." | 0.11.4 and earlier only |
| "The collector rejected the request (HTTP %d)." | 0.12.0+ only |
| "Queued locally. Delivery runs in the background and retries automatically." | 0.12.0+ only |
| "Sharing is not enabled - opt in on the Share tab first." | both - does not discriminate |

## 2. Fixed in this pass

**The rules feed hit the dead hub every hour.** `SSPA_Rules_Feed::refresh()` was the one
surviving consumer of `SSPA_Submitter::endpoint()`, so 0.12.0 shipped an hourly outbound
`GET .../ssph/v1/rules` (30s timeout, 404, result discarded) on every install. Fixed with a
12-hour failure backoff transient; see `.changelog-full.md` under 0.12.0. Containment only - the
feed still has no live source.

**Claiming a row is now compare-and-set, and a claim holds the row back** - findings 3.1 and 3.2
below, both fixed the same day. `begin_attempt()` CASs on `(id, attempts, state)`, returns null
when it loses the race, and sets `next_attempt` to now + `ATTEMPT_LEASE_SECONDS` (600) so a
process that dies mid-attempt does not leave the row instantly due. `SSPA_Community_Worker::run()`
returns without submitting when the claim is lost. Covered by
`.tests/cases/20-community-outbox.php`.

**Admin assets were cache-keyed on `SSPA_VERSION` alone**, so editing a bundled stylesheet or
script within one release served every already-loaded browser the previous file. Now keyed on
`SSPA_VERSION` plus the file's mtime via `sspa_asset_version()`. Not a submission defect, but it
is what made the tab-bar work in this release look broken.

## 3. Verified findings

3.1 and 3.2 were fixed on the same day and are marked so; the rest stand.

Each was read in the code, not inferred from a description. Ranked by what would actually bite.

### 3.1 A crash mid-attempt burns the whole backoff ladder - FIXED 2026-08-09

`SSPA_Community_Outbox::begin_attempt()` sets `state='retry'`, `phase='reserving'` and increments
`attempts`, but does **not** move `next_attempt` forward. If the process dies between
`begin_attempt()` and `sent()`/`failed()` - a PHP timeout part way through a 120-second upload of
up to 32 MiB is the realistic case - the row is left in `retry` with a `next_attempt` already in
the past. The worker's `finally` does not run on a fatal, so the 300-second option lease is what
gates the next attempt; after it expires the row is immediately due again, `attempts` increments
again, and the item walks up 15min/1h/6h/24h in a few minutes of wall clock without ever having
waited.

Fix shape: set `next_attempt` to now + a lease horizon inside `begin_attempt()`, so a dead
attempt is indistinguishable from a scheduled retry.

### 3.2 Nothing atomically claims a row - FIXED 2026-08-09

`due()` is a plain `SELECT ... LIMIT 1` and `begin_attempt()` is an unconditional
`UPDATE ... WHERE id = %d`. There is no compare-and-set, no `FOR UPDATE`, no owner column. The
only exclusion is `SSPA_Community_Worker::lock()`, which is a read-then-write lease over
`add_option()` - `add_option()` short-circuits on a cached `get_option()` and its write is
`INSERT ... ON DUPLICATE KEY UPDATE`, so two simultaneous requests can both be told they hold it.
Two overlapping WP-Cron spawns can therefore submit the same row twice. The receiver's
`submission_uuid` idempotency is the real backstop, which is why this has not shown up.

Fix shape: make the claim `UPDATE ... SET state='retry' WHERE id = %d AND state IN ('pending','retry')`
and proceed only when one row was affected.

### 3.3 A manual share can lose its only wake-up

`SSPA_Community_Worker::run()` deletes `sspa_share_manual_pending` whenever `due()` returns
nothing - including when a manually shared row exists but is inside its backoff window. With
site-wide sharing off, `maybe_nudge()` can then no longer re-arm the cron, so the only thing
keeping that item alive is the single `nudge($delay)` scheduled inside `failed()`. Deactivating
and reactivating the plugin clears `sspa_submission_worker_event` (`SSPA_Install::deactivate()`),
which drops that event and strands the item indefinitely.

Fix shape: only clear the flag when no manual row exists in any non-terminal state, rather than
when none is currently due.

### 3.4 The immutable bytes are not self-contained

`SSPA_Community_Client::submit()` hard-fails with the permanent `sspa_outbox_run_missing` if the
run row has gone, purely to read `run_type` for the manifest, and `SSPA_Community_Outbox::history()`
INNER JOINs `runs`, so such a row also vanishes from the Share tab. Nothing in the plugin deletes
run rows today (`ajax_prune_blobs()` only NULLs `profile_blob`), so this is latent rather than
live, but it contradicts the "the queued bytes survive anything" promise.

Fix shape: denormalise `run_type` onto the outbox row at queue time.

### 3.5 A wrong collector URL is permanent after one attempt

`permanent_status()` treats anything outside {408, 425, 429, 500, 502, 503, 504} as permanent, so
a 404 from a mistyped `sspa_collector_url` puts the row into `permanent_failure` on the first
attempt and requires a manual "Retry now" per item. Arguably correct for a genuine 404, painful
for a configuration typo.

### 3.6 Build failures that are invisible

`remember_local_error()` only records exporter build errors into `sspa_submission_build_errors`.
Encode, compress and size-limit failures return a `WP_Error` that is recorded nowhere, so they do
not appear in the Share tab's build-error list and the backfill silently retries them every pass.

### 3.7 Perpetual 60-second cron re-arm

On an opted-in site with an empty queue, `maybe_nudge()` on `init` re-arms
`sspa_submission_worker_event` every 60 seconds forever: the event fires, finds nothing, returns,
and the next `init` re-arms it. One indexed query per minute - bounded, but permanent churn.

### 3.8 Schema upgrade runs inside an arbitrary front-end request

`SSPA_Install::maybe_upgrade()` is on `plugins_loaded` for every request. On a version mismatch it
runs `dbDelta` over every table, plus `ensure_run_uuids()`, which does a full
`SELECT id, run_uuid FROM wp_sspa_runs`, a per-row `UPDATE` for anything missing a UUID, and a
`SHOW INDEX` + `ALTER TABLE ... ADD UNIQUE KEY`. There is no lock, and `update_option('sspa_db_version', ...)`
is the last statement, so any timeout before it means the whole thing repeats on the next request.
On a site with thousands of runs, upgrading 0.11.4 to 0.12.0 does that synchronous DDL inside
whichever page load happens to be first.

Worth noting the upgrade itself is sound: a 0.11.4 site holds `sspa_db_version = '1.3'`, which
mismatches, so the outbox tables are created without needing a reactivation.

### 3.9 Cosmetic

- `sspa_not_opted_in` carries two different messages ("Sharing is not enabled - opt in on the
  Share tab first." vs "Sharing is not enabled."). Worth unifying, not least because message text
  is being used to fingerprint versions.
- `SSPA_Community_Schema::MEASUREMENT_VERSION` is declared and referenced only at run creation.
- `sspa_submission_events` rows are written and never read by any screen or test.
- `uninstall.php` drops tables when asked but deletes no community options
  (`sspa_collector_secret_*`, `sspa_collector_registered_*`, `sspa_share_*`,
  `sspa_community_backfill_cursor`, `sspa_submission_build_errors`), nor the orphaned legacy
  `sspa_install_secret_*` / `sspa_submission_log` a 0.11.4 site carries forward.

## 4. Dead legacy transport still present

| Location | Reference |
| --- | --- |
| `includes/class-sspa-submitter.php` | `hub_url()` / `endpoint()`, default `https://superspeedy.org`, `?rest_route=/ssph/v1/` |
| `includes/class-sspa-rules-feed.php` | the only live consumer of the above (now backed off) |
| `includes/class-sspa-anonymiser.php` | `build()` - the whole schema-1 payload builder, no callers |
| `hub/` | empty leftover directory |

`SSPA_Submitter::register()` also has no callers anywhere; it is dead compatibility surface.
There is no migration or cleanup of `sspa_hub_url`, `sspa_install_secret*` or
`sspa_submission_log`, so an old `sspa_hub_url` dev override on an upgraded site still
redirects the rules feed.

## 5. Test coverage gaps

The four community cases (`20`-`23`) are strong on payload content, privacy, idempotency and
real-run integration. Not covered by any automated case: the worker lock, concurrent claiming,
`SSPA_Community_Client::submit()` end to end (only the manual scripts do that), the AJAX
handlers, the 1.3 to 1.6 schema upgrade, and the `sspa_submission_events` table.
