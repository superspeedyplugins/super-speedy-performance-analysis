# superspeedy.org hub launch - outstanding work

Status: **not started**. The hub site does not exist yet. This doc records everything in the
analysis plugin that still points at `superspeedy.org`, why it was left pointing there, and
what has to happen when the hub goes live.

Design background: `.docs/brainstorm-superspeedy-org-companion.md` (hub architecture) and
`.docs/brainstorm-performance-analysis.md` section 3.9 (client side).

## Why it is split this way

As of 0.9.2 the plugin's **commercial** identity moved to superspeedyplugins.com: plugin
header `Plugin URI` / `Author URI`, the readme `Donate link`, the install instructions, and
the update checker (`/assets/plugins/super-speedy-performance-analysis.json`). That is where
people download it and where updates come from.

Its **community** identity is still superspeedy.org: the anonymised-submission hub, the
signed rules feed and the future public plugin rankings. Deliberately not moved, because
superspeedyplugins.com is the commercial brand and the community database is meant to read
as neutral, and because the hub receiver is not installed there. Repointing the client at
superspeedyplugins.com today would send submissions to a site with no `ssph/v1` routes.

## Touchpoints still on superspeedy.org

### Code (functional - changing these changes behaviour)

- `includes/class-sspa-submitter.php:14` - `hub_url()` default `https://superspeedy.org`.
  Overridable per install via the `sspa_hub_url` option, which is how dev testing works.
  Endpoints are built as `?rest_route=/ssph/v1/...` (pretty `/wp-json/` paths 301 on some
  hosts and kill POSTs).
- `includes/class-sspa-rules-feed.php:16-24` - the feed's RSA public key. Falls back to
  `rules/feed-pubkey.pem`, **which does not exist in the repo yet**; only
  `rules/rules-snapshot.json` ships. Until the production keypair exists there is no
  bundled key, so a signed feed cannot be verified without setting `sspa_rules_pubkey`
  by hand. `.tests/cases/11-community.php:31` does exactly that against the local hub.

### User-facing copy (says "superspeedy.org" as a future/community promise)

- `readme.txt:22` - description bullet on optional community sharing.
- `includes/admin/tabs/share.php:9,15` - Share tab intro and the opt-in checkbox label.
- `includes/admin/tabs/overview.php:128` - "Coming soon" note on the data-retention control.
- `includes/admin/js/sspa-admin.js:143` - the same "coming soon" note in the delete confirm.
- `includes/class-sspa-abilities.php:204` - label of the submit ability.

These are honest as written (the Share tab is opt-in and the retention notes say "coming
soon"), so they can stay until launch. They must all be reviewed in one pass at launch,
because at that point they stop being promises and start being descriptions.

### The hub plugin itself

- `hub/` holds the MVP companion plugin (`super-speedy-performance-hub.php`, plus
  `class-ssph-rest.php`, `class-ssph-schema.php`, `class-ssph-keys.php`). It lives inside
  this repo for now and is meant to graduate to its own repo before launch.
- `hub/super-speedy-performance-hub.php:4` - its own `Plugin URI` is superspeedy.org, which
  is correct: that plugin really does belong to the hub site.
- Dev harness: the hub is symlinked into the local superspeedy install and stood up at
  `localhost:8081`; `.tests/dev-local-hub.sh` points a docker analysis site at it and runs
  the full round trip. See `.tests/README.md`.

## Launch checklist

1. Stand up superspeedy.org (WordPress) and move `hub/` out to its own repo.
2. Generate the production feed keypair on the hub. Private key stays on the hub, commit the
   public key to this repo as `rules/feed-pubkey.pem` so verification works out of the box.
3. Publish the rules feed and confirm a clean install verifies it with only the bundled key,
   no `sspa_rules_pubkey` override.
4. Drop `'sslverify' => false` from the submitter and rules-feed HTTP calls
   (`class-sspa-submitter.php`, `class-sspa-rules-feed.php`). It exists for local dev hubs
   with self-signed certs; against a real hub it weakens transport security on exactly the
   requests that carry the install secret.
5. Confirm `hub_url()`'s default resolves and the `?rest_route=` form works on the live hub
   (that was the 0.7.1 fix, verified against localhost:8081, never against production).
6. Review the copy listed above: change "coming soon" to live wording, and decide whether the
   Share tab links to a methodology page and the public rankings.
7. Decide the promotion story: the brainstorm's position is that the plugin is promoted on
   superspeedy.org as the community brand while superspeedyplugins.com remains the commercial
   home. Since 0.9.2 the download and update path are firmly on superspeedyplugins.com, so
   the two sites need one agreed answer to "where do I get it".
8. `.docs/implementation-plan.md:313` - the superspeedy.org promotion and methodology pages
   are still an open item there too. Reconcile with this doc rather than duplicating it.

## Open decisions

- Does superspeedy.org host its own download of the plugin, or always link to
  superspeedyplugins.com? Affects whether a second update-metadata JSON is needed.
- Does the public repo ever go public? The 0.9.2 update-checker move was made because it is
  private. If it opens later, GitHub releases become an option again, but the update checker
  should probably stay on superspeedyplugins.com so download numbers stay visible.
- Phase 7 mail work (SMTP sink at `sink.superspeedy.org`) is parked and blocked on the same
  site existing. See `.docs/implementation-plan.md:250`.
