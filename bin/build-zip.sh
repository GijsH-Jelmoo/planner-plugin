#!/usr/bin/env bash
# Bouwt build/jelmoo-planner.zip zonder tests en dev-afhankelijkheden.
# De GitHub Action gebruikt dit script ook, zodat lokaal en release hetzelfde zijn.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
STAGE="$ROOT/build/jelmoo-planner"

rm -rf "$ROOT/build"
mkdir -p "$STAGE"

cp -R "$ROOT/jelmoo-planner.php" "$ROOT/uninstall.php" "$ROOT/includes" "$ROOT/assets" "$ROOT/composer.json" "$ROOT/composer.lock" "$STAGE/"

composer install --working-dir="$STAGE" --no-dev --optimize-autoloader --no-interaction --no-progress --quiet
rm "$STAGE/composer.json" "$STAGE/composer.lock"

(cd "$ROOT/build" && zip -rqX jelmoo-planner.zip jelmoo-planner -x '*.DS_Store')

echo "$ROOT/build/jelmoo-planner.zip"
