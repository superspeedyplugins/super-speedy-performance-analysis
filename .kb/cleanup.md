# KB cleanup: Super Speedy Performance Analysis

Run 14 October 2026 against the live site (superspeedyplugins.com), parent term
`super-speedy-performance-analysis` (kb_category 4651).

## Starting state

| Term | ID | Articles |
|---|---|---|
| super-speedy-performance-analysis (parent) | 4651 | Quick Start Guide (1695043), Understanding Your Results (1695045), Controlling ... with MCP (1695047) |
| features | 4652 | Archive Query Profile (1695051), Function-Level Profiling (1694531) |
| troubleshooting | 4655 | Security Plugins and Profiling Requests (1695041) |
| setup-server | 4656 | Installing the Optional Profiling Extras (1695049) |
| advanced | 4657 | Methodology (1695039) |

There was no `faq` term and no `getting-started` child term. No `getting-started` kb_category
term exists for any plugin on the site, and the other plugins' Troubleshooting/FAQ guides sit on
the parent term only, so the new guides follow that actual convention (parent term 4651 only).

## Articles created

Both were created as **drafts**. Publishing through MCP was refused by the agent's permission
classifier, so Dave publishes them.

| Post ID | Action | Slug | Edit link |
|---|---|---|---|
| 1695504 | Created draft: Troubleshooting (merged from 1695041, sections given anchors, cross-link to FAQ) | `troubleshooting` | https://www.superspeedyplugins.com/wp-admin/post.php?post=1695504&action=edit |
| 1695505 | Created draft: Frequently Asked Questions (from the readme.txt FAQ plus the plugin header requirements; cross-link to Troubleshooting) | `frequently-asked-questions` | https://www.superspeedyplugins.com/wp-admin/post.php?post=1695505&action=edit |

The Quick Start Guide (1695043) already exists on the parent term and was left unchanged.

## Order of operations for Dave

1. Delete article 1695041 and term 4655 (below) **before** publishing 1695504. The
   Troubleshooting guide's URL, `/kb/super-speedy-performance-analysis/troubleshooting/`, is the
   same path as term 4655's archive, so while the term exists the guide is likely shadowed.
2. Publish 1695504 and 1695505, then confirm both slugs stayed clean (no `-2`).

## Articles safe to DELETE

| Term | Post ID | Title | Merged into |
|---|---|---|---|
| troubleshooting (4655) | 1695041 | Security Plugins and Profiling Requests | 1695504 Troubleshooting |

Terms to delete afterwards: **4655** (`troubleshooting`).

Consider a redirect from
`/kb/super-speedy-performance-analysis/troubleshooting/security-whitelisting/` to
`/kb/super-speedy-performance-analysis/troubleshooting/#security-plugins-block-profiling`.

## Slug collisions

- `troubleshooting`: collides with the term 4655 archive path until that term is deleted (see above).
- None created with a `-N` suffix: terms were attached at creation.

## Discord mining

No Discord export JSON is present in `.kb/`, so no candidates were mined.

## Not part of this cleanup, noticed in passing

These `.kb/` files have no matching live article. Some may be developer-only by design; decide
per file and publish through `kb-publishing` if wanted:

- `ajax-profiling.md`
- `geolocation-speed-boosts.md`
- `agent-api.md`, `agent-instructions-openai.md` (developer/agent reference)
- `getting-started.md` (may be the source of the live Quick Start Guide; check before publishing)
