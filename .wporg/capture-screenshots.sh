#!/usr/bin/env bash
# Capture the WordPress.org listing screenshots from the EXACT wp.org archive.
#
#   [OUT=<dir>] .wporg/capture-screenshots.sh <super-speedy-performance-analysis-X.Y.Z-wporg.zip>
#
# Resets wporg-listing.super-speedy-performance-analysis.localhost:8081 on the way IN
# (.wporg/listing-site.sh), then drives the real admin: two analyses (the first installs the
# profiler, the second is recorded), a page profile (recorded), the cache check and Compare.
# Writes screenshot-1.gif and screenshot-2.gif (from Playwright videos, via ffmpeg) and
# screenshot-3..7.png into $OUT (default .wporg/assets/). The site is left behind for inspection.
set -euo pipefail
ZIP="$(realpath "${1:?usage: capture-screenshots.sh <wporg zip>}")"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ROOT="$(cd "$HERE/.." && pwd)"
OUT="${OUT:-$HERE/assets}"; mkdir -p "$OUT"
# Recordings and their event times stay in .data for inspection.
REC="$ROOT/.data/wporg-capture/recordings"; mkdir -p "$REC"
# GIF_ONLY=1 re-encodes the GIFs from the last recordings without touching the site.
if [ "${GIF_ONLY:-0}" != 1 ]; then
    "$HERE/listing-site.sh" "$ZIP"
    . "$HOME/dev/super-speedy/tools/parallel-dev/bin/lib.sh"
    export NODE_PATH="${NODE_PATH:-$ROOT/.tests/node_modules}"
    export PD_ADMIN_USER="$ADMIN_USER" PD_ADMIN_PASS="$ADMIN_PASS"
    rm -f "$REC"/video-*.webm "$REC"/video-*.json
    OUT="$REC" BASE="http://wporg-listing.super-speedy-performance-analysis.localhost:$PORT" timeout 1800 node "$HERE/capture-screenshots.cjs"
    mv "$REC"/screenshot-*.png "$OUT"/
    rm -f "$OUT"/screenshot-1.png "$OUT"/screenshot-2.png
fi

# Same 14:9 proportions as the 1400x900 PNGs, at 1050x675, 10 frames a second, 96 colours and
# no dithering: flat admin colours stay clean and each GIF stays under wordpress.org's 10 MB.
# Video 1 plays the press and the results at real speed and the analysis itself sped up to
# RUN_SECONDS; video 2 plays at real speed.
RUN_SECONDS=4
gif() { # <webm> <filter_complex ending in [v]> <out.gif>
    /usr/bin/ffmpeg -v error -y -i "$1" -filter_complex "$2;[v]fps=10,scale=1050:-1:flags=lanczos,split[a][b];[a]palettegen=max_colors=96:stats_mode=diff[p];[b][p]paletteuse=dither=none:diff_mode=rectangle" -loop 0 "$3"
}
read -r R C D E < <(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m["ready"], m["click"]+0.8, m["done"], m["end"])' "$REC/video-1.json")
SPEED=$(python3 -c "print(max(1.0, ($D - $C) / $RUN_SECONDS))")
gif "$REC/video-1.webm" "[0:v]trim=$R:$C,setpts=PTS-STARTPTS[s1];[0:v]trim=$C:$D,setpts=(PTS-STARTPTS)/$SPEED[s2];[0:v]trim=$D:$E,setpts=PTS-STARTPTS[s3];[s1][s2][s3]concat=n=3:v=1[v]" "$OUT/screenshot-1.gif"
echo "screenshot-1.gif: analysis of $(python3 -c "print(round($D - $C))")s shown at ${SPEED}x"
read -r R E < <(python3 -c 'import json,sys; m=json.load(open(sys.argv[1])); print(m["ready"], m["end"])' "$REC/video-2.json")
gif "$REC/video-2.webm" "[0:v]trim=$R:$E,setpts=PTS-STARTPTS[v]" "$OUT/screenshot-2.gif"
ls -l "$OUT"/screenshot-*
echo "screenshots written to $OUT"
