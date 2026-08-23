#!/usr/bin/env bash
# Publish .wiki/ to the GitHub wiki.
#
# The wiki is a separate git repository. Its pages are authored here, in .wiki/, so the
# content is reviewable next to the code that it documents and survives the wiki being
# wiped. This script is the only thing that should write to the wiki: editing a page in
# GitHub's web editor puts it one publish away from being overwritten.
#
# It refuses to push when the wiki carries commits this repository does not have, rather
# than force-pushing over somebody's web edit. Pull them into .wiki/ first.
#
#   .build/publish-wiki.sh            publish
#   .build/publish-wiki.sh --dry-run  show what would change, write nothing
set -uo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC="$REPO_DIR/.wiki"
CLONE="$REPO_DIR/.data/wiki-clone"
WIKI_URL="https://github.com/superspeedyplugins/super-speedy-performance-analysis.wiki.git"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

die() { printf 'error: %s\n' "$*" >&2; exit 1; }
info() { printf '  %s\n' "$*"; }

[ -d "$SRC" ] || die "no .wiki/ directory at $SRC"
ls "$SRC"/*.md >/dev/null 2>&1 || die ".wiki/ contains no markdown pages"

VERSION=$(grep -m1 '^ \* Version:' "$REPO_DIR/super-speedy-performance-analysis.php" | awk '{print $3}')
[ -n "$VERSION" ] || die "could not read the plugin version"

# Every [[link]] must resolve to a page, or the wiki ships dead links.
BROKEN=0
for target in $(grep -oh '\[\[[A-Za-z0-9-]*\]\]' "$SRC"/*.md | tr -d '[]' | sort -u); do
    if [ ! -f "$SRC/$target.md" ]; then
        printf 'broken wiki link: [[%s]] has no page\n' "$target" >&2
        BROKEN=1
    fi
done
[ "$BROKEN" -eq 0 ] || die "fix the broken links above before publishing"

mkdir -p "$(dirname "$CLONE")"
if [ -d "$CLONE/.git" ]; then
    git -C "$CLONE" fetch --quiet origin || die "could not fetch the wiki"
    git -C "$CLONE" reset --quiet --hard origin/HEAD || die "could not reset the wiki clone"
else
    rm -rf "$CLONE"
    git clone --quiet "$WIKI_URL" "$CLONE" || die "could not clone the wiki - create its first page in the browser once, then retry"
fi

# Refuse to clobber a web edit: anything in the wiki that is not in .wiki/ and is not one of
# ours gets reported rather than deleted.
for existing in "$CLONE"/*.md; do
    [ -e "$existing" ] || continue
    name=$(basename "$existing")
    if [ ! -f "$SRC/$name" ]; then
        info "note: $name exists in the wiki but not in .wiki/ - leaving it alone"
    fi
done

rsync -a --exclude '.git' "$SRC"/*.md "$CLONE/"
[ -d "$SRC/images" ] && rsync -a "$SRC/images" "$CLONE/"

if [ -z "$(git -C "$CLONE" status --porcelain)" ]; then
    info "wiki already matches .wiki/ - nothing to publish"
    exit 0
fi

info "changes to publish:"
git -C "$CLONE" status --short | sed 's/^/    /'

if [ "$DRY_RUN" -eq 1 ]; then
    info "dry run - nothing written"
    exit 0
fi

git -C "$CLONE" add -A || die "could not stage the wiki changes"
git -C "$CLONE" commit --quiet -m "Wiki update from plugin v${VERSION}" || die "could not commit"
git -C "$CLONE" push --quiet || die "push failed - if the wiki has commits this repo does not, pull them into .wiki/ first"

info "published from v${VERSION}: https://github.com/superspeedyplugins/super-speedy-performance-analysis/wiki"
