#!/usr/bin/env python3
"""Generate the WordPress.org banner master (banner.html) for Super Speedy Performance Analysis.

Composite banner in the house style: deep corner-radial purple, the Performance Analysis Atlas glyph
as a faint ghost off the bottom-right, wordmark + tagline in Inter, and a crop of a REAL screenshot
(tools/banner-card-source.png: the Overview after an analysis, captured from the 0.68.4 wp.org archive
as screenshot-1; kept here so renumbering the listing screenshots cannot change the banner) on the
right. No numbers: a figure needs an entry in super-speedy-ops/products/<slug>/claims.md.

    python3 .wporg/tools/gen_banner.py            # writes .wporg/tools/banner.html
    node ~/.agents/skills/wporg-release/scripts/raster.cjs .wporg/tools/banner.html 1544 500 .wporg/assets/banner-1544x500.png
    node ~/.agents/skills/wporg-release/scripts/raster.cjs --downscale .wporg/assets/banner-1544x500.png 772 250 .wporg/assets/banner-772x250.png
"""
import base64
import re
from pathlib import Path

HERE = Path(__file__).resolve().parent
WPORG = HERE.parent
ROOT = WPORG.parent

icon_svg = (WPORG / "assets/icon.svg").read_text()
glyph = re.search(r'<path d="([^"]*)"', icon_svg).group(1)
inter = base64.b64encode((HERE / "Inter.woff2").read_bytes()).decode()  # OFL, Inter-OFL.txt
shot = base64.b64encode((HERE / "banner-card-source.png").read_bytes()).decode()

# Crop of banner-card-source.png (2x capture of a 1400x900 viewport): the site score and the first
# two top insights. Coordinates are in CSS px of the 1400x900 viewport.
CROP = dict(x=200, y=52, w=720, h=500)
SCALE = 0.74  # how large the crop appears in the banner

def glyph_svg(size, opacity):
    s = size / 1024
    return (f'<svg width="{size}" height="{size}" viewBox="0 0 {size} {size}" xmlns="http://www.w3.org/2000/svg">'
            f'<g transform="scale({s})"><g transform="translate(0,960) scale(1,-1)">'
            f'<path d="{glyph}" fill="#fff" fill-opacity="{opacity}"/></g></g></svg>')

html = f"""<!doctype html>
<html><head><meta charset="utf-8">
<style>
@font-face {{ font-family: Inter; src: url(data:font/woff2;base64,{inter}) format('woff2'); font-weight: 100 900; font-display: block; }}
html, body {{ margin: 0; width: 1544px; height: 500px; overflow: hidden; }}
.b {{ position: relative; width: 1544px; height: 500px; overflow: hidden; font-family: Inter, sans-serif; color: #fff;
     background: radial-gradient(circle at 22% 18%, #8b3ad4 0%, #2f0a52 88%); }}
.ghost {{ position: absolute; right: -330px; bottom: -360px; }}
.copy {{ position: absolute; left: 96px; top: 0; bottom: 0; width: 880px; display: flex; flex-direction: column; justify-content: center; }}
.tile {{ width: 84px; height: 84px; border-radius: 19px; background: radial-gradient(circle at 22% 18%, #9d4edd, #6a1fb0);
        box-shadow: 0 0 0 1px rgba(255,255,255,.22), 0 10px 30px rgba(0,0,0,.25); display: flex; align-items: center; justify-content: center; margin-bottom: 30px; }}
h1 {{ margin: 0; font-weight: 800; font-size: 74px; letter-spacing: -.035em; line-height: .98; }}
h1 .lite {{ display: inline-block; vertical-align: middle; margin-left: 14px; padding: 7px 16px 9px; border-radius: 999px;
           font-size: 30px; font-weight: 700; letter-spacing: 0; background: rgba(255,255,255,.16); box-shadow: inset 0 0 0 1.5px rgba(255,255,255,.45); position: relative; top: -6px; }}
p {{ margin: 22px 0 0; font-size: 29px; font-weight: 500; line-height: 1.3; color: rgba(255,255,255,.88); letter-spacing: -.01em; }}
.chips {{ margin-top: 30px; display: flex; flex-wrap: nowrap; gap: 10px; }}
.chips span {{ font-size: 17px; font-weight: 600; padding: 8px 13px; white-space: nowrap; border-radius: 10px; background: rgba(255,255,255,.12); color: #fff; }}
.ui {{ position: absolute; left: 968px; top: 56px; width: {CROP['w'] * SCALE}px; height: {CROP['h'] * SCALE}px; border-radius: 14px; overflow: hidden;
      background-image: url(data:image/png;base64,{shot}); background-repeat: no-repeat;
      background-size: {1400 * SCALE}px {900 * SCALE}px; background-position: -{CROP['x'] * SCALE}px -{CROP['y'] * SCALE}px;
      box-shadow: 0 30px 70px rgba(20,0,40,.55), 0 0 0 1px rgba(255,255,255,.18); transform: rotate(-3deg); transform-origin: top left; }}
p em {{ font-style: italic; font-weight: 600; color: #fff; }}
p u {{ text-decoration: underline; text-decoration-thickness: 3px; text-underline-offset: 6px; font-weight: 600; color: #fff; }}
</style></head>
<body><div class="b">
  <div class="ghost">{glyph_svg(760, 0.08)}</div>
  <div class="ui"></div>
  <div class="copy">
    <h1>Super Speedy<br>Performance Analysis</h1>
    <p>Find the plugins slowing down your WordPress and WooCommerce site, <em>page by page</em>.</p>
    <div class="chips"><span>Cost per plugin</span><span>Plugin Impact Analysis</span><span>Cache and checkout checks</span></div>
  </div>
</div></body></html>
"""
(HERE / "banner.html").write_text(html)
print("wrote", HERE / "banner.html")
