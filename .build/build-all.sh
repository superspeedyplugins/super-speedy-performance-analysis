#!/usr/bin/env bash
# Build both editions (Pro and wordpress.org) plus the GitHub release asset (.build/build-github.sh).
#   .build/build-all.sh <dest_dir> [--smoke]
set -uo pipefail
_h="$(cd "$(dirname "$0")" && pwd)"
DEST="${1:?usage: build-all.sh <dest_dir> [--smoke]}"
SMOKE="${2:-}"
SRC="$(cd "$_h/.." && pwd)"
if [ -n "$(git -C "$SRC" status --porcelain 2>/dev/null)" ]; then
    echo "    [build] WARNING: working tree is dirty - this zip is not reproducible from a commit" >&2
fi
"$_h/build-pro.sh"   "$DEST" "$SMOKE" || exit 1
"$_h/build-wporg.sh" "$DEST" "$SMOKE" || exit 1
"$_h/build-github.sh" "$DEST/github" "$SMOKE" || exit 1
