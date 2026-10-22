#!/usr/bin/env bash
# Browser journey for the AJAX tab's observed endpoints and "Profile this endpoint".
#   bash .tests/browser/run-endpoint-profile.sh
# Defaults to the endpoint fixture (installed by prepare-endpoint-profile.php). To run it against
# another real GET endpoint on a site that has it, for example Super Speedy Search Fast REST:
#   SSPA_ENDPOINT_ROUTE=sspsss/v1/suggestions/ SSPA_ENDPOINT_MATCH=suggestions \
#   SSPA_ENDPOINT_PLACEHOLDER='{searchstring}' bash .tests/browser/run-endpoint-profile.sh
set -uo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
sspa_require_site || exit 1
case "$SSPA_SITE_DIR" in "$SITES_ROOT"/*) ;; *) echo 'Refusing non-isolated site' >&2; exit 1;; esac
cli eval-file "$PLUGIN_DIR/.tests/browser/prepare-endpoint-profile.php" || exit 1
export SSPA_E2E_URL="$SSPA_SITE_URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS"
export SSPA_ENDPOINT_ARTIFACT_DIR="${SSPA_ENDPOINT_ARTIFACT_DIR:-$PLUGIN_DIR/.data/endpoint-profile-browser}"
echo "Endpoint profile browser journey: $SSPA_SITE_URL"
timeout 900 node "$PLUGIN_DIR/.tests/browser/endpoint-profile.e2e.cjs"
