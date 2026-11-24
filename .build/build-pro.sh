#!/usr/bin/env bash
# Build the Pro edition, for superspeedyplugins.com only. Paid since 24 November 2026: never attach
# this zip to a GitHub release (.build/build-github.sh builds the one GitHub asset).
#   .build/build-pro.sh <dest_dir> [--smoke]
# Folder and zip: super-speedy-performance-analysis-pro. The full tree, nothing stripped.
set -uo pipefail
_h="$(cd "$(dirname "$0")" && pwd)"
source "$_h/build.sh"
SRC="$(cd "$_h/.." && pwd)"
DEST="${1:?usage: build-pro.sh <dest_dir> [--smoke]}"
ZIP=$(ssx_build_target "$SRC" "$DEST" "$PRO_SLUG" "${PRO_SLUG}.zip" ssx_hook_pro) || exit 1
ssx_log "built $ZIP"
echo "$ZIP"
if [ "${2:-}" = "--smoke" ]; then "$_h/smoke-test.sh" "$ZIP" pro || exit 1; fi
exit 0
