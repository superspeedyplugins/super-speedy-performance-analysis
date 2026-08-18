#!/usr/bin/env bash
# .build/build.sh - the per-plugin build MANIFEST for super-speedy-performance-analysis.
#
# This plugin has NO free/paid split. It is free everywhere; the only difference between the
# two editions is the distribution channel's update mechanism:
#
#   full   - superspeedyplugins.com + the GitHub release asset. Carries the
#            super-speedy-settings submodule, which supplies the shared Super Speedy admin
#            menu and the plugin-update-checker (PUC) library.
#   wporg  - full MINUS super-speedy-settings. wordpress.org forbids a bundled updater that
#            phones home, and PUC is exactly that.
#
# There is therefore no ssx_hook_free here and no edition marker: nothing is deleted for
# feature reasons, only for wp.org policy reasons.
#
# The main plugin file already tolerates the submodule's absence - the require is behind
# file_exists() and the update checker behind class_exists(PucFactory) - so the wp.org build
# is a deletion plus assertions, with no shim required. `assert_menu_fallback` below is what
# stops that from silently becoming untrue.

set -uo pipefail

_bh="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib.sh
source "$_bh/lib.sh"

PLUGIN_SLUG="super-speedy-performance-analysis"
PLUGIN_MAIN="${PLUGIN_SLUG}.php"

# ---------------------------------------------------------------------------
# wp.org edition
# ---------------------------------------------------------------------------
ssx_hook_wporg() {
    local stage="$1"

    # The whole reason this edition exists. The submodule carries:
    #   - plugin-update-checker (PUC)          -> a phone-home updater, banned on wp.org
    #   - super-speedy-settings-core.php       -> builds update checkers, same problem
    #   - the shared Super Speedy admin menu   -> the plugin falls back to its own top-level
    #                                             menu when this is absent
    ssx_rm "$stage" "super-speedy-settings"

    # Deleting the library is not enough: PCP's "plugin updater detected" check is a string
    # scan, so the class_exists('...PucFactory') guard in the main file is itself an ERROR.
    # Strip the marked block, which is the require + the buildUpdateChecker call.
    ssx_strip_block "$stage/$PLUGIN_MAIN" 'SSPA-SELFHOSTED-UPDATER-START' 'SSPA-SELFHOSTED-UPDATER-END'

    # The submodule pointer is meaningless without the submodule, and .gitmodules is a
    # PCP "hidden files" error in its own right. (Dot-files are stripped by ssx_build_target
    # anyway; this is belt and braces in case that ever changes.)
    ssx_rm "$stage" ".gitmodules"

    ssx_assert_no_updater "$stage"
    ssx_assert_menu_fallback "$stage"
    ssx_assert_versions_match "$stage"
}

# ssx_strip_block <file> <start_marker> <end_marker>
# Deletes the marker lines and everything between them. Fails loudly if the markers are
# missing or unbalanced, so a refactor that drops them breaks the build instead of quietly
# shipping the block.
ssx_strip_block() {
    local file="$1" start="$2" end="$3"
    [ -f "$file" ] || ssx_die "ssx_strip_block: no such file: $file"
    local s e
    s=$(grep -c "$start" "$file"); e=$(grep -c "$end" "$file")
    [ "$s" = "1" ] && [ "$e" = "1" ] \
        || ssx_die "ssx_strip_block: expected exactly one $start and one $end in $(basename "$file"), found $s/$e"
    perl -i -ne "print unless /\\Q$start\\E/ .. /\\Q$end\\E/" "$file" \
        || ssx_die "ssx_strip_block failed on $file"
    php -l "$file" >/dev/null || ssx_die "$(basename "$file") does not parse after stripping $start"
    ssx_log "stripped block $start..$end from $(basename "$file")"
}

# ---------------------------------------------------------------------------
# Safety assertions - these FAIL the build rather than shipping something wrong
# ---------------------------------------------------------------------------

# Nothing that phones home for updates may survive in the wp.org zip.
ssx_assert_no_updater() {
    local stage="$1" hit
    [ -e "$stage/super-speedy-settings" ] && ssx_die "super-speedy-settings survived the wp.org build"

    # Dot-paths (.build, .tests, .changelog-full.md) are stripped by ssx_build_target AFTER
    # this hook, so they never ship - scanning them here would be a false positive.
    # No exemption for the main file: the marked block is stripped, so not even the
    # class_exists() guard may remain. PCP's check is a string scan and errors on the name.
    hit=$(find "$stage" -name '.*' -prune -o -type f -print 2>/dev/null \
          | xargs grep -lE 'PucFactory|plugin-update-checker|buildUpdateChecker|Puc[0-9]?v[0-9]' 2>/dev/null || true)
    [ -n "$hit" ] && ssx_die "updater references survived in the wp.org build:"$'\n'"$hit"
    ssx_log "assert: no bundled updater, not even a guarded reference"
}

# Deleting the submodule removes the shared Super Speedy admin menu. The plugin is supposed
# to fall back to registering its own top-level menu. If that fallback is ever refactored
# away, the wp.org edition would install with no admin page at all - so prove it is present.
ssx_assert_menu_fallback() {
    local stage="$1" page="$stage/includes/admin/class-sspa-admin-page.php"
    [ -f "$page" ] || ssx_die "admin page class missing from the build"
    grep -q 'add_menu_page' "$page" \
        || ssx_die "no add_menu_page() fallback in class-sspa-admin-page.php - the wp.org zip would have no admin menu"
    ssx_log "assert: top-level admin menu fallback present"
}

# PCP errors on a Stable tag / header Version mismatch, and Dave wants zero PCP errors.
ssx_assert_versions_match() {
    local stage="$1" header readme
    header=$(grep -m1 -E '^\s*\*\s*Version:' "$stage/$PLUGIN_MAIN" | sed -E 's/.*Version:[[:space:]]*//' | tr -d '\r')
    readme=$(grep -m1 -E '^Stable tag:' "$stage/readme.txt" | sed -E 's/^Stable tag:[[:space:]]*//' | tr -d '\r')
    [ -n "$header" ] || ssx_die "could not read Version from $PLUGIN_MAIN"
    [ "$header" = "$readme" ] \
        || ssx_die "Stable tag ($readme) != header Version ($header) - PCP fails on this"
    ssx_log "assert: version $header == stable tag $readme"
}

# The full edition gets the same version check; it has nothing else to verify.
ssx_hook_full() {
    ssx_assert_versions_match "$1"
    [ -f "$1/super-speedy-settings/super-speedy-settings.php" ] \
        || ssx_die "super-speedy-settings is missing - run: git submodule update --init"
    ssx_log "assert: super-speedy-settings present"
}
