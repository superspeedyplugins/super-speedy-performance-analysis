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

# A killed history-comparison case must not leave its deliberate REST slowdown
# armed for an unrelated later suite run.
cli option delete sspa_history_fixture_armed --quiet 2>/dev/null || true
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
START_AT="${SSPA_START_AT:-}"
FAILED=0
RAN=0
FAILED_NAMES=""

for case_file in "$PLUGIN_DIR"/.tests/cases/*.php; do
    name=$(basename "$case_file")
    if [ -n "$START_AT" ] && [[ "$name" < "$START_AT" ]]; then
        continue
    fi
    if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then
        continue
    fi
    # These core fixtures require wasteful checkout calls / no settings publisher.
    # The integration case requires SPro loaded normally in the new CLI process.
    case "$name" in
        19-checkout-flow.php|37-component-state.php)
            if cli plugin is-active scalability-pro >/dev/null 2>&1; then
                cli plugin deactivate scalability-pro || exit 1
            fi
            ;;
        72-fast-ajax-spro.php)
            cli plugin activate scalability-pro || exit 1
            ;;
    esac
    RAN=$((RAN + 1))
    echo "=== $name ==="
    output=$(cli eval-file "$case_file" 2>&1)
    case_exit=$?
    echo "$output"
    if [ "$case_exit" -ne 0 ] || echo "$output" | grep -q '^FAIL' || ! echo "$output" | grep -q '^PASS'; then
        FAILED=$((FAILED + 1))
        FAILED_NAMES="$FAILED_NAMES $name"
        echo "--- $name FAILED ---"
    fi
done

if [ -z "$FILTER" ] || [[ "admin-tabs-browser" == *"$FILTER"* ]]; then
    RAN=$((RAN + 1))
    if ! bash "$PLUGIN_DIR/.tests/browser/run-admin-tabs.sh"; then
        FAILED=$((FAILED + 1))
        FAILED_NAMES="$FAILED_NAMES admin-tabs-browser"
    fi
fi

echo
if [ -z "$FILTER" ] || [[ "share-preview-browser" == *"$FILTER"* ]]; then
    RAN=$((RAN + 1))
    if ! bash "$PLUGIN_DIR/.tests/browser/run-share-preview.sh"; then
        FAILED=$((FAILED + 1))
        FAILED_NAMES="$FAILED_NAMES share-preview-browser"
    fi
fi
if [ -z "$FILTER" ] || [[ "fast-ajax-browser" == *"$FILTER"* ]]; then
    RAN=$((RAN + 1))
    if ! bash "$PLUGIN_DIR/.tests/browser/run-ajax-profile.sh"; then
        FAILED=$((FAILED + 1))
        FAILED_NAMES="$FAILED_NAMES fast-ajax-browser"
    fi
fi
if [ -z "$FILTER" ] || [[ "history-tooltips-browser" == *"$FILTER"* ]]; then
    RAN=$((RAN + 1))
    if ! bash "$PLUGIN_DIR/.tests/browser/run-history-tooltips.sh"; then
        FAILED=$((FAILED + 1))
        FAILED_NAMES="$FAILED_NAMES history-tooltips-browser"
    fi
fi
if [ "$RAN" -eq 0 ]; then echo "No cases matched: $FILTER" >&2; exit 1; fi
echo "$RAN case file(s) run, $FAILED failed"
[ -n "$FAILED_NAMES" ] && echo "failed:$FAILED_NAMES"
exit $FAILED
