#!/usr/bin/env bash
# Build the GitHub release's super-speedy-performance-analysis.zip.
#   .build/build-github.sh <dest_dir> [--smoke]
# Every GitHub release must carry this asset under exactly this name (Dave, 19 November 2026):
# the public diagnosis and plugin-updates skills install from
# releases/latest/download/super-speedy-performance-analysis.zip, and installed copies' update
# checker downloads it. It is the whole (Pro) tree in the plain folder, with the plain Plugin Name
# and the plain updater slug, which is what installed copies run.
# This is the ONLY asset a GitHub release carries. Pro is a paid plugin since 24 November 2026
# (Dave): super-speedy-performance-analysis-pro.zip is never attached to a GitHub release, and the
# assets already on old releases are left as they are.
set -uo pipefail
_h="$(cd "$(dirname "$0")" && pwd)"
source "$_h/build.sh"
SRC="$(cd "$_h/.." && pwd)"
DEST="${1:?usage: build-github.sh <dest_dir> [--smoke]}"
ZIP=$(ssx_build_target "$SRC" "$DEST" "super-speedy-performance-analysis" "super-speedy-performance-analysis.zip" ssx_hook_github) || exit 1
ssx_log "built $ZIP"
echo "$ZIP"
if [ "${2:-}" = "--smoke" ]; then "$_h/smoke-test.sh" "$ZIP" github || exit 1; fi
exit 0
