#!/usr/bin/env bash
# Prépare un faux hébergement : <dir>/public_html/maillage (copie du code) + admin/ (connexion du
# propriétaire, mot de passe « test-owner-pass ») + maillage/config.php (emails écrits dans mail.jsonl,
# API d'IA remplacée par mock-ai.php).
set -euo pipefail
DIR=${1:?dossier de test}
REPO=$(cd "$(dirname "$0")/../.." && pwd)
rm -rf "$DIR"; mkdir -p "$DIR/public_html"
cp -R "$REPO/maillage" "$DIR/public_html/maillage"
cp -R "$REPO/admin" "$DIR/public_html/admin"
rm -f "$DIR/public_html/maillage/config.php"
php -r 'file_put_contents($argv[1], "<?php\ndefine(\"ADMIN_PASSWORD_HASH\", " . var_export(password_hash("test-owner-pass", PASSWORD_DEFAULT), true) . ");\n");' "$DIR/public_html/admin/config.php"
cp "$REPO/tests/maillage/mock-ai.php" "$DIR/public_html/mock-ai.php"
cat > "$DIR/public_html/maillage/config.php" <<PHP
<?php
define('MLG_MAIL_LOG', '$DIR/mail.jsonl');
// Recherche par photo : l'API d'IA est remplacée par mock-ai.php (aucune clé ici : elle se saisit dans admin.html).
define('MLG_AI_URL', 'http://127.0.0.1:' . (\$_SERVER['SERVER_PORT'] ?? '8096') . '/mock-ai.php');
PHP
echo "OK $DIR"
