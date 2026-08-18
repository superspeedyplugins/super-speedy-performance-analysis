#!/usr/bin/env bash
# Build the wordpress.org edition (no bundled updater).
#   .build/build-wporg.sh <dest_dir> [--smoke]
set -uo pipefail
_h="$(cd "$(dirname "$0")" && pwd)"
source "$_h/build.sh"
SRC="$(cd "$_h/.." && pwd)"
DEST="${1:?usage: build-wporg.sh <dest_dir> [--smoke]}"
ZIP=$(ssx_build_target "$SRC" "$DEST" "$PLUGIN_SLUG" "${PLUGIN_SLUG}-wporg.zip" ssx_hook_wporg) || exit 1
ssx_log "built $ZIP"
echo "$ZIP"
if [ "${2:-}" = "--smoke" ]; then "$_h/smoke-test.sh" "$ZIP" wporg || exit 1; fi
exit 0
