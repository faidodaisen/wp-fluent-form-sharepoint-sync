#!/usr/bin/env bash
# Build the installable plugin zip. Used by CI and locally: bash build.sh <version> <out-dir>
set -euo pipefail

SLUG=fluent-sharepoint-sync
VER="${1:?version}"
OUT="${2:-.}"
HERE="$(cd "$(dirname "$0")" && pwd)"
STAGE="$(mktemp -d)"
trap 'rm -rf "$STAGE"' EXIT

# Shipped paths (never extension-based): main file, uninstall, readme, whole runtime dirs.
mkdir -p "$STAGE/$SLUG"
cp "$HERE/$SLUG.php" "$HERE/uninstall.php" "$HERE/readme.txt" "$STAGE/$SLUG/"
for d in src assets languages; do
  if [ -d "$HERE/$d" ]; then cp -r "$HERE/$d" "$STAGE/$SLUG/"; fi
done
find "$STAGE/$SLUG" \( -name '.*' -o -name '*.md' -o -name '*.log' -o -name '*.zip' \) -prune -exec rm -rf {} +

# Sanity: one root folder, main file present, runtime dirs non-empty.
[ -f "$STAGE/$SLUG/$SLUG.php" ] || { echo "main file missing" >&2; exit 1; }
for d in src assets; do
  [ -n "$(ls -A "$STAGE/$SLUG/$d" 2>/dev/null)" ] || { echo "$d empty" >&2; exit 1; }
done

ZIP="$(cd "$OUT" && pwd)/$SLUG-$VER.zip"
rm -f "$ZIP"
( cd "$STAGE" && zip -rq "$ZIP" "$SLUG" )
echo "built $ZIP ($(unzip -l "$ZIP" | tail -1 | awk '{print $2}') files)"
