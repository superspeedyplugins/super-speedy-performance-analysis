#!/usr/bin/env bash
# Regenerate the first-party translation template with WP-CLI.
set -euo pipefail
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
destination="${1:-$root/languages/super-speedy-performance-analysis.pot}"
mkdir -p "$(dirname "$destination")"
wp i18n make-pot "$root" "$destination" \
    --domain=super-speedy-performance-analysis \
    --exclude=super-speedy-settings,plugin-update-checker,includes/admin/vendor,vendor
