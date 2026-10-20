#!/usr/bin/env bash
# Install WordPress's Hebrew (he_IL) core language pack on the test site, so browser journeys
# can use a REAL right-to-left admin: is_rtl(), body.rtl and WordPress's rtl stylesheets, as an
# RTL user gets them. Flipping document.dir alone leaves the admin's LTR stylesheet in place
# (the fixed admin menu moves right while #wpcontent keeps its left margin), which is a state
# no real user sees. The suite site blocks external HTTP, so the official package is fetched
# here from downloads.wordpress.org into the gitignored .data folder and unzipped.
# Expects env.sh to be sourced (SSPA_SITE_DIR, PLUGIN_DIR).
set -uo pipefail
languages="$SSPA_SITE_DIR/wp-content/languages"
if [ -f "$languages/admin-he_IL.mo" ] && [ -f "$languages/he_IL.mo" ]; then
    exit 0
fi
zip="$PLUGIN_DIR/.data/downloads/he_IL-core.zip"
mkdir -p "$PLUGIN_DIR/.data/downloads" "$languages"
if [ ! -s "$zip" ]; then
    package=$(curl -sf --max-time 60 "https://api.wordpress.org/translations/core/1.0/" \
        | php -r '$d=json_decode(stream_get_contents(STDIN),true); foreach($d["translations"] as $t){ if($t["language"]==="he_IL"){ echo $t["package"]; break; } }')
    [ -n "$package" ] || { echo "FAIL: no he_IL core language pack listed by api.wordpress.org" >&2; exit 1; }
    curl -sfL --max-time 120 -o "$zip" "$package" || { echo "FAIL: could not download $package" >&2; exit 1; }
fi
unzip -oq "$zip" -d "$languages" || { echo "FAIL: could not unpack the he_IL language pack" >&2; exit 1; }
echo "installed he_IL core language pack into $languages"
