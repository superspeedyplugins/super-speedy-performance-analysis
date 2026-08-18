#!/usr/bin/env bash
# Shared config for the SSPA test suite.
#
# Replaced .tests/docker/env.sh on 16 August 2026. WordPress development on this machine
# does not use Docker - the environment is a native nginx + php-fpm + mariadb parallel-dev
# site. Docker cost a Colima VM reserving 6 GB and 4 CPUs before a single WordPress
# started; this costs a directory and a database.
#
# The site is PERSISTENT, not per-run: 43 case files against a fresh install every time
# would be slow, and the suite is written to be idempotent against a standing site.
# Rebuild it from scratch at any time with:
#
#   .tests/setup-site.sh
#
# A second checkout can run its own environment by overriding the scenario:
#   SSPA_SCENARIO=mybranch .tests/setup-site.sh && SSPA_SCENARIO=mybranch .tests/run-tests.sh

PLUGIN_SLUG=super-speedy-performance-analysis
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

SSPA_SCENARIO="${SSPA_SCENARIO:-tests}"

# Derive the path and URL from parallel-dev rather than hardcoding /opt/homebrew: that root
# is machine-local (PD_SITES_ROOT), and this repository is meant to work on the WSL2 box too.
PD_LIB="${PD_LIB:-$HOME/dev/super-speedy/tools/parallel-dev/bin/lib.sh}"
if [ -f "$PD_LIB" ]; then
    # lib.sh sets -e, which these scripts must not inherit - they are built on `grep -q ...`
    # idioms where a non-zero exit is a normal outcome. Restoring `set -uo pipefail` does NOT
    # clear -e; only `set +e` does.
    # shellcheck source=/dev/null
    source "$PD_LIB"
    set +e
    set -uo pipefail
    SSPA_SITE_DIR=$(pd_site_dir "$PLUGIN_SLUG" "$SSPA_SCENARIO")
    SSPA_SITE_URL=$(pd_site_url "$PLUGIN_SLUG" "$SSPA_SCENARIO")
else
    echo "  ! parallel-dev not found at $PD_LIB - falling back to the Mac default paths" >&2
    SSPA_SITE_DIR="/opt/homebrew/var/www/sites/${PLUGIN_SLUG}/${SSPA_SCENARIO}"
    SSPA_SITE_URL="http://${SSPA_SCENARIO}.${PLUGIN_SLUG}.localhost:8081"
fi

# Case files are addressed on the host filesystem now - there is no container to copy into,
# and the site reaches the plugin through parallel-dev's symlink to this repository. Kept
# under the old name so the case files and run-tests.sh did not have to change.
CONTAINER_PLUGIN_DIR="$PLUGIN_DIR"

# Same name and shape the Docker helper exposed, so no case file changed when this landed.
cli() {
    wp --path="$SSPA_SITE_DIR" --url="$SSPA_SITE_URL" "$@"
}

# The plugin is symlinked into the site by parallel-dev, so edits are already live. The
# Docker version had to tar the tree into the container before every run; this is a no-op
# and is kept only so callers do not need to know that.
sync_plugin() {
    if [ ! -e "$SSPA_SITE_DIR/wp-content/plugins/$PLUGIN_SLUG" ]; then
        echo "  ! $SSPA_SITE_DIR has no $PLUGIN_SLUG - run .tests/setup-site.sh" >&2
        return 1
    fi
    return 0
}

sspa_require_site() {
    if [ ! -f "$SSPA_SITE_DIR/wp-config.php" ]; then
        echo "No test site at $SSPA_SITE_DIR" >&2
        echo "Create it with: .tests/setup-site.sh" >&2
        return 1
    fi
    return 0
}
