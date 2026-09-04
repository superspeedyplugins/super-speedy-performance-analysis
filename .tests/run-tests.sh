#!/usr/bin/env bash
# Run the SSPA test suite against the native parallel-dev test site.
# Usage: .tests/run-tests.sh [case-substring]
#
# No Docker. The environment is created by .tests/setup-site.sh; this script only checks
# it is there and runs the cases against it.
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

sspa_require_site || exit 1
sync_plugin || exit 1

# A retained site can have a collection left running by a previous focused
# case. Clear that test state on the way in so early lifecycle cases do not
# inherit a duration conflict from an earlier run.
cli eval '$active = SSPA_Traffic_Collection::active(); if ( $active ) { SSPA_Traffic_Collection::stop( (int) $active["id"], true ); }' >/dev/null 2>&1 || exit 1

# Pre-flight: several cases silently degrade into failures (sector "general", tiny deep
# deltas, no write profiles) when the WooCommerce sample data has gone missing - reseed.
PRODUCTS=$(cli post list --post_type=product --post_status=publish --format=count 2>/dev/null | tr -dc '0-9')
if [ "${PRODUCTS:-0}" -lt 5 ]; then
    echo "sample products missing (${PRODUCTS:-0}) - re-running setup..."
    "$PLUGIN_DIR/.tests/setup-site.sh" || exit 1
fi

FILTER="${1:-}"
FAILED=0
RAN=0
FAILED_NAMES=""

for case_file in "$PLUGIN_DIR"/.tests/cases/*.php; do
    name=$(basename "$case_file")
    if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then
        continue
    fi
    RAN=$((RAN + 1))
    echo "=== $name ==="
    output=$(cli eval-file "$case_file" 2>&1)
    echo "$output"
    if echo "$output" | grep -q '^FAIL' || ! echo "$output" | grep -q '^PASS'; then
        FAILED=$((FAILED + 1))
        FAILED_NAMES="$FAILED_NAMES $name"
        echo "--- $name FAILED ---"
    fi
done

echo
echo "$RAN case file(s) run, $FAILED failed"
[ -n "$FAILED_NAMES" ] && echo "failed:$FAILED_NAMES"
exit $FAILED
