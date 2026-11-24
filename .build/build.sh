#!/usr/bin/env bash
# .build/build.sh - the per-plugin build MANIFEST for super-speedy-performance-analysis.
#
# Two editions from one tree, built mostly by DELETION:
#
#   pro    - superspeedyplugins.com only (paid since 24 November 2026; never a GitHub release asset,
#            .build/build-github.sh builds that). Folder and zip
#            super-speedy-performance-analysis-pro. The full tree: the synthetic test customer,
#            minted measurement logins, every MCP/REST ability. Carries the super-speedy-settings
#            submodule (the shared Super Speedy menu and the plugin-update-checker, PUC), whose
#            update checker is registered under the -pro slug.
#   wporg  - wordpress.org, folder super-speedy-performance-analysis. The pro tree MINUS
#            super-speedy-settings (wp.org forbids a phone-home updater) and MINUS every
#            `// SSPA-PRO-START` ... `// SSPA-PRO-END` block: the code wordpress.org's review of
#            15 Nov 2026 refused (creating, updating, deleting and logging in users, the
#            error_reporting() read, abilities that change the site). The replacement path
#            follows each block and runs only once the block is gone. Guideline 5: absent,
#            never locked. Design: .docs/2026-11-16-pro-and-wporg-editions.md.
#
# The main plugin file tolerates the submodule's absence - the require is behind
# file_exists() and the update checker behind class_exists(PucFactory) - so the menu fallback
# needs no shim. `assert_menu_fallback` below is what stops that from silently becoming untrue.

set -uo pipefail

_bh="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Release entrypoints validate evidence before sourcing this staging manifest.
# shellcheck source=lib.sh
source "$_bh/lib.sh"

PLUGIN_SLUG="super-speedy-performance-analysis"
PRO_SLUG="${PLUGIN_SLUG}-pro"
PLUGIN_MAIN="${PLUGIN_SLUG}.php"

# ---------------------------------------------------------------------------
# wp.org edition
# ---------------------------------------------------------------------------
# The source header says "... Pro". The wordpress.org edition and the GitHub plain zip carry the
# plain name wordpress.org approved.
ssx_plain_name() {
    local main="$1/$PLUGIN_MAIN"
    perl -i -pe 's{^(\s*\*\s*Plugin Name:\s*Super Speedy Performance Analysis) Pro(\r?)$}{$1$2}' "$main" \
        || ssx_die "Plugin Name rewrite failed"
    grep -qE '^\s*\*\s*Plugin Name:\s*Super Speedy Performance Analysis\r?$' "$main" \
        || ssx_die "Plugin Name is not 'Super Speedy Performance Analysis'"
    ssx_log "plain Plugin Name"
}

# The GitHub release's plain-named zip: the whole tree in the plain folder.
ssx_hook_github() {
    ssx_plain_name "$1"
    ssx_hook_full "$1"
}

ssx_hook_wporg() {
    local stage="$1"
    ssx_plain_name "$stage"

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

    # Everything wordpress.org refused, marked in place in the source. Deleted whole.
    ssx_strip_pro_blocks "$stage"
    # Pro's panel wordmark; the wordpress.org panels carry no credit (review round 1).
    ssx_rm "$stage" "includes/admin/img"
    # Pro's DNS check; its call sites are SSPA-PRO blocks.
    ssx_rm "$stage" "includes/class-sspa-dns-check.php" "includes/admin/partials/dns-check.php"
    # Pro's update watchdog; its call sites (bootstrap, Compare settings and badge, abilities) are
    # SSPA-PRO blocks.
    ssx_rm "$stage" "includes/class-sspa-watchdog.php" "includes/admin/js/sspa-watchdog.js"

    # The submodule pointer is meaningless without the submodule, and .gitmodules is a
    # PCP "hidden files" error in its own right. (Dot-files are stripped by ssx_build_target
    # anyway; this is belt and braces in case that ever changes.)
    ssx_rm "$stage" ".gitmodules"
    ssx_rm "$stage" "SKILL.md" "README.md"
    php "$_bh/wporg-readme.php" "$stage/readme.txt" || ssx_die "wp.org readme preparation failed"
    # WordPress installs files, not empty directory entries. Keep the archive tree identical.
    find "$stage" -depth -type d -empty -delete || ssx_die "wp.org empty-directory cleanup failed"

    ssx_assert_no_updater "$stage"
    ssx_assert_no_pro_code "$stage"
    ssx_assert_no_watchdog "$stage"
    ssx_assert_menu_fallback "$stage"
    ssx_assert_history_chart "$stage"
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

# ssx_strip_pro_blocks <stage>
# Deletes every `// SSPA-PRO-START` ... `// SSPA-PRO-END` block (marker lines included) from every
# PHP and JS file. Blocks never nest; a file whose markers are unbalanced or nested fails the
# build, and every touched PHP file must still parse.
ssx_strip_pro_blocks() {
    local stage="$1" file n=0
    while IFS= read -r file; do
        php -r '
            $lines = file($argv[1]);
            $depth = 0;
            foreach ($lines as $i => $line) {
                if (preg_match("#^\\s*//\\s*SSPA-PRO-START\\b#", $line)) { if ($depth) { fwrite(STDERR, "nested SSPA-PRO-START at line " . ($i + 1) . "\n"); exit(1); } $depth = 1; }
                elseif (preg_match("#^\\s*//\\s*SSPA-PRO-END\\b#", $line)) { if (!$depth) { fwrite(STDERR, "SSPA-PRO-END without START at line " . ($i + 1) . "\n"); exit(1); } $depth = 0; }
            }
            if ($depth) { fwrite(STDERR, "unclosed SSPA-PRO-START\n"); exit(1); }
        ' "$file" || ssx_die "unbalanced SSPA-PRO markers in ${file#"$stage"/}"
        perl -i -ne 'print unless m{^\s*//\s*SSPA-PRO-START\b} .. m{^\s*//\s*SSPA-PRO-END\b}' "$file" \
            || ssx_die "stripping SSPA-PRO blocks failed on ${file#"$stage"/}"
        case "$file" in
            *.php) php -l "$file" >/dev/null || ssx_die "${file#"$stage"/} does not parse after stripping SSPA-PRO blocks" ;;
            *.js) if command -v node >/dev/null 2>&1; then node --check "$file" || ssx_die "${file#"$stage"/} does not parse after stripping SSPA-PRO blocks"; fi ;;
        esac
        n=$((n + 1))
    done < <(grep -rlE '^\s*//\s*SSPA-PRO-START' "$stage" --include='*.php' --include='*.js' 2>/dev/null | grep -v '/\.')
    [ "$n" -gt 0 ] || ssx_die "no SSPA-PRO blocks found - the wp.org edition would ship the pro code"
    ssx_log "stripped SSPA-PRO blocks from $n files"
}

# ---------------------------------------------------------------------------
# Safety assertions - these FAIL the build rather than shipping something wrong
# ---------------------------------------------------------------------------

# What wordpress.org's review of 15 Nov 2026 refused must be absent from the wp.org zip, not
# merely unreachable: user creation, editing, deletion and login, the error_reporting() read,
# and every ability that changes the site.
ssx_assert_no_pro_code() {
    local stage="$1" hit
    hit=$(find "$stage" -name '.*' -prune -o -type f \( -name '*.php' -o -name '*.js' \) -print 2>/dev/null \
          | xargs grep -nE 'SSPA-PRO-(START|END)|wp_(insert|update|delete|create)_user\s*\(|wp_set_current_user\s*\(|wp_generate_auth_cookie\s*\(|wp_set_auth_cookie\s*\(|wp_signon\s*\(|[^_a-z]error_reporting\s*\(|ensure_test_customer|fresh_test_customer|Powered by %1|auto_enable\(|sspa_autoload_figures' 2>/dev/null || true)
    [ -n "$hit" ] && ssx_die "pro-only code survived in the wp.org build:"$'\n'"$hit"
    for ability in set-profiler update-settings advance-run cancel-run run-analysis run-deep-analysis run-checkout-flow \
                   profile-url profile-admin-save run-cache-check start-ajax-window profile-endpoint process-submissions \
                   manage-submission prune-stored-captures delete-traffic-collection cleanup-workflow-objects \
                   send-quote-request prepare-quote-request start-traffic-collection stop-traffic-collection submit-results \
                   run-parameter-check advance-parameter-check advance-cache-check stop-ajax-window compare-ajax-windows \
                   set-history-expectation update-watchdog-settings run-watchdog-now get-watchdog-status; do
        grep -rqE "'$ability'" "$stage/includes/class-sspa-abilities.php" "$stage/includes/class-sspa-abilities-control.php" \
            && ssx_die "write ability $ability survived in the wp.org build"
    done
    ssx_log "assert: no user creation, login, error_reporting() or write abilities"
}

# The update watchdog is Pro: absent from the wp.org zip, not switched off.
ssx_assert_no_watchdog() {
    local stage="$1" hit
    hit=$(find "$stage" -name '.*' -prune -o -type f \( -name '*.php' -o -name '*.js' \) -print 2>/dev/null \
          | xargs grep -nliE 'watchdog' 2>/dev/null || true)
    [ -n "$hit" ] && ssx_die "update watchdog code survived in the wp.org build:"$'\n'"$hit"
    ssx_log "assert: no update watchdog code"
}

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

ssx_assert_history_chart() {
    local stage="$1" file
    for file in echarts-history.min.js LICENSE-echarts.txt NOTICE-echarts.txt LICENSE-zrender.txt; do
        [ -s "$stage/includes/admin/vendor/$file" ] \
            || ssx_die "History chart asset missing or empty: includes/admin/vendor/$file"
    done
    grep -q 'window.SSPAECharts' "$stage/includes/admin/vendor/echarts-history.min.js" \
        || ssx_die "History chart bundle does not expose the pinned ECharts adapter"
    ssx_log "assert: local History chart bundle and licence notices present"
}

# The Pro edition: the whole tree under its own slug, plus the customer agent guide.
ssx_hook_pro() {
    local stage="$1" main="$1/$PLUGIN_MAIN"
    # Its own name, so a site can tell it from the wordpress.org edition on the Plugins screen,
    # and its own update feed (super-speedy-performance-analysis-pro.json), read by folder name.
    # The source tree is the Pro plugin since 0.68.6: nothing is rewritten here.
    grep -qE '^\s*\*\s*Plugin Name:\s*Super Speedy Performance Analysis Pro\r?$' "$main" \
        || ssx_die "Plugin Name is not 'Super Speedy Performance Analysis Pro'"
    grep -q "'plugin_slug' => dirname(plugin_basename(__FILE__))," "$main" \
        || ssx_die "the updater slug is not the install folder"
    php -l "$main" >/dev/null || ssx_die "$PLUGIN_MAIN does not parse"
    ssx_log "Pro name and update slug $PRO_SLUG"
    grep -rqE '^\s*//\s*SSPA-PRO-START' "$stage/includes/class-sspa-auth.php" \
        || ssx_die "the Pro tree has no SSPA-PRO blocks in class-sspa-auth.php - was it built from a stripped tree?"
    ssx_hook_full "$stage"
}

# Shared by the Pro edition: the customer agent guide, version and asset checks.
ssx_hook_full() {
    # Customer agent instructions. The repository root AGENTS.md is the private developer guide
    # (a gitignored symlink), so the shipped one is kept in .build and copied in here. Remove the
    # staged symlinks first: cp would otherwise write through AGENTS.md into the private guide.
    rm -f "$1/AGENTS.md" "$1/CLAUDE.md"
    # The settings submodule carries its own developer AGENTS.md/CLAUDE.md; they are not for customers.
    rm -f "$1/super-speedy-settings/AGENTS.md" "$1/super-speedy-settings/CLAUDE.md"
    cp "$1/.build/customer-AGENTS.md" "$1/AGENTS.md" || ssx_die "customer AGENTS.md missing"
    ssx_log "added AGENTS.md"
    ssx_assert_versions_match "$1"
    ssx_assert_history_chart "$1"
    [ -f "$1/super-speedy-settings/super-speedy-settings.php" ] \
        || ssx_die "super-speedy-settings is missing - run: git submodule update --init"
    ssx_log "assert: super-speedy-settings present"
}
