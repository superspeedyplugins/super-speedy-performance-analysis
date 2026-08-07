#!/usr/bin/env bash
# Tear down the SSPA docker test environment. Pass --keep-data to preserve containers.
set -euo pipefail
source "$(dirname "${BASH_SOURCE[0]}")/env.sh"

docker rm -f $SSPA_WP $SSPA_DB $SSPA_REDIS >/dev/null 2>&1 || true
docker network rm $SSPA_NET >/dev/null 2>&1 || true
echo "removed"
