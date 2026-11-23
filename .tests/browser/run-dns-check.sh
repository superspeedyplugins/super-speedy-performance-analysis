#!/usr/bin/env bash
# Pro DNS check browser journey on its own site (tests-dns-check). Run case 165 first or not:
# this clears case 165's fixture resolvers on the way in and uses the server's real DNS.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-dns-check}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
sync_plugin || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
out="$PLUGIN_DIR/.data/dns-check/$(date +%Y%m%d-%H%M%S)"
mkdir -p "$out"
cli option delete sspa_test_dns_nameservers >/dev/null 2>&1
# No saved result on the way in, so the journey can only pass on the result its own click produced.
cli option delete sspa_dns_check >/dev/null 2>&1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS" SSPA_E2E_OUT="$out"
echo "DNS check browser journey: $SSPA_SITE_URL"
timeout 180 node "$PLUGIN_DIR/.tests/browser/dns-check.e2e.cjs" 2>&1 | tee "$out/journey.log"
result=${PIPESTATUS[0]}
echo "Evidence: $out"
exit "$result"
