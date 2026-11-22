#!/usr/bin/env bash
# Capture the WordPress.org listing screenshots from the EXACT wp.org archive.
#
#   [OUT=<dir>] .wporg/capture-screenshots.sh <super-speedy-performance-analysis-X.Y.Z-wporg.zip>
#
# Resets wporg-listing.super-speedy-performance-analysis.localhost:8081 on the way IN
# (.wporg/listing-site.sh), then drives the real admin: two analyses (the first installs the profiler), a page
# profile, the cache check and Compare. Writes screenshot-N.png into $OUT (default
# .wporg/assets/). The site is left behind for inspection.
set -euo pipefail
ZIP="$(realpath "${1:?usage: capture-screenshots.sh <wporg zip>}")"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
"$HERE/listing-site.sh" "$ZIP"
. "$HOME/dev/super-speedy/tools/parallel-dev/bin/lib.sh"
export NODE_PATH="${NODE_PATH:-$ROOT/.tests/node_modules}"
export PD_ADMIN_USER="$ADMIN_USER" PD_ADMIN_PASS="$ADMIN_PASS"
OUT="${OUT:-$HERE/assets}"; mkdir -p "$OUT"
OUT="$OUT" BASE="http://wporg-listing.super-speedy-performance-analysis.localhost:$PORT" timeout 1800 node "$HERE/capture-screenshots.cjs"
echo "screenshots written to $OUT"
