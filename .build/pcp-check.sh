#!/usr/bin/env bash
# All Plugin Check checks against the installed archive; retains the site and raw evidence.
# Usage: .build/pcp-check.sh <wporg-zip> [evidence-directory]
set -euo pipefail
here="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "$here/.." && pwd)"
zip="$(realpath "${1:?Usage: pcp-check.sh <wporg-zip> [evidence-directory]}")"
evidence="${2:-$root/.data/wporg-review/pcp}"
mkdir -p "$evidence"
evidence="$(cd "$evidence" && pwd)"
rm -f "$evidence/selected-checks.json"
workspace="${SUPERSPEEDY_WORKSPACE:-$HOME/dev/super-speedy}"
source "$workspace/tools/parallel-dev/bin/smoke.sh"
pd_smoke_start "${SSPA_PCP_TAG:-wporg-pcp-review}" super-speedy-performance-analysis "$zip"
# Pin the review tool so findings can be reproduced. Update deliberately when PCP changes.
wpc plugin install plugin-check --version=2.1.0 --activate > "$evidence/checker-install.log" 2>&1
checker="$PD_SMOKE_DIR/wp-content/plugins/plugin-check/cli.php"
wpc plugin list --format=json > "$evidence/plugins.json"
wpc core version > "$evidence/wordpress-version.txt"
php --version > "$evidence/php-version.txt"
sha256sum "$zip" > "$evidence/archive.sha256"
printf '%s\n' "$PD_SMOKE_URL" > "$evidence/site.txt"
wpc --require="$checker" plugin list-checks --include-experimental --format=json > "$evidence/checks.json" 2> "$evidence/checks.stderr"
php -r '$checks=json_decode(file_get_contents($argv[1]),true); if(!is_array($checks)||!count($checks)){fwrite(STDERR,"Missing Plugin Check inventory\n");exit(1);}' "$evidence/checks.json"
export SSPA_PCP_EVIDENCE="$evidence"
command=(wp plugin check super-speedy-performance-analysis "--path=$PD_SMOKE_DIR" "--require=$checker" "--require=$here/pcp-runtime-proof.php" --include-experimental --severity=1 --include-low-severity-errors --include-low-severity-warnings --format=strict-json --fields=file,line,column,type,code,message,docs)
printf '%q ' "${command[@]}" > "$evidence/command.txt"
printf '\n' >> "$evidence/command.txt"
set +e
"${command[@]}" > "$evidence/pcp.json" 2> "$evidence/pcp.stderr"
result=$?
set -e
printf '%s\n' "$result" > "$evidence/pcp.exit"
if [ "$result" -ne 0 ]; then
    cat "$evidence/pcp.stderr" >&2
    echo "Plugin Check command failed ($result); retained evidence: $evidence" >&2
    exit "$result"
fi
if [ -s "$evidence/pcp.stderr" ] || [ -s "$evidence/checks.stderr" ]; then
    cat "$evidence/checks.stderr" "$evidence/pcp.stderr" >&2
    echo 'Plugin Check wrote unexpected diagnostics; refusing a passing report.' >&2
    exit 1
fi
[ -s "$evidence/selected-checks.json" ] || { echo 'Missing actual runner inventory.' >&2; exit 1; }
sha256sum --check "$evidence/archive.sha256" > "$evidence/archive-unchanged.txt"
parity=$(mktemp -d)
unzip -q "$zip" -d "$parity"
diff -qr "$parity/super-speedy-performance-analysis" "$PD_SMOKE_DIR/wp-content/plugins/super-speedy-performance-analysis" > "$evidence/installed-parity.txt" || { echo "Installed package differs from archive; evidence: $evidence/installed-parity.txt" >&2; exit 1; }
rm -rf "$parity"
php "$here/validate-pcp.php" "$evidence/pcp.json" | tee "$evidence/summary.txt"
