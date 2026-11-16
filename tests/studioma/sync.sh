#!/usr/bin/env bash
# Recopie le code de studioma/ dans un faux hébergement déjà préparé (la base et la config sont gardées).
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
cp "$DIR/public_html/studioma/config.php" "$DIR/config.keep"
cp -R "$REPO/studioma/." "$DIR/public_html/studioma/"
cp "$DIR/config.keep" "$DIR/public_html/studioma/config.php"
echo "OK $DIR"
