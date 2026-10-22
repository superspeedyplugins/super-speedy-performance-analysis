#!/usr/bin/env bash
# Case 142's dedicated site. The traffic observer records the plugin directory of the PHP process that
# installs it. On the ordinary suite sites the plugin is a symlink and WP-CLI resolves it to the checkout,
# so moving the site's plugins/ entry would not touch the files the observer loads. Customers install PA
# as a real folder. This site therefore holds a real copy of the invoking checkout, refreshed on every
# run, and case.php renames that folder exactly as a customer does over FTP.
set -uo pipefail
export SSPA_SCENARIO="${SSPA_OBSERVER_GUARD_SCENARIO:-tests-observer-copy}"
source "$(dirname "${BASH_SOURCE[0]}")/../env.sh"
set -uo pipefail
case "$(realpath -m "$SSPA_SITE_DIR")" in "$(realpath -m "$SITES_ROOT")"/*) ;; *) echo "Refusing non-isolated site" >&2; exit 1 ;; esac
if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
    "$WORKSPACE/tools/parallel-dev/bin/create-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" || exit 1
fi
case "$SSPA_SCENARIO" in
    tests-observer-copy) "$WORKSPACE/tools/parallel-dev/bin/keep-site.sh" "$PLUGIN_SLUG" "$SSPA_SCENARIO" 'SSPA case 142 traffic observer with the plugin folder missing' ;;
esac
TARGET="$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG"
# A previous run interrupted mid-rename leaves the copy under its moved name: put it back first.
if [ ! -e "$TARGET" ] && [ -d "$TARGET-moved-by-case-142" ]; then mv "$TARGET-moved-by-case-142" "$TARGET"; fi
if [ -L "$TARGET" ]; then unlink "$TARGET"; fi
mkdir -p "$TARGET"
rsync -a --delete --exclude .git --exclude .data --exclude node_modules --exclude '.tests/node_modules' "$PLUGIN_DIR/" "$TARGET/" || exit 1
echo "Test site: $SSPA_SITE_URL ($SSPA_SITE_DIR), PA copied from $PLUGIN_DIR"
WPC=(wp --path="$SSPA_SITE_DIR" --url="$SSPA_SITE_URL")
"${WPC[@]}" plugin activate "$PLUGIN_SLUG" --quiet || exit 1
SSPA_142_CHECKOUT="$PLUGIN_DIR" "${WPC[@]}" --user=1 eval-file "$TARGET/.tests/observer-guard/case.php"
