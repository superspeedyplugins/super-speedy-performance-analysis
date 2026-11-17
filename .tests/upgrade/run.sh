#!/usr/bin/env bash
#
# How an installed Super Speedy Performance Analysis receives the next release through its own
# update checker, from the Plugins screen, in a real browser.
#
#   .tests/upgrade/run.sh <installed.zip> <feed-version> [scenario ...]
#
# Site: tests-pro-upgrade (created on demand, left in place). Each scenario starts from a clean
# install of <installed.zip> (activated), with the update feed answering <feed-version>. Scenarios
# say what the package download returns:
#   real                 the real network (today: GitHub's latest release asset)
#   local:<zip>          this zip, served for the GitHub latest-release asset URL
#   404                  no asset with that name on the latest GitHub release
# For each: the update notice, WordPress's result, the plugin folders on disk, which are active,
# the installed version and name, and every update request (licence header presence only).

set -uo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_DIR="$(cd "$HERE/../.." && pwd)"
export SSPA_SCENARIO="${SSPA_SCENARIO:-tests-pro-upgrade}"
source "$PLUGIN_DIR/.tests/env.sh" 2>/dev/null || { source "$HOME/dev/super-speedy/tools/parallel-dev/bin/lib.sh"; }
set +e
SITE=$(pd_site_dir super-speedy-performance-analysis "$SSPA_SCENARIO"); URL=$(pd_site_url super-speedy-performance-analysis "$SSPA_SCENARIO")
[ -f "$SITE/wp-config.php" ] || "$HOME/dev/super-speedy/tools/parallel-dev/bin/create-site.sh" super-speedy-performance-analysis "$SSPA_SCENARIO" >/dev/null || exit 1
case "$SITE" in /var/www/sites/*) ;; *) echo "refusing site outside /var/www/sites: $SITE"; exit 1 ;; esac
W() { wp --path="$SITE" --url="$URL" "$@"; }
INSTALLED="${1:?installed zip}"; FEED_VERSION="${2:?feed version}"; shift 2
OUT="$PLUGIN_DIR/.data/upgrade/$(date +%Y%m%d-%H%M%S)"; mkdir -p "$OUT"
GH='https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip'
FEED=$(curl -s -m 30 https://www.superspeedyplugins.com/assets/plugins/super-speedy-performance-analysis.json | sed -E "s/(\"version\"[[:space:]]*:[[:space:]]*\")[^\"]+/\1$FEED_VERSION/")
echo "site: $URL   installed: $(basename "$INSTALLED")   feed version: $FEED_VERSION   evidence: $OUT"
mkdir -p "$SITE/wp-content/mu-plugins"; cp "$HERE/feed-fixture.php" "$SITE/wp-content/mu-plugins/zz-upgrade-feed-fixture.php"
export SSPA_E2E_URL="$URL" SSPA_E2E_USER="$ADMIN_USER" SSPA_E2E_PASSWORD="$ADMIN_PASS" SSPA_FLEET_OUTPUT="$OUT"
[ -d "$PLUGIN_DIR/.tests/node_modules" ] || export NODE_PATH="$(cd "$(git -C "$PLUGIN_DIR" rev-parse --git-common-dir)/.." && pwd)/.tests/node_modules"

for scenario in "$@"; do
    echo; echo "=== scenario: $scenario"
    # Clean start: no copy of either edition, the installed zip active, no stored update state.
    W plugin deactivate super-speedy-performance-analysis super-speedy-performance-analysis-pro >/dev/null 2>&1
    rm -rf "${SITE:?}/wp-content/plugins/super-speedy-performance-analysis" "${SITE:?}/wp-content/plugins/super-speedy-performance-analysis-pro"
    rm -f "$SITE/wp-content/mu-plugins/sspa-loader.php" "$SITE/wp-content/db.php"
    unzip -qq "$INSTALLED" -d "$SITE/wp-content/plugins/" && W plugin activate super-speedy-performance-analysis >/dev/null 2>&1
    W eval 'delete_site_transient("update_plugins"); foreach (array("external_updates-super-speedy-performance-analysis","external_updates-super-speedy-performance-analysis-pro","zz_upgrade_log","zz_upgrade_package","zz_upgrade_package_url") as $o) delete_option($o);' >/dev/null 2>&1
    W option update zz_upgrade_feed "$FEED" >/dev/null
    case "$scenario" in
        real) ;;
        404) W option update zz_upgrade_package 404 >/dev/null; W option update zz_upgrade_package_url "$GH" >/dev/null ;;
        local:*) W option update zz_upgrade_package "${scenario#local:}" >/dev/null; W option update zz_upgrade_package_url "$GH" >/dev/null ;;
    esac
    printf '<?php echo json_encode(function_exists("opcache_reset") ? opcache_reset() : null);' > "$SITE/zz-opcache-reset.php"; curl -s -o /dev/null "$URL/zz-opcache-reset.php"; rm -f "$SITE/zz-opcache-reset.php"
    echo "before: $(W plugin list --fields=name,status,version --format=csv 2>/dev/null | grep performance-analysis | tr '\n' ' ')"
    UPGRADE_LABEL="$(echo "$scenario" | tr -c 'a-z0-9' '_' | cut -c1-40)" timeout 600 node "$HERE/update.e2e.cjs" 2>&1 | sed 's/^/  /'
    echo "folders: $(ls "$SITE/wp-content/plugins" | grep performance-analysis | tr '\n' ' ')"
    echo "after: $(W plugin list --fields=name,status,version --format=csv 2>/dev/null | grep performance-analysis | tr '\n' ' ')"
    for f in super-speedy-performance-analysis super-speedy-performance-analysis-pro; do
        [ -f "$SITE/wp-content/plugins/$f/super-speedy-performance-analysis.php" ] && echo "  $f header: $(grep -m1 'Plugin Name:' "$SITE/wp-content/plugins/$f/super-speedy-performance-analysis.php" | sed 's/^ \* //') / $(grep -m1 'Version:' "$SITE/wp-content/plugins/$f/super-speedy-performance-analysis.php" | sed 's/^ \* //') / updater slug $(grep -oE "'plugin_slug' => '[^']+'" "$SITE/wp-content/plugins/$f/super-speedy-performance-analysis.php" | head -1)"
    done
    echo "requests:"; W option get zz_upgrade_log --format=json 2>/dev/null | php -r 'foreach ((array) json_decode(stream_get_contents(STDIN), true) as $e) echo "  ", json_encode($e, JSON_UNESCAPED_SLASHES), "\n";'
done
echo; echo "Site left in place: $URL/wp-admin/plugins.php"
