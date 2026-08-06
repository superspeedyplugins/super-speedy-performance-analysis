# Download URL audit - every place still pointing at the protected zip path

**DO NOT COMMIT THIS DOC - this repo is PUBLIC.** It discusses the private zip-hosting
path, and the related server-side exposure findings (reported to Dave in chat, 2026-08-06)
must never appear in a public repo. Keep local, or move to a private repo's .docs.

Status: audit, 2026-08-06. Fixed so far: README.md (GitHub Release links) and the
`.update-server/` seed's download_url (now the auth endpoint).

The direct zip path on superspeedyplugins.com is protected and must never be handed out:
`GET /assets/plugins/super-speedy-performance-analysis.zip` returns **404**. Dave's rule:
public download links point at `/download/` - a PHP endpoint that generates the zip - and
the free edition's public download is the GitHub Release asset
(`releases/latest/download/super-speedy-performance-analysis.zip`, verified 200).

What the probes established (all curl-checked 2026-08-06):

| URL | Status | Meaning |
|---|---|---|
| `/assets/plugins/<slug>.zip` | 404 | Protected. Never link it |
| `/assets/plugins/<slug>.json` | 200 | Update-checker metadata feed works |
| `/assets/plugins/<slug>/readme.txt` (this plugin) | 404 | Not deployed for this plugin |
| `/assets/plugins/scalability-pro/readme.txt` | 200 | Shape is right; deployed plugins have it |
| `/download/super-speedy-performance-analysis/` | 200, text/html | A page, not the zip generator |
| `/download/<slug>.zip` | 404 | Not the generator shape either |

**RESOLVED - the correct endpoint shape** (from surveying every other plugin's live JSON,
2026-08-06): a download_url must be the auth server's authorising PHP endpoint,

```
https://auth.superspeedyplugins.com/wp-json/secure-wpi/v1/download/<slug>
```

never a raw zip. Every properly deployed plugin (scalability-pro, super-speedy-search,
-filters, -imports, -emails, -ajax-prices, -chat, -sitemaps, -coming-soon) uses exactly this.
Bare GETs return 403 (application/json) for known-good slugs too - it authorises via the
query parameters the update checker appends. The `/download/<slug>/` page on
superspeedyplugins.com is the product landing page, not the generator.

**Fleet survey of `/assets/plugins/<slug>.json`** (live, 2026-08-06): correct on the nine
plugins above; **seed-grade (v0.1) with never-deployed versions**: super-speedy-listings,
super-speedy-comments, and this plugin (the only one whose download_url was also a raw zip);
**no JSON served at all** (updates silently fail if these use the PUC path):
super-speedy-blocks, -compare, -coupons, -slugs, -contact, -discord, -knowledge-base,
-surveys, -welcome. The settings-sync skill's server-side JSON check exists for exactly this.

## THE BIG ONE: the live update channel for this plugin is broken

The deployed metadata at `/assets/plugins/super-speedy-performance-analysis.json` is still
the 0.1 SEED file (verified live: `version: 0.1`, `download_url` = the protected
`/assets/plugins/<slug>.zip`, which 404s). Two consequences:

1. Installed sites (0.10.9) compare against version 0.1 and will NEVER be offered an update.
2. Even if the version were bumped, the download_url 404s, so the update would fail anyway.

Fix: deploy a real JSON with the current version and the auth-server download_url
(`https://auth.superspeedyplugins.com/wp-json/secure-wpi/v1/download/super-speedy-performance-analysis`).
The repo's `.update-server/` seed now carries that URL (fixed 2026-08-06); the auth server
must also know the slug - as a FREE plugin it presumably needs an ungated rule there (see the
wc-product-launch skill's auth-server wiring notes). Also deploy
`/assets/plugins/super-speedy-performance-analysis/readme.txt` so the settings module's
changelog fetch works (it 404s today; scalability-pro's equivalent works).

## Locations handing out the protected zip path (user-facing, fix to a working link)

| Location | What it says | Fix |
|---|---|---|
| `readme.txt:28` | Install step: "install the zip from .../assets/plugins/<slug>.zip" | GitHub Release asset URL (or /download/) |
| `SKILL.md:20` | `wp plugin install https://.../assets/plugins/<slug>.zip --activate` | GitHub Release asset URL - agents follow this verbatim and it 404s today |
| `.kb/getting-started.md:9` | Same wp plugin install line | Same fix; note the LIVE KB article on superspeedyplugins.com was published from this source and needs re-publishing after |
| `.docs/agent-instructions-openai.md:13` | Same wp plugin install line | Same fix |
| `.update-server/super-speedy-performance-analysis.json:6` | Seed `download_url` = protected zip | Point at /download/ or the GitHub asset; this seed is what deployment copies live |

## super-speedy-settings submodule (shared across ALL plugins - fix via settings-sync)

These are the shared module's phone-home URLs. The metadata/readme shapes WORK for deployed
plugins, so the only change Dave has called for here is where a *download* is concerned:
whatever hands a zip to the user must use `/download/`, never `/assets/plugins/<slug>.zip`.

| Location | What it does | State |
|---|---|---|
| `super-speedy-settings/class-ssp-mcp.php:230` | MCP install ability reads `/assets/plugins/<slug>.json`, then installs from its `download_url` | The JSON fetch is fine; the *seed JSONs'* download_url values are the problem. Confirm every deployed `<slug>.json`'s download_url points at /download/, not the raw zip |
| `super-speedy-settings/super-speedy-settings-core.php:172` | Update checker (PUC) reads `/assets/plugins/<slug>.json` | Same: the JSON path is fine, its download_url content must be /download/ |
| `super-speedy-settings/super-speedy-settings-core.php:612` | Changelog fetch `/assets/plugins/<plugin>/readme.txt?v=` | Shape works for deployed plugins (scalability-pro 200); this plugin's copy is not deployed |
| `super-speedy-settings/super-speedy-settings-core.php:688` | Same, uncached variant | Same |

Any change here is a submodule change: edit in one plugin, commit + push the submodule, then
propagate with the settings-sync skill (which also checks every plugin's `<slug>.json`
exists on the server - exactly the failure this plugin currently has).

## Non-code mentions (documentation only, lower priority)

| Location | Context |
|---|---|
| `super-speedy-performance-analysis.php:40,44` | Comment names the metadata path; the URL used is the JSON feed (works). Only the comment's framing needs nothing - no zip is referenced. No change needed unless the metadata path itself is considered private |
| `.docs/superspeedy-org-hub-launch.md:14` | Historic note that the update checker lives at that path. Doc-only |
| `.update-server/README.md:4` | Describes the JSON feed location. Doc-only, internal convention doc |

## Already fixed

- `README.md` (repo front page): all three download references now point at the GitHub
  Release asset (`releases/latest/download/super-speedy-performance-analysis.zip`),
  curl-verified 200. Release v0.10.9 exists with the zip attached.

## Suggested order

1. Dave confirms the `/download/` endpoint shape.
2. Deploy a corrected live JSON (current version + working download_url) and the readme.txt
   copy - this un-breaks updates for every installed copy.
3. Sweep the five user-facing locations (readme.txt, SKILL.md, KB source + live KB article,
   OpenAI doc, .update-server seed).
4. Audit the OTHER plugins' deployed `<slug>.json` download_url values for the same raw-zip
   mistake, since the settings module install path serves whatever those files say.
