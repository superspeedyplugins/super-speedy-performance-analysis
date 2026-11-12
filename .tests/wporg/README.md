# WordPress.org Plugin Check

Run `php .tests/wporg/pcp-validator.php` from the repository root. It checks that the real report validator rejects
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
same edition transformation, saving `diagnostic-wporg.zip` under
`.data/wporg-review/diagnostic` without running Plugin Check. It is not a release or
handoff: build that with `.build/build-wporg.sh`, which also runs Plugin Check.
`SSPA_PCP_TAG` gives concurrent PCP runs separate retained sites.
