#!/usr/bin/env bash
# Recopie le code de swanbida/ dans le faux hébergement (sans toucher config.php ni media/).
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
cd "$REPO/swanbida"
find . -type f ! -path './media/*' ! -name config.php | while read -r f; do mkdir -p "$DIR/public_html/swanbida/$(dirname "$f")"; cp "$f" "$DIR/public_html/swanbida/$f"; done
