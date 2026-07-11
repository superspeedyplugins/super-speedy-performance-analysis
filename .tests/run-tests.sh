#!/usr/bin/env bash
# Run the SSPA test suite against the docker environment (starts it if needed).
# Usage: .tests/run-tests.sh [case-substring]
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/docker/env.sh"

if ! docker ps --format '{{.Names}}' | grep -qx $SSPA_WP; then
    "$PLUGIN_DIR/.tests/docker/up.sh" || exit 1
fi

sync_plugin

FILTER="${1:-}"
FAILED=0
RAN=0

for case_file in "$PLUGIN_DIR"/.tests/cases/*.php; do
    name=$(basename "$case_file")
    if [ -n "$FILTER" ] && [[ "$name" != *"$FILTER"* ]]; then
        continue
    fi
    RAN=$((RAN + 1))
    echo "=== $name ==="
    output=$(cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/cases/$name" 2>&1)
    echo "$output"
    if echo "$output" | grep -q '^FAIL' || ! echo "$output" | grep -q '^PASS'; then
        FAILED=$((FAILED + 1))
        echo "--- $name FAILED ---"
    fi
done

echo
echo "$RAN case file(s) run, $FAILED failed"
exit $FAILED
