#!/usr/bin/env bash
# Prépare un faux hébergement : <dir>/public_html/studioma (copie du code) + studioma/config.php
# (emails écrits dans mail.jsonl, connexion Google avec des clés de test). La base et les fichiers vont
# dans <dir>/studioma-data, comme sur le serveur.
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
rm -rf "$DIR"; mkdir -p "$DIR/public_html"
cp -R "$REPO/studioma" "$DIR/public_html/studioma"
cat > "$DIR/public_html/studioma/config.php" <<PHP
<?php
define('STM_MAIL_LOG', '$DIR/mail.jsonl');
// « Continue with Google » : ID client de test ; les clés publiques sont écrites par les tests
// (google-certs.json), qui signent eux-mêmes les jetons d'identité.
define('STM_GOOGLE_CLIENT_ID', 'studioma-test.apps.googleusercontent.com');
define('STM_GOOGLE_FROM_IMPACT', false);
define('STM_GOOGLE_CERTS', '$DIR/google-certs.json');
PHP
echo "OK $DIR"
