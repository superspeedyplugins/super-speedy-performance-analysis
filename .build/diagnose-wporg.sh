#!/usr/bin/env bash
# Uncertified diagnostic package for iterative review. Never a release/handoff command.
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
source "$here/build.sh"
root="$(cd "$here/.." && pwd)"
out="$root/.data/wporg-review/diagnostic"
mkdir -p "$out"
echo 'DIAGNOSTIC ONLY: release evidence and approval have not been verified.' >&2
zip=$(ssx_build_target "$root" "$out" "$PLUGIN_SLUG" "diagnostic-wporg.zip" ssx_hook_wporg)
printf '%s\n' "$zip"
sha256sum "$zip"
