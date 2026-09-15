#!/usr/bin/env bash
# Retained, explicitly approved optional-tool matrix. Never targets host WordPress.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
DOCKER="${SSPA_MATRIX_DOCKER:-docker}"
OUT="$ROOT/.data/capability-matrix"
mkdir -p "$OUT"
for row in absent blocked available; do
    container="sspa-capability-$row"
    "$DOCKER" inspect "$container" >/dev/null
    "$DOCKER" exec "$container" php /usr/local/bin/wp --allow-root --path=/var/www/html eval-file /matrix/probe.php > "$OUT/$row.log" 2>&1
    cat "$OUT/$row.log"
    grep -q '^PASS: capability matrix row$' "$OUT/$row.log"
    "$DOCKER" exec "$container" cat /evidence/detection.json > "$OUT/$row.json"
    "$DOCKER" exec "$container" cat /evidence/tools.html > "$OUT/$row.html"
done
"$DOCKER" image inspect sspa-capability-matrix:20260915 > "$OUT/image.json"
git -C "$ROOT" rev-parse HEAD > "$OUT/commit.txt"
echo 'PASS: complete capability matrix'
