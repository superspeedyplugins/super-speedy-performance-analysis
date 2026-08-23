# The community plugin database

Per-plugin evidence profiles at [superspeedy.org](https://superspeedy.org/plugins/), built from anonymised results shared by real sites.

## What it's for

"Is this plugin slow?" has no measured answer anywhere. There are opinions, there are benchmarks of one plugin on one empty test site, and there's whatever the plugin's own sales page says. None of that tells you what a plugin costs on a store like yours.

The community database is the measured version: many sites, each running the same analysis, grouped so a comparison means something.

## Sharing is opt-in and stays off

It's off until you switch it on, and an update never switches it on. Turning it off stops new payloads and delivery attempts without deleting anything already archived.

**Never shared:** your domain, your URLs, raw SQL, email addresses, customer data. Your site is a random ID.

**See exactly what gets sent** builds the payload for your latest analysis, explains in plain English what it contains and what it doesn't, and lets you download the file. Nothing is queued or sent by pressing it. The Share tab previews the exact JSON each queued payload would send, with its compressed size, SHA-256 and receipt.

## Two ways to share

Share every analysis automatically, or pick individual analyses from the History tab and share only those. That includes a single full scan, plugin impact analysis, page analysis, cache analysis, spot check or checkout analysis, without switching sharing on for everything else.

## What travels

Every run type goes as one coherent run rather than a mixture of latest profiles and unrelated history: plugin impact analyses, cache analyses, checkout flows, ad-hoc page profiles, plugin-toggle spot checks, and excimer function, component and phase data.

Ad-hoc page profiles travel as a page classification and an opaque identifier, so no URL or URL-derived key leaves the site. Sizes travel as bands rather than exact counts.

**Site characteristics** describe what kind of site yours is, an online shop, a jobs board, a publisher, or honestly more than one, with the signals that led to the label. That's what lets superspeedy.org compare your results against sites genuinely like yours instead of against a single average. The classifier is deterministic: the same counts and the same plugin list give the same label every time.

## How it's sent

Each install registers with a per-install secret and signs its submissions. Secrets are stored per collector URL, so a development collector and the public one never see each other's credentials. Payloads upload straight to the archive using a short-lived single-object URL issued by superspeedy.org, and TLS verification is always on. A collector that isn't verified HTTPS is refused.

Delivery runs through a durable background outbox with per-payload Retry, Pause and Resume. Queue existing runs shares the history you already have, in bounded resumable batches, skipping anything already queued or archived.

## What comes back

A signed rules feed. Recommendation texts, thresholds, plugin categories, sector signatures and fragile lists improve without a plugin update. The feed is RSA-signed and verified before anything trusts it. A tampered feed is ignored and the bundled snapshot applies.

## Requirements and limits

Nothing to install, and nothing happens until you opt in.

Coverage depends on how many sites have shared results for a given plugin, so check [superspeedy.org/plugins](https://superspeedy.org/plugins/) for the plugin you care about rather than assuming it's there.

---

Related: [[AI-Agents-and-MCP]] · [[Plugin-Impact-Analysis]] · [[Contributing-and-Reporting-a-Problem]]
