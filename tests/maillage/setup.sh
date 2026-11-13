#!/usr/bin/env bash
# Prépare un faux hébergement : <dir>/public_html/maillage (copie du code) + admin/ (connexion du
# propriétaire, mot de passe « test-owner-pass ») + maillage/config.php (emails écrits dans mail.jsonl).
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
rm -rf "$DIR"; mkdir -p "$DIR/public_html"
cp -R "$REPO/maillage" "$DIR/public_html/maillage"
cp -R "$REPO/admin" "$DIR/public_html/admin"
rm -f "$DIR/public_html/maillage/config.php"
php -r 'file_put_contents($argv[1], "<?php\ndefine(\"ADMIN_PASSWORD_HASH\", " . var_export(password_hash("test-owner-pass", PASSWORD_DEFAULT), true) . ");\n");' "$DIR/public_html/admin/config.php"
cat > "$DIR/public_html/maillage/config.php" <<PHP
<?php
define('MLG_MAIL_LOG', '$DIR/mail.jsonl');
PHP
echo "OK $DIR"
