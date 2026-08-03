# Community sharing and the rules feed

<!-- internal -->
The hub site superspeedy.org DOES NOT EXIST YET (`.docs/superspeedy-org-hub-launch.md`, status
"not started"). Everything below is built in the plugin and works against a local/dev hub, but
the public destination is not live. Do not describe the public rankings as available.

### Opt-in anonymised sharing
**Since:** 0.6.0, 11 July 2026

Sharing results is opt-in. The Share tab shows the **exact payload before anything is sent**:
metric medians per generic page type, per-plugin attribution, measured isolation deltas,
findings with normalised query fingerprints, plugin slugs and versions, and bucketed site
sizes. Never shared: the domain, URLs, raw SQL, emails or customer data. The site is a random
ID.

### Signed submissions
**Since:** 0.6.0, 11 July 2026

Install registration with per-install secrets, every submission HMAC-signed, with submission
history on the Share tab. Secrets are stored per hub URL (since 0.7.1) so a development hub and
the public hub never see each other's credentials.

### Signed community rules feed
**Since:** 0.6.0, 11 July 2026

Recommendation texts, thresholds, plugin categories, sector signatures and fragile lists can
improve without a plugin update. The feed is RSA-signed and verified before anything trusts it;
a tampered feed is ignored and the bundled snapshot (`rules/rules-snapshot.json`) applies.

### Transport hardening
**Since:** 0.7.1, 11 July 2026

Submissions and the rules feed use `?rest_route=` URLs, which work regardless of the hub's
permalink or trailing-slash redirect setup. Pretty `/wp-json/` URLs 301 on some hosts, which
silently broke POST submissions.

### Companion hub plugin
**Since:** 0.6.0, 11 July 2026

Ships in the repo's `hub/` folder. Receives and quarantines submissions, flattens measured
impacts for future public rankings, and serves the signed rules feed. Destined for
superspeedy.org.
