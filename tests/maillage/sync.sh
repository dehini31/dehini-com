#!/usr/bin/env bash
# Recopie le code de maillage/ dans un faux hébergement déjà préparé (la base et la config sont gardées).
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
cp "$DIR/public_html/maillage/config.php" "$DIR/config.keep"
cp -R "$REPO/maillage/." "$DIR/public_html/maillage/"
cp "$DIR/config.keep" "$DIR/public_html/maillage/config.php"
echo "OK $DIR"
