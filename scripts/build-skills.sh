#!/bin/sh
# Package each skill as dist/<name>.skill (a zip with the skill folder at its root) for claude.ai upload.
# Packages the committed files at HEAD, so uncommitted edits are not included.
set -eu
cd "$(dirname "$0")/.."
mkdir -p dist
for dir in skills/*/; do
    name=$(basename "$dir")
    git archive --format=zip --prefix="$name/" -o "dist/$name.skill" "HEAD:skills/$name"
    echo "dist/$name.skill"
done
