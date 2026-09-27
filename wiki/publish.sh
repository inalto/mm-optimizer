#!/usr/bin/env bash
# Publishes the pages in this directory to the GitHub wiki.
#
# GitHub does not create a wiki's git repository until the first page exists,
# and there is no API for that step. Once, by hand:
#
#   open https://github.com/inalto/mm-optimizer/wiki  ->  "Create the first page"
#   (save anything; this script overwrites it)
#
# After that, run this script whenever the pages change.

set -euo pipefail

REPO="git@github.com:inalto/mm-optimizer.wiki.git"
HERE="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
WORK="$( mktemp -d )"
trap 'rm -rf "$WORK"' EXIT

if ! git ls-remote "$REPO" >/dev/null 2>&1; then
    echo "The wiki repository does not exist yet." >&2
    echo "Create the first page at https://github.com/inalto/mm-optimizer/wiki, then run this again." >&2
    exit 1
fi

git clone --quiet "$REPO" "$WORK"
cp "$HERE"/*.md "$WORK/"
cd "$WORK"

if git diff --quiet && git diff --cached --quiet && [ -z "$( git status --porcelain )" ]; then
    echo "The wiki is already up to date."
    exit 0
fi

git add -A
git commit --quiet -m "Update the wiki from wiki/ in the main repository"
git push --quiet origin HEAD
echo "Wiki published."
