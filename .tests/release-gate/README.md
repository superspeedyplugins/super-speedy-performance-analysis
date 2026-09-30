# AJAX release evidence

Run `php .tests/release-gate/run.php` from the repository root. The suite executes
the real validator and build entrypoints in synthetic Git repositories. It substitutes
WordPress discovery responses and the final archive-staging function so it never
needs a live site or produces a ZIP. Assertions cover real command execution,
missing/stale evidence, source and runtime changes, altered logs, advancing core,
pending SQL approval and both edition entrypoints stopping before staging.

This gate reuses the established plugin execution-evidence engine and its retained
JSON/log contract. The PA adapter uses collector-active and collector-inactive
coverage, checks the actual PA checkout and existing test-runner configuration, and
has no private-issue API calls. PA is public. Private issue declarations are refused.

## WordPress.org Plugin Check

The same contract runner also executes `pcp.php`: the real report validator rejects
unknown warnings, all errors (including errors with an allowed warning code), and
malformed/incomplete reports. It verifies the staged readme keeps its current release
and following sections while linking the complete source changelog.

`.build/build-wporg.sh` runs `.build/pcp-check.sh` against its archive before reporting
success. The checker installs that ZIP on a dedicated retained native site, uses Plugin
Check 2.1.0 with its runtime CLI bootstrap, and runs every available check, including
experimental checks, at severity 1. Raw strict JSON, the complete check inventory,
versions, command, exit status and archive SHA-256 stay in `.data/wporg-review/pcp`.
The actual early CLI runner must select the complete static/runtime inventory.
Unexpected checker stderr, a changed archive or installed files differing from the
archive fail before the result validator runs.
It does not use AI false-positive filtering or check/code exclusions.

Only these WARNING codes are accepted: `WordPress.DB.DirectDatabaseQuery.DirectQuery`,
`WordPress.DB.DirectDatabaseQuery.NoCaching`, `WordPress.DB.DirectDatabaseQuery.SchemaChange`,
`WordPress.DB.SlowDBQuery.slow_db_query_meta_key`, and
`WordPress.DB.SlowDBQuery.slow_db_query_meta_value`. Profiling requires live database
observations, owned schema changes and metadata lookups. Preparation, escaping and
SQL security findings are never permitted. Any other type or code fails the build.

For iterative review before source is committed, `.build/diagnose-wporg.sh` uses the
same edition transformation and full checker, saving `diagnostic-wporg.zip` under
`.data/wporg-review/diagnostic`. This deliberately uncertified artifact is not a
release or handoff: only the normal build entrypoint checks the release evidence
and approval. `SSPA_PCP_TAG` gives concurrent PCP runs separate retained sites.

## Record the actual checks

Use a retained isolated test site with the required endpoint-controller companion.
Set `SSPA_SCENARIO` as described by the normal test README. Configure:

- `SSPA_GATE_SITE_PATH`: that scenario's existing WordPress directory.
- `SSPA_GATE_SPRO_ROOT`: the companion checkout used by the site.
- `SSPA_GATE_PARALLEL_DEV_ROOT`: the shared harness checkout.
- `SSPA_GATE_METADATA_ROOT`: the private plan checkout.
- `SSPA_GATE_METADATA_REPOSITORY`: its GitHub owner/repository identity.

The recorder verifies that `.tests/env.sh` resolves the same site and harness. It
also verifies the actual PA and companion plugin paths in WordPress. The source,
submodules and companion checkout must be committed and clean. Only the shared
harness uses an explicit runtime-file fingerprint, allowing unrelated dirty docs.

```bash
php .build/validate-feature-release-gate.php --record-tests
```

This runs cases 40–43, the fast-ajax filter (59, 71–73 and the chart browser), and
boot-export case 70 through the normal runner. It requires each command's successful
exit and specified assertion output, including no writes while inactive and a real
request event while active. It never enables an endpoint or shares data itself;
normal tests control their synthetic fixtures on the retained site.

A failed or interrupted run invalidates previous success. Successful evidence binds
HEAD/tree, recursive submodules, dependency revisions, actual WordPress/PHP/plugin
versions and command/log hashes. Changing source or dependency commits requires a new
run. Local evidence is a maintainer execution record, not a cryptographic guarantee
against an administrator deliberately forging files. CI executes the commands anew.

## Release approval

`--manifest-only` checks structure and grants no release readiness. Both existing
full and wordpress.org build drivers invoke `.build/validate-feature-release-gate.php`
before sourcing the staging manifest. The wp.org driver additionally requires its full
Plugin Check result before reporting a successful build. The shared build library is unchanged.

The strict check fetches current `origin/main`, requires it to be an ancestor without
feature merge commits, verifies current private metadata, and requires these lines
in the declared SQL review after the maintainer has actually approved that commit:

```text
SQL-Approval: approved
SQL-Approved-By: Dave Hilditch
SQL-Approved-Commit: <tested PA commit>
```

The approval lives in separate private metadata, so recording it does not change the
tested PA commit. The recorder can run before approval; the strict check cannot pass
without it. No approval is generated automatically.

```bash
php .build/validate-feature-release-gate.php
```

## CI prerequisite

The workflow needs a maintainer-controlled Linux runner labelled
`ssp-native-wordpress`, its native WordPress test environment, PHP CLI 7.4+, Node,
browser dependencies and the configured paths above. It also needs the metadata
repository variable and read-only `SSPA_GATE_METADATA_DEPLOY_KEY` secret. It refuses
fork branches before entering that private runtime environment.

This source change does not provision a runner, credentials or branch protection.
Until they exist, the required job cannot produce passing runtime evidence. Configure
branch protection to require the feature release check before merge. No unverified
or uploaded passing placeholder substitutes for executing the tests.
