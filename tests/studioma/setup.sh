#!/usr/bin/env bash
# Prépare un faux hébergement : <dir>/public_html/studioma (copie du code) + studioma/config.php
# (emails écrits dans mail.jsonl, connexion Google avec des clés de test). La base et les fichiers vont
# dans <dir>/studioma-data, comme sur le serveur.
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
rm -rf "$DIR"; mkdir -p "$DIR/public_html"
cp -R "$REPO/studioma" "$DIR/public_html/studioma"
# Administration du site (/admin) : sa session ouvre aussi l'administration de Studioma (mot de passe de test).
cp -R "$REPO/admin" "$DIR/public_html/admin"
rm -f "$DIR/public_html/admin/config.php"
php -r 'file_put_contents($argv[1], "<?php\ndefine(\"ADMIN_PASSWORD_HASH\", " . var_export(password_hash("test-owner-pass", PASSWORD_DEFAULT), true) . ");\n");' "$DIR/public_html/admin/config.php"
cat > "$DIR/public_html/studioma/config.php" <<PHP
<?php
define('STM_MAIL_LOG', '$DIR/mail.jsonl');
// « Continue with Google » : ID client de test ; les clés publiques sont écrites par les tests
// (google-certs.json), qui signent eux-mêmes les jetons d'identité.
define('STM_GOOGLE_CLIENT_ID', 'studioma-test.apps.googleusercontent.com');
define('STM_GOOGLE_FROM_IMPACT', false);
define('STM_GOOGLE_CERTS', '$DIR/google-certs.json');
// Meta : faux ensemble de données ; les événements de l'API Conversions sont écrits dans meta.jsonl.
define('STM_META_PIXEL_ID', '1234567890123');
define('STM_META_TOKEN', 'test-meta-token-0123456789');
define('STM_META_LOG', '$DIR/meta.jsonl');
PHP
echo "OK $DIR"
