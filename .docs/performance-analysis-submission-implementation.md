# Super Speedy Performance Analysis community submission - implementation specification

Status: **complete and verified against the production collector; 0.12.0 is written in the
working tree, not yet committed, pushed, deployed or released**, 2026-08-08.

Implementation verification on 2026-08-08:

- the plugin's disposable WordPress test passed schema activation, immutable outbox,
  versioning, Excimer/toggle/finding evidence, privacy and offline-retry assertions;
- the real PHP client registered, reserved, uploaded 990 gzip bytes directly to the private
  development R2 bucket, completed, verified its receipt and marked the outbox item sent;
- the receiver then reported that submission as `storage_status=archived` and
  `processing_status=complete`;
- the receiver's independent live R2 round-trip and full Go test suite also passed.
- schema 1.1 with page-profile/checkout evidence v2 then uploaded as 1,019-1,022 gzip bytes,
  including through the real background worker, was permanently archived and correctly
  marked `partial` by processor 1.0;
- a dry-run reprocessing filter selected exactly that schema-1.1 partial submission for a
  future processor v2, without selecting already-understood schema-1.0 data.
- the obsolete bundled WordPress hub, schema-1 round-trip case and local-hub harness were
  removed; after making the checkout fixtures deterministic, the complete plugin suite passed
  all 20 case files with zero failures.
- the production receiver is live at `https://collector.superspeedy.org`; its public health,
  synthetic direct-to-R2 upload, manifest processing, database backup and isolated restore have
  passed. The verified public synthetic payload was 361 gzip bytes.
- the real PHP client then completed the production compatibility smoke test from the
  disposable Docker WordPress site. `.tests/manual/live-production.php` registered a fresh
  random installation identity against `https://collector.superspeedy.org` and passed all 16
  assertions, including the outage-recovery case, on 2026-08-08. Two synthetic spot-run
  submissions were archived: 1,021 and 1,020 gzip bytes, 2,767 uncompressed, schema 1.1,
  completion HTTP 201, `storage_status=archived` and `processing_status=partial` - the expected
  explicit outcome for schema 1.1 under processor 1.0, which leaves it available for targeted
  reprocessing.
- the receiver-side confirmation was made with the collector's own signed
  `GET /v1/submissions/<uuid>` endpoint rather than a server login. The client gained
  `SSPA_Community_Client::status()` and the `SSPA-SUBMISSION-STATUS-V1` canonical string for
  this; it is a read-only use of an endpoint the receiver already exposed, so the wire contract
  below is otherwise unchanged.
- the complete plugin suite passed 20 case files and 471 assertions at 0.11.4, and again after
  the 0.12.0 version bump.

The checklist below distinguishes the working collection/backfill path from the remaining
release hardening and deployment work.

This document specifies and records the implementation in the canonical Performance Analysis
plugin at:

`/opt/homebrew/var/www/superspeedy/wp-content/plugins/super-speedy-performance-analysis`

The matching receiver specification is `.docs/receiver-implementation.md` in the separate
`superspeedy.org` repository. The complete client-side wire contract needed for independent work
is reproduced below, so this document still stands alone. Any implementation change to an
endpoint, signature, state, limit or field must update both repositories.

This replaces the original schema-1 `SSPA_Anonymiser::build()` and synchronous
`SSPA_Submitter::submit()` path as the normal production submission mechanism. The bundled
schema-1 WordPress hub and its round-trip harness have been removed because they cannot cover
current Excimer, adhoc-page, checkout, deep-analysis, cache-impact or plugin-toggle evidence.

## 0. Handoff snapshot: read this before changing code

This is a public implementation document. It contains no receiver master key, R2 credential,
installation secret or presigned URL. None is required to build or release the plugin.

The client described below is already present on `main`; do not start by rebuilding P1-P4.
The implementation landed principally in commits `836c7b5` (durable client), `b309413`
(backfill), `d1e0b1d` (obsolete hub removal) and `572f03b` (stable checkout fixtures). Begin by
checking the current worktree and reading `.tests/README.md`, then audit or extend the existing
classes.

Current protocol/version facts:

- plugin version in the main file and `Stable tag:`: `0.12.0`;
- production collector: `https://collector.superspeedy.org`;
- transport version: `1`;
- payload schema: `1.1`;
- anonymisation version: `1`;
- measurement version: `1`;
- `sspa/page-profile` and `sspa/checkout-flow` evidence versions: `2`;
- all other current evidence versions: `1`.

Existing implementation map:

```text
includes/community/class-sspa-community-identity.php   install UUID, per-collector secret and URL
includes/community/class-sspa-community-schema.php     version registry, canonical JSON and gzip
includes/community/class-sspa-community-privacy.php    explicit outbound privacy boundary
includes/community/class-sspa-community-exporter.php   coherent run/evidence projection
includes/community/class-sspa-community-outbox.php     immutable bytes, state and retry metadata
includes/community/class-sspa-community-client.php     register, reserve, R2 PUT and complete
includes/community/class-sspa-community-worker.php     bounded WP-Cron sender and lease
includes/community/class-sspa-community-backfill.php   resumable historical queue creation
includes/class-sspa-submitter.php                       compatibility facade
includes/admin/tabs/share.php                           consent, preview and queue controls
.tests/cases/20-community-outbox.php                    privacy, bytes, HMAC and retry tests
.tests/cases/21-community-evidence.php                  every current run/evidence type
.tests/cases/22-community-backfill.php                  history, consent and preview
.tests/cases/23-community-run-integration.php           real runs of every type queue payloads
.tests/manual/live-r2.php                               local receiver/R2 compatibility runner
.tests/manual/live-production.php                       production smoke test and outage recovery
```

Credential handling is deliberately client-generated:

- `install_uuid` is a random local identifier;
- the plugin generates a random 32-byte `install_secret` on first registration;
- the secret is stored in a URL-scoped, non-autoloaded WordPress option;
- the secret is sent once to `POST /v1/installations` over verified TLS and is then used locally
  to calculate HMACs;
- the receiver returns no long-lived credential;
- R2 credentials never enter WordPress because the receiver returns a five-minute exact-key
  presigned upload URL.

Therefore no `.env` file or shared production key is needed in this public repository. If future
developer tooling genuinely needs a secret, store it only in an ignored `.env` file and add a
safe `.env.example`; never put a real value in this document, a fixture, a command example or
Git history.

## 1. Objective

After explicit site-owner opt-in, create a privacy-safe, versioned and coherent export for each
shareable analysis run, retain its exact compressed bytes in a durable local outbox, upload
those bytes directly to R2 using a receiver-issued temporary URL, and remove the outbox body
only after the receiver returns a receipt proving permanent archival.

The exporter must cover every current profiling mode and provide a generic evidence container
for future modes. The receiver does not need to understand a new evidence type before the
plugin may submit it.

The core client guarantee is:

> A queued submission is byte-stable across every retry. It remains local until the receiver
> acknowledges that the same UUID and SHA-256 are permanently archived.

## 2. Fixed client decisions

1. Anonymisation is exclusively a Performance Analysis responsibility and occurs before the
   payload enters the outbox or crosses the network.
2. One submission represents one coherent local run, not a mixture of latest profiles and
   unrelated historical impacts.
3. Every run receives a persistent random `run_uuid`.
4. Every outbox item receives one `submission_uuid`, reused for all retries.
5. The exact outgoing JSON is encoded once, compressed with gzip level 6 once, hashed once and
   stored unchanged until receipt.
6. The client uses the receiver's reserve, direct R2 upload and complete protocol as one
   idempotent submission operation.
7. WordPress Cron retries unavailable services indefinitely at a low frequency. Receiver
   uptime is not required for analysis completion.
8. Unknown future evidence is represented with a namespaced type and integer version; adding
   it does not require changing the transport protocol.
9. Automatic submission is enabled only after explicit opt-in. Updates never silently enable
   sharing. Consent has two scopes: the site-wide setting means "every analysis, now and in
   future", and a per-run share is its own act of consent for exactly one analysis. A per-run
   share works with the site-wide setting off and never turns it on.
10. The Share screen shows the exact uncompressed JSON represented by a queued item, plus its
    compressed size, SHA-256, state and receipt.

## 3. Legacy schema-1 gaps closed by this implementation

The removed schema-1 exporter:

- selects only the latest completed `baseline` or `spot` run;
- excludes `adhoc` and `checkout` runs;
- does not export Excimer functions, components or phases from `profile_blob`;
- sends historical deep impacts without coherent run linkage or measurement-time versions;
- sends only a small latest cache-impact summary;
- cannot identify that a spot run followed a plugin activation/deactivation;
- mixes current plugin inventory with older evidence;
- has no stable submission, run or evidence identifiers;
- rebuilds a mutable payload each time Submit Now is pressed;
- sends synchronously with no durable outbox;
- disables TLS verification for hub calls;
- sends a deterministic domain hash which the new protocol does not need.

The revised implementation must not carry these limitations into the permanent dataset.

## 4. Local data model changes

### 4.1 Runs

Add to `sspa_runs`:

```text
run_uuid char(36) unique not null
measurement_version int unsigned not null
share_context longtext null
```

Migration assigns a random UUID to every existing run. New runs receive it at insertion. A
run UUID never changes when history is displayed, exported or retried.

`share_context` contains local structured context needed to build evidence later, including
safe plugin-toggle context and explicit relationships. It is local data and is not copied
blindly into the community payload.

### 4.2 Outbox

Create `sspa_submission_outbox`:

```text
id bigint unsigned primary key
submission_uuid char(36) unique not null
run_id bigint unsigned not null
run_uuid char(36) not null
transport_version int unsigned not null
payload_schema_major int unsigned not null
payload_schema_minor int unsigned not null
anonymisation_version int unsigned not null
client_version varchar(32) not null
payload_gzip longblob not null
payload_sha256 char(64) not null
compressed_bytes bigint unsigned not null
uncompressed_bytes bigint unsigned not null
state varchar(24) not null
phase varchar(24) not null
consent_scope varchar(16) not null default 'automatic'
reservation_uuid char(36) null
upload_expires_at datetime null
receipt_uuid char(36) null
attempts int unsigned not null default 0
next_attempt datetime null
last_http_status smallint null
last_error_code varchar(64) null
last_error_detail varchar(255) null
created datetime not null
last_attempt datetime null
sent_at datetime null
```

Indexes:

```text
unique (submission_uuid)
unique (run_uuid)
index (state, next_attempt)
index (created)
```

There is normally one community submission per run. If a future run must be split because it
exceeds the transport limit, introduce an explicit part number and manifest rather than
silently allowing duplicate `run_uuid` rows.

### 4.3 Submission history

Create an append-only `sspa_submission_events` table or bounded equivalent recording state,
phase, attempt, safe reason code and time. Do not record payloads, install secrets, HMACs or
presigned URLs in logs/events.

Once sent, `payload_gzip` may be cleared after a conservative local grace period while
retaining UUID, hash, size, run relationship and receipt. Until receipt, the body is never
removed by normal cleanup.

## 5. Version model

Keep these independent:

| Version | Meaning | Bump when |
|---|---|---|
| `client_version` | plugin release | any plugin release |
| `transport_version` | reserve/upload/complete protocol | endpoints/signatures/transport change |
| payload schema major/minor | overall JSON envelope | envelope compatibility changes |
| `anonymisation_version` | client privacy projection | privacy transformation changes |
| `measurement_version` | how a measurement was produced | profiler/methodology semantics change |
| evidence type/version | one evidence record's structure | that record changes |

Use integer major/minor fields, never a floating-point schema value. An evidence processor
must be able to distinguish version 1 from version 2 without inferring it from
`client_version`.

### Bump rules

- Adding a new evidence type does not require a payload-schema major bump.
- Adding optional envelope fields requires a payload-schema minor bump.
- Breaking envelope changes require a payload-schema major bump.
- Changing Excimer fields bumps `sspa/excimer-profile`'s evidence version.
- Changing how a metric is measured bumps its measurement version even if JSON is unchanged.
- Correcting privacy transformation bumps `anonymisation_version` and the affected evidence
  version when its output changes.

## 6. Payload envelope

Initial proposed envelope:

```json
{
  "payload_schema": {"major": 1, "minor": 0},
  "submission_uuid": "uuid",
  "install_uuid": "uuid",
  "client": {
    "name": "super-speedy-performance-analysis",
    "version": "0.12.0"
  },
  "anonymisation_version": 1,
  "payload_created_at": "2026-08-08T12:34:56Z",
  "site_snapshot": {},
  "run": {
    "run_uuid": "uuid",
    "run_type": "checkout",
    "trigger_source": "manual",
    "measurement_version": 1,
    "started_at": "2026-08-08T12:30:00Z",
    "finished_at": "2026-08-08T12:34:00Z",
    "outcome": "complete",
    "incomplete_reason": null
  },
  "component_inventory": [],
  "evidence_manifest": [
    {
      "type": "sspa/excimer-profile",
      "version": 1,
      "measurement_version": 1,
      "count": 11
    }
  ],
  "evidence": [
    {
      "evidence_uuid": "uuid",
      "type": "sspa/excimer-profile",
      "version": 1,
      "measurement_version": 1,
      "data": {}
    }
  ]
}
```

The manifest is redundant by design. It lets the receiver index what ought to be present and
later verify that processing did not silently skip records.

Use deterministic ordering before JSON encoding:

- fixed envelope key order;
- inventories sorted by component type/slug/version;
- evidence sorted by type, version and evidence UUID;
- associative metric maps sorted by key;
- arrays whose order conveys flow/time retain their meaningful order.

`submission_uuid` is created before encoding, so it is part of the immutable payload.

## 7. Evidence types required for the first schema

The exact fields receive a separate machine-readable schema/fixture during implementation.
The initial types are:

### `sspa/site-snapshot@1`

- WordPress, PHP and database-family/version cohorts;
- object-cache state and safe cache technology category;
- theme slug/version where permitted;
- sector and bucketed site-size metrics;
- plugin/theme inventory captured from the run's measurement-time snapshot.

Do not replace historical measurement inventory with the site's current active plugins.

### `sspa/page-profile@1`

- safe page/action classification and taxonomy version;
- HTTP method, variant and object-cache mode;
- wall/TTFB, page generation, PHP, SQL, HTTP and mail metrics;
- memory, query/row/duplicate/cache counts;
- response code, sample summaries and blocked/incomplete state;
- relationship to the corresponding Excimer and component evidence.

### `sspa/component-observation@1`

- attributed component type, slug and measurement-time version;
- profile/evidence relationship;
- query, SQL, row, HTTP, mail and cache totals;
- attribution/methodology version.

### `sspa/excimer-profile@1`

- collector name, period, sample count and sampled wall time;
- top functions with inclusive/self time;
- safe component attribution and driver components;
- component sampled-time rollup;
- request phases and top phase functions;
- relationship to one page/action profile.

Current Excimer caps, including the top-40 function list and top functions per phase, should
be exported explicitly rather than inferred by the receiver.

### `sspa/finding@1`

- finding type, severity, component, safe page classification;
- recommendation key and confidence;
- numeric/enum evidence;
- normalised SQL fingerprint only where the client proves literals are removed.

### `sspa/plugin-impact@1`

- plugin slug and measurement-time version;
- page/action classification, method and cache mode;
- delta metrics, noise floor and confidence;
- evidence class, baseline run/profile relationship and test run/profile relationship;
- isolation-method version.

Impacts are scoped to the coherent deep-analysis run. Do not append the latest 1,000 global
rows to every submission.

### `sspa/cache-impact@1`

- component and measurement-time version;
- cache-on/off/priming relationships;
- saved percentage and absolute query/SQL/page deltas;
- cache technology/mode and method version.

### `sspa/checkout-flow@1`

- ordered safe flow steps and per-step profile relationships;
- outcome and safely classified skipped/blocked steps;
- total and slowest-step time;
- secured/at-risk split around the payment boundary;
- email and sanitised HTTP-host-category totals;
- rolled-up component and Excimer summaries;
- checkout type/methodology version.

Never export product/order/user IDs, checkout URLs, addresses, emails, payment tokens, request
bodies or raw gateway responses.

### `sspa/plugin-toggle-spot@1`

- toggled plugin slug and version where known;
- action: activated or deactivated;
- relationship to the resulting spot run;
- explicit evidence class `after_toggle_observation` unless a true paired before/after
  measurement exists.

The current prompt deletes its transient before the run and does not persist this context.
Implementation must save it into the new run's local context before starting the run. Do not
label the current after-only spot run as a causal plugin impact.

### `sspa/adhoc-page-profile@1`

- opaque evidence UUID;
- safe classification such as frontend/admin, singular/archive/search/API and post type where
  acceptable;
- profile and Excimer relationships.

Never export the URL or current URL-derived `page_key`. Common URLs can be dictionary-guessed
from deterministic hashes. Unknown arbitrary URLs use a coarse `custom-frontend` or
`custom-admin` classification.

### Future evidence

New evidence uses a namespaced lowercase type and positive integer version:

```text
sspa/<descriptive-type>@<integer-version>
```

It may be queued before the receiver has a semantic processor. The exporter must still supply
a safe bounded JSON `data` object, stable evidence identity and manifest entry.

## 8. Client privacy boundary

The community exporter must never serialise the local tables or `profile_blob` wholesale. It
must build an explicit share-safe projection.

Never export:

- domains, URLs, paths or query strings;
- deterministic domain hashes;
- raw SQL or SQL literals;
- email, IP, username, customer, user, session, cart or order identifiers;
- cookies, nonces, authorisation/payment tokens or request headers;
- request/response bodies;
- filesystem paths;
- checkout form values, addresses or product/order data;
- arbitrary exception messages or free text without a defined transformation.

Potentially identifying profiler strings require explicit rules:

- known public plugin/theme slugs may be exported;
- component attribution maps to slug/type rather than full paths;
- function/class names are allowed only under a documented rule and length/character bound;
- closure/file information uses safe basename/component mapping and never a full path;
- HTTP evidence uses host/service category, not URL;
- SQL uses a tested literal-free fingerprint;
- unknown/custom components may need an opaque per-submission identifier rather than their
  private name.

Add a client-side forbidden-data test scanner as defence in depth. It blocks outbox creation
and shows an actionable local error; it is not a receiver-side anonymiser.

The Share screen renders the exact decompressed queued JSON. Documentation must list fields by
evidence type/version.

## 9. Export creation

### Trigger

When an opted-in shareable run reaches a terminal state:

1. finish all local analysis/findings work;
2. capture the final measurement-time component inventory and run relationships;
3. build the share-safe payload;
4. validate the payload locally;
5. canonicalise and encode JSON once;
6. gzip at level 6;
7. calculate SHA-256 over the exact compressed bytes;
8. insert the complete outbox row atomically;
9. schedule asynchronous submission;
10. return analysis completion without waiting on the network.

Normal `done` runs are shareable. Failed checkout runs with valid partial step evidence may be
exported as `outcome=failed` and explicitly incomplete. Cancelled or corrupted runs are not
submitted by default.

### Pruning interaction

For opted-in sites, detailed local profile data required by an export must not be pruned until
the corresponding outbox item has been built successfully. Once the exact outbox payload
exists, later local profile-blob pruning cannot change it.

For opted-out sites, sharing code creates no outbox data and local retention continues
normally.

### Size limits

- one run per payload;
- gzip level 6;
- reject local creation above 256 MiB uncompressed or 32 MiB compressed;
- show the exact reason and retain the run locally;
- do not silently truncate evidence to fit;
- add an explicit multipart submission schema later if real runs exceed the limit.

Current measured disposable-site whole-run gzip sizes are approximately 4-6 KB for one page,
19 KB for a five-page spot run, 27 KB for a small deep run, 60 KB for a 28-profile baseline
and 47-58 KB for complete checkout flows. Real complex/deep runs may be much larger; record
local size percentiles after release.

## 10. Registration and credentials

Replace the WordPress REST endpoint construction with a configurable collector base URL,
defaulting to:

```text
https://collector.superspeedy.org
```

Registration uses `POST /v1/installations`. Retain per-hub secret storage so development and
production credentials cannot be mixed.

```json
{
  "install_uuid": "uuid",
  "install_secret": "64 lowercase hexadecimal characters",
  "client_version": "0.12.0",
  "wordpress_version": "6.x",
  "php_version": "8.x"
}
```

First registration returns HTTP `201`; an idempotent repeat with the same UUID and secret
returns `200`:

```json
{
  "install_uuid": "uuid",
  "registered": true,
  "duplicate": false
}
```

The same UUID with another secret returns HTTP `409` and error code
`registration_conflict`.

Requirements:

- generate and persist a random 32-byte install secret before the first registration request;
- submit it only over verified TLS as defined in the receiver registration contract;
- retry with the same install UUID and secret if registration or its response fails;
- TLS verification is always enabled in production;
- remove every current `'sslverify' => false` hub call;
- secret is never included in logs, UI, support diagnostics or payload;
- an existing secret is reused;
- registration is attempted lazily before the first reservation;
- registration failure leaves the outbox untouched and schedules retry.

## 11. Submission operation

From the rest of the plugin, reserve/upload/complete appears as one idempotent method operating
on a stored outbox row.

The exact reservation HMAC input is this UTF-8 string, including the final newline:

```text
SSPA-SUBMISSION-RESERVE-V1\n
<transport_version>\n
<install_uuid>\n
<submission_uuid>\n
<payload_sha256>\n
<compressed_bytes>\n
<uncompressed_bytes>\n
<payload_schema_major>\n
<payload_schema_minor>\n
<client_version>\n
<anonymisation_version>\n
<run_uuid>\n
<run_type>\n
<payload_created_at>\n
```

Calculate lowercase hexadecimal HMAC-SHA256 using the 32 raw bytes represented by the local
64-character hexadecimal installation secret. Send it as `X-SSPA-Signature`, with the
installation UUID in `X-SSPA-Install`.

The exact completion HMAC input is:

```text
SSPA-SUBMISSION-COMPLETE-V1\n
<transport_version>\n
<install_uuid>\n
<submission_uuid>\n
<reservation_uuid>\n
<payload_sha256>\n
```

UUIDs and SHA-256 are lowercase. Integers have no leading zeroes. The client version, run type
and RFC 3339 UTC payload time are the exact strings sent in the JSON request. The fixed
PHP-to-Go fixture in case 20 is authoritative against accidental newline or encoding drift.

### Phase A: reserve

Send only this transport manifest to:

```http
POST /v1/submissions
```

```json
{
  "transport_version": 1,
  "submission_uuid": "uuid",
  "payload_sha256": "64 lowercase hexadecimal characters",
  "compressed_bytes": 59546,
  "uncompressed_bytes": 608553,
  "payload_schema_major": 1,
  "payload_schema_minor": 1,
  "client_version": "0.12.0",
  "anonymisation_version": 1,
  "run_uuid": "uuid",
  "run_type": "checkout",
  "payload_created_at": "2026-08-08T12:34:56Z"
}
```

Calculate the reservation HMAC over the exact newline-delimited specification. Add shared PHP
and Go fixtures so character encoding, newline, integer and case differences cannot drift.

Receiver responses:

- `awaiting_upload`: store reservation UUID and expiry for diagnostics, then upload;
- `uploaded`: skip upload and complete;
- `complete`: verify returned UUID/hash, store receipt and mark sent;
- `409 submission_conflict`: permanent local error requiring inspection;
- retryable service/network response: retain item and schedule retry.

An `awaiting_upload` response contains:

```json
{
  "submission_uuid": "uuid",
  "reservation_uuid": "uuid",
  "storage_status": "awaiting_upload",
  "upload_url": "https://temporary-presigned-r2-url/",
  "upload_expires_at": "2026-08-08T12:39:56Z",
  "required_headers": {"Content-Type": "application/gzip"}
}
```

### Phase B: upload

Use `wp_remote_request()` with method `PUT`, the returned R2 URL, the required
`Content-Type: application/gzip` header and the exact `payload_gzip` bytes. Do not add an AWS
SDK or R2 credentials to the plugin. The presigned URL is an opaque bearer URL and must not be
logged.

Do not allow WordPress or an HTTP library to recompress, decode or otherwise transform the
body. Confirm the sent byte count against `compressed_bytes` immediately before the request.

The upload URL expires after five minutes. An expired or failed URL is not a permanent error;
the next reserve call returns a fresh URL.

### Phase C: complete

Send the reservation UUID and expected hash to:

```http
POST /v1/submissions/<submission_uuid>/complete
```

```json
{
  "transport_version": 1,
  "reservation_uuid": "uuid",
  "payload_sha256": "64 lowercase hexadecimal characters"
}
```

Calculate the completion HMAC exactly as specified. Accept success only when:

- returned submission UUID equals the outbox UUID;
- returned hash equals the outbox hash using timing-safe comparison where available;
- a non-empty receipt UUID is present;
- storage status is `archived`.

Persist the receipt before clearing the local body.

New completion returns HTTP `201`; an idempotent repeat returns `200` with the same receipt:

```json
{
  "received": true,
  "duplicate": false,
  "submission_uuid": "uuid",
  "receipt_uuid": "uuid",
  "payload_sha256": "64 lowercase hexadecimal characters",
  "storage_status": "archived",
  "processing_status": "pending",
  "received_at": "2026-08-08T12:34:59Z"
}
```

### Status query

The receiver also exposes a signed read-only lookup, used by release and support checks so the
archival outcome comes from the collector rather than being inferred from the local receipt:

```http
GET /v1/submissions/<submission_uuid>
```

It takes the same `X-SSPA-Install` and `X-SSPA-Signature` headers and no body. Its canonical
string is the shortest of the three and carries no transport version:

```text
SSPA-SUBMISSION-STATUS-V1\n
<install_uuid>\n
<submission_uuid>\n
```

It returns `submission_uuid`, `receipt_uuid`, `payload_sha256`, `storage_status`,
`processing_status` and `received_at`. A submission belonging to another installation returns
`404 submission_not_found`, identically to one that does not exist, so the endpoint cannot be
used to enumerate. `processing_status` is `not_queued` at reservation, `pending` at archival,
then `processing` and finally one of `complete`, `partial`, `unsupported` or `failed`.

`SSPA_Community_Client::status()` is a diagnostic call only. It never changes outbox state: a
receipt verified at completion remains the sole authority for marking a row sent.

## 12. Outbox state machine

Use durable state plus an informational phase:

```text
pending/retry
    -> reserving
    -> uploading
    -> completing
    -> sent

permanent_failure
```

`reserving`, `uploading` and `completing` are phases of an in-progress attempt, not assumptions
that the remote side lacks later state. After a PHP timeout or process death, reset the local
state to retry and begin again with reservation. The receiver tells the client whether upload
or completion remains necessary.

This makes the three HTTP requests one recoverable operation from the client/UI perspective.

### Retry schedule

Suggested base schedule with random jitter:

```text
15 minutes
1 hour
6 hours
24 hours
daily thereafter until success or a permanent error
```

Do not abandon after seven days. Receiver availability is intentionally allowed to be
intermittent.

Respect `Retry-After` for `429` and `503`. Treat connection failures, timeouts, `408`, `425`,
`429`, `500`, `502`, `503` and `504` as retryable. Classify exact permanent cases in shared
contract tests rather than treating every `4xx` identically.

Examples:

- `400` invalid manifest: permanent until client bug/update;
- `401` invalid credential: attempt controlled re-registration/recovery once, then surface;
- `409` UUID/hash conflict: permanent and visible;
- `413` too large: permanent for this transport; keep local payload and explain;
- expired R2 URL: retry reservation;
- R2 upload `5xx`: retry reservation/upload;
- completion timeout: retry from reservation, not blind re-upload.

### Cron runner

- schedule a real WordPress cron hook for due outbox items;
- process a bounded number per invocation, initially one, to protect shared hosting;
- use an option/transient or database lease to prevent concurrent workers handling one row;
- an admin visit may schedule/nudge due work but must not upload synchronously in page render;
- manual Retry Now uses the same worker function and state machine;
- analysis completion never waits for submission.

## 13. Historical backfill

Add a Share-tab workflow:

> Submit existing unsubmitted analysis runs

Before creating anything, show:

- completed runs by type and date;
- estimated shareable versus incomplete counts;
- profile blobs already pruned;
- estimated compressed bytes;
- existing queued/sent runs;
- a reminder that the exact privacy-safe payload can be previewed.

Backfill rules:

1. one persistent `run_uuid` per historical run;
2. no outbox item if a receipt already exists for that run;
3. create items in bounded batches;
4. support baseline, spot, deep, cache-impact, adhoc and checkout runs;
5. include failed checkout partial evidence when valid and clearly marked;
6. label unavailable historical plugin versions/relationships instead of guessing;
7. if `profile_blob` has been pruned, export remaining safe scalar evidence with explicit
   missing-evidence markers;
8. pause/resume safely;
9. never mix evidence from different runs to make a historical item appear complete.

The current Submit Now control becomes Retry/Submit queued items. It should not rebuild a
mutable latest-run schema-1 payload.

## 14. Current run-type integration work

### Baseline and normal spot

Export every profile and associated component, finding and Excimer record from that run.

### Deep analysis

Export impacts created by the run with explicit baseline/test relationships and
measurement-time plugin versions. Determine the exact local relationship model during
implementation; do not infer it from current active plugins at export time.

### Cache impact

Export the coherent cache comparison run, not only the latest component saved percentage.

### Adhoc page analysis

Replace URL-derived public identity with safe page classification and opaque evidence UUID.
The local URL may continue to support the local report but is never exported.

### Checkout

Export ordered step profiles, flow notes reduced to safe enums/numbers, payment-boundary
timing, rolled-up Excimer/component evidence and explicit incomplete steps. Build from the
completed run and profile blobs, not from temporary queue options which may already be gone.

### Activation/deactivation spot prompt

Persist the transient's plugin slug/action into run context before deleting it. Export it as
an after-toggle observation. A future true before/after spot method gets a new measurement
version/evidence class.

### Future run types

Adding a run type requires:

- a safe exporter for its new evidence;
- a namespaced evidence type/version;
- measurement-method version;
- privacy fixtures;
- size fixture;
- Share preview labelling.

It does not require the receiver processor to be deployed first.

## 15. Share UI and consent

The Share tab should show:

- current opt-in state and consent text/version;
- queued, retrying, sent and permanent-failure counts;
- oldest pending and next retry times;
- outbox rows with run type/date, payload versions, sizes, hash and receipt;
- exact JSON preview for an unsent or locally retained sent item;
- Retry Now, Cancel Unsent and historical backfill controls;
- field/privacy documentation by evidence type;
- clear distinction between local analysis retention and community archival.

Turning opt-in off:

- stops creation of new outbox items;
- stops automatic attempts for unsent items until the owner chooses whether to cancel or
  resume them;
- does not silently request deletion of already receipted evidence;
- deletion of accepted evidence is a separate authenticated action and policy.

Agents/abilities may submit already opted-in queued data but may not enable sharing.

### Per-run sharing

The site owner must be able to contribute one analysis without adopting the site-wide setting.
The History tab therefore carries a per-run control naming what it would send - "Share this
checkout analysis", "Share this deep analysis", "Share this full scan" and so on - for every
run type, plus the queue state of runs already queued.

- `SSPA_Community_Outbox::share_run()` queues one run with `consent_scope = 'manual'`;
- `SSPA_Community_Outbox::due()` is the single authority on what may leave the site, and with
  the site-wide setting off it returns `manual` rows only;
- an opted-out site that previously accumulated automatic rows delivers only the runs the owner
  has since chosen, and the rest stay put with their bytes intact;
- a manual payload records `client.consent_version` as the version in force at the click, not
  the site-wide option, which is `0` on a site that never accepted the blanket setting;
- the ability surface still cannot enable either scope.

## 16. Code structure

Suggested classes:

```text
includes/community/
  class-sspa-community-identity.php
  class-sspa-community-exporter.php
  class-sspa-community-privacy.php
  class-sspa-community-schema.php
  class-sspa-community-outbox.php
  class-sspa-community-client.php
  class-sspa-community-worker.php
  class-sspa-community-backfill.php
  exporters/
    class-sspa-export-site.php
    class-sspa-export-page-profile.php
    class-sspa-export-excimer.php
    class-sspa-export-findings.php
    class-sspa-export-deep.php
    class-sspa-export-cache.php
    class-sspa-export-checkout.php
    class-sspa-export-adhoc.php
    class-sspa-export-toggle-spot.php
```

Keep transport, export and privacy concerns separate:

- exporter reads local evidence and produces a PHP array;
- privacy/schema validator accepts or rejects that array;
- canonical encoder produces immutable JSON/gzip/hash;
- outbox stores bytes and state;
- client performs HTTP operations;
- worker owns retry/state transitions.

Deprecate `SSPA_Anonymiser` and the synchronous responsibilities in `SSPA_Submitter` after
schema-1 compatibility tests are no longer needed.

## 17. Tests

Follow the plugin's `.tests/README.md` harness and add focused unit fixtures where useful.

### Identity, encoding and outbox

- existing runs receive stable unique run UUIDs;
- one terminal run creates one outbox row only when opted in;
- canonical encoding produces identical bytes across retries;
- gzip and SHA-256 match fixed fixtures;
- HMAC strings/signatures match receiver Go fixtures byte-for-byte;
- local body remains after every retryable failure;
- body clears only after matching archived receipt;
- concurrent cron/manual attempts cannot submit the same row twice logically.

### Direct R2 protocol

- reserve/upload/complete happy path;
- expired URL obtains a fresh reservation URL;
- upload success plus lost completion request resumes at completion;
- lost completion response returns the same receipt;
- receiver/R2 outage schedules retry without affecting analysis;
- different hash for the same UUID becomes a visible permanent failure;
- signed headers and gzip bytes reach the development R2 fixture unchanged;
- no R2 credentials or presigned URLs enter logs/history.

### Real runs, not just fixtures

Payload-building tests that insert their own run rows cannot prove that an analysis a site
actually performs ends up queued. Drive `SSPA_Run_Controller::start()` for every run type with
sharing on and assert each reaches a terminal state, queues exactly one outbox row, and carries
the evidence that run type exists to produce.

This is not optional coverage. Fixture-built inventories differ from real ones, and the first
run of this test found that the privacy gate rejected every real payload of every type on any
site whose database is named `wordpress` - the WordPress.org and Docker default - because
`DB_NAME` was scanned as a raw substring and matches the `wordpress-core` sentinel slug that
appears in every payload. No fixture test could have found it; the live production smoke test
did not, because its synthetic inventory holds a single `woocommerce` entry.

### Evidence coverage

- baseline includes all run profiles/components/findings/Excimer evidence;
- spot includes only its coherent run;
- deep includes scoped impacts and relationships;
- cache impact contains the full comparison;
- adhoc contains no URL or URL-derived public key;
- checkout contains steps, payment boundary and rollups without transactional identifiers;
- activation and deactivation runs preserve safe toggle context;
- failed checkout partial evidence is explicitly incomplete;
- pruned historical blobs generate explicit gaps, not invented zeroes.

### Privacy

Fixtures must attempt to leak:

- domain and complete URL;
- email and IP address;
- raw SQL with literal values;
- order/user/product/session IDs;
- cookies, headers, tokens and form values;
- absolute filesystem paths;
- HTTP query parameters and response body;
- exception/free-text content.

Each fixture fails outbox creation and identifies the local evidence path. The exact payload
preview test confirms allowed fixtures contain none of the prohibited values.

### Versioning and future compatibility

- plugin release may change without payload/evidence version changing;
- payload minor/major versions serialise distinctly;
- evidence v2 can coexist with evidence v1;
- new unknown evidence can be exported and archived before receiver support;
- receiver processor 1.0 marks a 1.1 payload partial/unsupported as appropriate;
- later processor reprocesses only the 1.1/evidence-v2 subset;
- measurement-version changes are present even when JSON shape is unchanged.

### Consent and UI

- opt-out creates/sends nothing;
- opt-in is never enabled by upgrade;
- disabling sharing stops unsent work;
- agent ability cannot opt in;
- preview exactly matches stored uncompressed JSON;
- backfill count/progress/resume and duplicate avoidance work.

## 18. Delivery sequence

### P1: identity, coherent exporter and fixtures

- [x] Add run UUID and measurement-version migration.
- [x] Define payload/evidence schema and version registry.
- [x] Implement share-safe exporters for every current run type.
- [x] Persist plugin-toggle context at run creation.
- [x] Add privacy and exact-payload preview tests.
- [x] Add dedicated fixtures for every current run type, including complete and partial
  checkout evidence.

Exit: every current run type can produce one locally validated privacy-safe payload without
sending it.

### P2: durable outbox

- [x] Add outbox/events schema and migration.
- [x] Create immutable gzip/hash rows on opted-in terminal runs.
- [x] Integrate pruning protection.
- [x] Add Share UI state/preview and manual queueing.
- [x] Add cron lease and retry scheduling.

Exit: payloads survive reloads, cron delays and network outages unchanged.

### P3: direct R2 submission client

- [x] Implement production registration with TLS verification.
- [x] Implement reservation/completion HMAC contract.
- [x] Implement opaque presigned `PUT` with exact signed headers/body.
- [x] Implement idempotent recovery from every remote state.
- [x] Persist and verify the receipt; retain the local body for the initial release.
- [x] Pass live development-receiver/R2 round-trip fixtures.
- [x] Add a fixed PHP/Go HMAC cross-language fixture in addition to the proven live exchange.

Exit: one queued item reliably receives a matching permanent receipt.

### P4: backfill and operational UI

- [x] Inventory historical runs and pruned evidence.
- [x] Build resumable bounded backfill.
- [x] Add Retry Now, non-destructive Pause/Resume and detailed safe errors.
- [x] Update ability behaviour to queue without enabling opt-in.
- [x] Update field and privacy copy for the operational queue UI.
- [x] Add consent versioning and the operational controls described above.

Exit: existing analysis history can be queued without duplication or mixed provenance.

### P5: compatibility release

- [x] Run receiver/R2 integration suite and the complete 20-case plugin suite; all 20 plugin
  cases pass, including the receiver/community submission and checkout-flow cases.
- [x] Test schema/evidence unsupported and later targeted reprocessing selection.
- [x] Verify by source audit and live runs that secrets, raw payloads and presigned URLs do
  not enter client outbox events or receiver/worker logs.
- [x] Pass the production collector smoke test and the outage-recovery case with the real PHP
  client (`.tests/manual/live-production.php`, 16/16 on 2026-08-08).
- [x] Confirm the archival outcome on the receiver itself rather than from the local receipt.
- [x] Bump to `0.12.0` and write both changelogs, because collection schema/behaviour changes
  materially.
- [ ] Commit, push, deploy and release `0.12.0`.

Exit: explicitly opted-in sites continuously accumulate current and future evidence without a
manual Submit Now dependency.

## 19. Release acceptance criteria

- [x] Every current run type has an explicit privacy-safe exporter and fixture.
- [x] Excimer functions/components/phases are represented rather than omitted.
- [x] Checkout, adhoc, cache and deep runs are coherent independent submissions.
- [x] Toggle spot context is honest about after-only versus paired evidence.
- [x] No outgoing field is cleaned by relying on the receiver.
- [x] Exact gzip bytes and hash are stable across all retries.
- [x] Receiver downtime leaves local outbox items intact and analysis unaffected.
- [x] Direct R2 upload uses only a short-lived exact-key URL.
- [x] Receipt UUID/hash are verified before local payload removal.
- [x] Unknown future evidence can be archived before a receiver processor exists.
- [x] Version metadata permits selective reprocessing of only newly supported evidence.
- [x] Historical backfill is resumable and does not guess missing provenance.
- [x] TLS verification is enabled and secrets/presigned URLs never enter logs.

## 20. Launch checks, all completed 2026-08-08

Do not rewrite the working submission system. The launch sequence below is done; what is
recorded here is what each step actually produced, so a later change can be checked against it.

1. `https://collector.superspeedy.org/healthz` returns `{"status":"ok"}` through Cloudflare
   proxying, not DNS-only origin.
2. The full disposable suite passes: 20 case files, 471 assertions, zero failures, run from
   bash against the docker environment as `.tests/README.md` requires. Note the harness gotcha
   that bit this session - `docker/env.sh` must be sourced from **bash**; sourced from zsh,
   `BASH_SOURCE` is unset, `PLUGIN_DIR` resolves to `wp-content` and `sync_plugin` silently
   copies the wrong tree into the container.
3. `.tests/manual/live-production.php` is the production-only variant. It accepts only the exact
   host `collector.superspeedy.org` with no port, requires the positional opt-in token
   `production`, and prints the submission UUID, receipt UUID and SHA-256 but never the
   installation secret or presigned URL. The opt-in is a bare word rather than `--production`
   because wp-cli parses any leading-dash argument as a flag belonging to `eval-file` itself and
   errors with `unknown --production parameter`. It also pauses any unrelated queued outbox
   items for the duration, so a populated local queue cannot be flushed to the production
   archive as a side effect of a smoke test.
4. Run from the disposable Docker site: the outbox reached `sent`, the receipt UUID was stored
   and the immutable hash still matched. Confirmed on the receiver with the signed status
   endpoint: `storage_status=archived`, `processing_status=partial`, matching receipt UUID and
   payload hash.
5. Outage recovery is not a separate manual exercise; it is phase one of the same script, so it
   is repeated on every future release check. The payload is queued while the collector is
   genuinely unreachable - the same host on discard port 9, a real connection failure with no
   mocking - and the script asserts the run completed, the item sat in `retry` with its exact
   bytes and hash intact, and no receipt was invented. Restoring the production URL then gets
   the same submission UUID and hash a verified receipt on attempt 2.
6. Share-tab consent and exact-payload preview reviewed, `0.12.0` changelog written in both
   `readme.txt` and `.changelog-full.md`, plugin version and `Stable tag:` bumped, complete
   suite rerun.

7. Added after the above, when a real-run integration test was finally written: the privacy
   gate rejected every real payload of every run type on any site whose database is called
   `wordpress`, because `DB_NAME` was scanned as a raw substring and matches the
   `wordpress-core` sentinel present in every payload. Fixed by
   `SSPA_Community_Privacy::distinctive_db_name()`, with case 20 regressions and case 23 as
   the coverage that would have caught it. Every run type then queued: adhoc 2.7 KB, spot
   2.7 KB, cache analysis 4.0 KB, checkout 13.3 KB, baseline 28.2 KB (28 page profiles), deep
   52.4 KB (78 plugin impacts).
8. Per-run sharing added so an owner can contribute one analysis without the site-wide setting,
   verified in the browser against the disposable site with sharing off.

9. One REAL analysis of every run type was then delivered to the production collector from the
   disposable site via `.tests/manual/live-production-runs.php`, using the per-run consent path
   with site-wide sharing off throughout. All six were permanently archived with an explicit
   `processing_status=partial`, the expected outcome for schema 1.1 under processor 1.0:

```text
adhoc         run #305   2,653 gzip     15,380 uncompressed     7 evidence records
spot          run #300   2,911 gzip     16,993 uncompressed     6 evidence records
cache_impact  run #301   4,063 gzip     30,825 uncompressed    15 evidence records
checkout      run #302  14,225 gzip    156,012 uncompressed    59 evidence records
baseline      run #303  29,035 gzip    364,352 uncompressed   137 evidence records
deep          run #304  52,845 gzip    707,172 uncompressed   287 evidence records
```

These are the first realistic size percentiles for section 9's limits: the largest real payload
is 52.8 KB compressed against a 32 MiB compressed ceiling, so there is no near-term pressure for
a multipart schema. Record real-site percentiles after release, since a large production site
will exceed a disposable test store considerably.

Remaining before customers see this: commit, push, deploy and release `0.12.0`.

No receiver, R2 or Cloudflare secret is required for any plugin step. Registration against the
production collector uses a new random installation identity and secret generated inside the
disposable WordPress database.
