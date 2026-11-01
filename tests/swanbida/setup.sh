#!/usr/bin/env bash
# Prépare un faux hébergement : <dir>/public_html/swanbida (copie du code) + admin/config.php
# (mot de passe propriétaire « test-owner-pass ») + swanbida/config.php pointant vers les faux services.
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
rm -rf "$DIR"; mkdir -p "$DIR/public_html/admin"
cp -R "$REPO/swanbida" "$DIR/public_html/swanbida"
php -r 'file_put_contents($argv[1], "<?php\ndefine(\"ADMIN_PASSWORD_HASH\", " . var_export(password_hash("test-owner-pass", PASSWORD_DEFAULT), true) . ");\n");' "$DIR/public_html/admin/config.php"
cat > "$DIR/public_html/swanbida/config.php" <<PHP
<?php
define('SWB_GRAPH_URL', 'http://127.0.0.1:8091/');
define('SWB_TELEGRAM_URL', 'http://127.0.0.1:8091/');
define('SWB_MAIL_LOG', '$DIR/mail.jsonl');
PHP
echo "OK $DIR"
