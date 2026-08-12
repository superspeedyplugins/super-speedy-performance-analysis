# Community sharing, site cohorts and the rules feed

<!-- internal -->
Read this before writing any copy from this doc. **superspeedy.org's public side does not exist
yet.** What DOES exist and is verified working end to end is the collector: the client registers,
reserves, uploads directly to R2 and completes, and a real submission was archived and processed
(`.docs/performance-analysis-submission-implementation.md`, verified 2026-08-08). What does not
exist is any public page - no rankings, no per-plugin performance profiles, no methodology page.
Do not describe public rankings as available. The receiver work for 0.18.0's cohorts and
order-management flow is also still outstanding on the superspeedy.org side.

Also removed, do not describe as current: the **bundled WordPress hub plugin** that used to ship
in `hub/`. It was deleted in commit d1e0b1d ("Remove obsolete schema 1 hub harness") along with
the schema-1 round-trip test and the local-hub harness. The server side is now a separate Go
receiver plus R2, not a WordPress plugin in this repo.

### Opt-in anonymised sharing
**Since:** 0.6.0, 11 July 2026

Sharing is opt-in, stays off until you opt in, and an update never turns it on. Turning it off
stops new payloads and delivery attempts without deleting anything already archived. Never
shared: the domain, URLs, raw SQL, emails or customer data. The site is a random ID.

### Two ways to share, presented separately
**Since:** 0.12.0, 9 August 2026

Share every analysis automatically, or pick individual analyses and share only those. A "Share
this" control sits on every analysis in the History tab, so you can contribute a single full
scan, plugin impact analysis, page analysis, cache analysis, spot check or checkout analysis
without switching sharing on for everything else.

### See exactly what gets sent
**Since:** 0.13.0, 10 August 2026

A "See exactly what gets sent" button builds the payload for your latest analysis, explains in
plain English what it contains and what it never contains, and lets you download the file.
Nothing is queued or sent. The Share tab also previews the exact JSON each queued payload would
send, with its compressed size, SHA-256 and receipt.

### A durable background outbox
**Since:** 0.12.0, 9 August 2026

Each completed run becomes its own versioned anonymised payload in a durable queue that survives
reloads and outages and retries until the archive confirms it is stored. Analysis runs never
wait on the network, so the archive being unreachable cannot slow down or fail a run.

Delivery is driven by your browser while you are on the analysis screen rather than by WP-Cron,
which many hosts disable; cron remains a backstop. Queued submissions are claimed atomically, so
two background workers can never deliver the same payload twice, and a submission interrupted by
a PHP timeout waits for its scheduled retry instead of becoming due again immediately.
Per-payload `Retry now`, `Pause` and `Resume` controls replaced the old Submit Now button, which
rebuilt a payload from scratch on every press. `Queue existing runs` shares the history you
already have, in bounded resumable batches, skipping runs already queued or archived.

### What travels: every run type, as one coherent run
**Since:** 0.12.0, 9 August 2026

Plugin impact analyses, cache analyses, checkout flows, ad-hoc page profiles, plugin-toggle spot
checks and excimer function, component and phase data are all shared, each as one coherent run
rather than a mixture of the latest profiles and unrelated history. Ad-hoc page profiles are
shared as a page classification and an opaque identifier, so no URL or URL-derived key leaves
the site.

### Registration, signing and direct upload
**Since:** 0.6.0, 11 July 2026 (direct upload 0.12.0)

Install registration with per-install secrets and HMAC-signed submissions, with submission
history on the Share tab. Secrets are stored per collector URL (since 0.7.1) so a development
collector and the public one never see each other's credentials. Payloads upload straight to the
community archive using a short-lived single-object URL issued by superspeedy.org, and TLS
verification is always on - a collector that is not verified HTTPS is refused.

### Site characteristics: what kind of site this is
**Since:** 0.18.0, 12 August 2026

Shared analyses describe what kind of site this is - an online shop, a jobs board, a publisher,
or honestly more than one of them - with the signals that led to the label, so superspeedy.org
can compare your results against sites genuinely like yours instead of against a single average.
The classifier is deterministic: the same counts and the same plugin list give the same primary
label every time, with no randomness and no closest-guess tie-breaking. The coarse signals travel
with the label so a receiver can reclassify later.

<!-- internal -->
Vocabulary is deliberate and worth preserving in copy. "Site characteristics" is the user-facing
idea; "site cohort dimensions" is what the receiver groups by; **never "demographics"** - these
are characteristics of a website, not of people, and the old word invites exactly the wrong
assumption about what is collected. `SSPA_Demographics` keeps its name as the local metrics
collector only.

### Sizes as bands, never exact counts
**Since:** 0.6.0, 11 July 2026 (extended 0.18.0)

Site sizes are shared as bands and never exact counts. Since 0.18.0 they cover pages, total
orders, orders in the last 30 days, comments and how many plugins are active, alongside the
posts, products, users and database size already sent. The ladder is decimal (10, 100, 1k up to
1b) and versioned as a contract, so a band string means the same thing in a payload sent a year
apart.

Order counts use safe routes only: the all-time total comes from WooCommerce's own order table
estimate or WordPress's maintained count, and from **nothing at all** on a posts table too large
to count cheaply. The 30-day figure is a real count of that window and stays accurate however
busy the shop is - it is bounded by its own WHERE clause on an indexed date column under both
HPOS and legacy storage.

<!-- internal -->
Worth knowing: the 30-day count used to fetch up to N order ids and count them, so a shop doing
40,000 orders a month came back as the cap and banded as "<10k". Not imprecise - wrong, and
wrong about precisely the busiest stores in the cohort. Fixed in 0.18.0.

### Order management travels separately from checkout
**Since:** 0.18.0, 12 August 2026

A checkout analysis shares your order-management time - opening the order and marking it
completed - as its own record with its own total. It was measured but discarded on the way out
before. Customer checkout totals are unchanged and still contain no admin time. The Share tab
explains both, and what the bands mean.

### Reactions are shared as pairs
**Since:** 0.17.4, 12 August 2026

Shared analyses carry which plugin was excluded alongside which plugin reacted, so these pairs
can build a community dependency map. The refused statement itself is never shared, only its
fingerprint.

### Signed community rules feed
**Since:** 0.6.0, 11 July 2026

Recommendation texts, thresholds, plugin categories, sector signatures and fragile lists can
improve without a plugin update. The feed is RSA-signed and verified before anything trusts it;
a tampered feed is ignored and the bundled snapshot (`rules/rules-snapshot.json`) applies. Since
0.12.0 a failed fetch backs off for 12 hours instead of retrying every hour.

### Transport hardening
**Since:** 0.7.1, 11 July 2026

Submissions and the rules feed use `?rest_route=` URLs, which work regardless of the collector's
permalink or trailing-slash redirect setup. Pretty `/wp-json/` URLs 301 on some hosts, which
silently broke POST submissions.

### Privacy check no longer mistakes versions for IP addresses
**Since:** 0.12.0, 9 August 2026

Sharing was refused outright on any site running a plugin with a four-part version number, e.g.
3.0.83.3, which the privacy check read as an IP address. Affected sites queued nothing at all;
their existing analyses can be sent with `Queue existing runs`.
