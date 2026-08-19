#!/usr/bin/env bash
# .build/lib.sh — shared build helpers for Super Speedy plugins.
# Keep IDENTICAL across plugins. Sourced by .build/build.sh and the build drivers.
# (This is plugin-agnostic — copy verbatim; nothing here mentions a specific plugin.)

ssx_log() { echo "    [build] $*" >&2; }
ssx_die() { echo "    [build] ERROR: $*" >&2; exit 1; }

# ssx_rm <staging> <path...>  — delete files/dirs from the staging tree.
ssx_rm() {
    local stage="$1"; shift
    local p
    for p in "$@"; do
        rm -rf "${stage:?}/${p}"
        ssx_log "removed ${p}"
    done
}

# ssx_build_target <SRC> <OUT_DIR> <PLUGIN_FOLDER> <ZIP_NAME> <hook_fn>
#   SRC            pristine source tree (the working copy / clone)
#   OUT_DIR        directory the zip is written to
#   PLUGIN_FOLDER  folder name inside the zip (== the install slug)
#   ZIP_NAME       output zip filename
#   hook_fn        transform fn; receives the staging dir (empty for a no-op/paid build)
# Prints the absolute zip path on success.
ssx_build_target() {
    local SRC="$1" OUT_DIR="$2" PLUGIN_FOLDER="$3" ZIP_NAME="$4" hook="${5:-}"
    mkdir -p "$OUT_DIR"
    local work; work="$(mktemp -d)"
    local stage="${work}/${PLUGIN_FOLDER}"

    # Stage a copy named for the install slug. Exclude .git up front (huge); every
    # other dot-folder/-file is stripped AFTER the hook so the hook can still read
    # them if needed.
    mkdir -p "$stage"
    rsync -a --exclude='.git' "$SRC"/ "$stage"/

    # Per-target transform (deletions etc.).
    if [ -n "$hook" ]; then "$hook" "$stage" || ssx_die "hook '$hook' failed"; fi

    # Strip every dot-prefixed file/dir at any depth (.build .tests .docs .kb .ai
    # .server .git* .vscode …) so none reach the shipped zip.
    find "$stage" -name '.*' -prune -exec rm -rf {} + 2>/dev/null

    # This machine-local config is gitignored but not dot-prefixed, so the
    # generic dot-path removal above does not catch it.
    rm -f "$stage/wp-cli.local.yml"

    local zip="${OUT_DIR}/${ZIP_NAME}"
    rm -f "$zip"
    ( cd "$work" && zip -rqX "$zip" "${PLUGIN_FOLDER}/" ) || ssx_die "zip failed"
    rm -rf "$work"
    echo "$zip"
}
