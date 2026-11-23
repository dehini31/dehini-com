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
# Abonnements : les batteries historiques testent les fonctionnalités sans les limites des formules ; billing.js
# (BILLING=1) les applique, avec un faux PayPal (tests/studioma/fake-paypal.php, servi par router.php).
if [ "${BILLING:-0}" = "1" ]; then
  PORT=${2:-8097}
  cat >> "$DIR/public_html/studioma/config.php" <<PHP
define('STM_PLANS_ENFORCED', true);
define('STM_BILLING_ENABLED', true);
define('STM_PAYPAL_ENV', 'sandbox');
define('STM_PAYPAL_API_BASE', 'http://127.0.0.1:$PORT/fake-paypal');
define('STM_PAYPAL_CLIENT_ID', 'test-client-id-0123456789');
define('STM_PAYPAL_SECRET', 'test-secret-0123456789abcdef');
define('STM_PAYPAL_WEBHOOK_ID', 'WHTEST0123456789');
define('STM_PAYPAL_PLAN_SOLO_MONTHLY', 'P-SOLOMONTH0000000000000000');
define('STM_PAYPAL_PLAN_SOLO_YEARLY', 'P-SOLOYEAR00000000000000000');
define('STM_PAYPAL_PLAN_TEAM_MONTHLY', 'P-TEAMMONTH0000000000000000');
define('STM_PAYPAL_PLAN_TEAM_YEARLY', 'P-TEAMYEAR00000000000000000');
PHP
elif [ "${PROMO:-0}" = "1" ]; then
  # Offre de lancement (promo.js) : limites appliquées, paiement fermé comme en production ; tout appel à PayPal irait
  # au faux PayPal et serait noté dans fake-paypal.json (il ne doit jamais y en avoir). Règles anti-abus réglées par le test.
  PORT=${2:-8097}
  cat >> "$DIR/public_html/studioma/config.php" <<PHP
define('STM_PLANS_ENFORCED', true);
define('STM_LAUNCH_PROMO', true);
define('STM_PAYPAL_API_BASE', 'http://127.0.0.1:$PORT/fake-paypal');
define('STM_REFERRAL_SAME_IP_MAX', 0);
define('STM_REFERRAL_BURST_MAX', 0);
PHP
else
  echo "define('STM_PLANS_ENFORCED', false);" >> "$DIR/public_html/studioma/config.php"
fi
# Les batteries historiques (et billing.js) créent des comptes sans l'offre de lancement : ils restent en Free.
[ "${PROMO:-0}" = "1" ] || echo "define('STM_LAUNCH_PROMO', false);" >> "$DIR/public_html/studioma/config.php"
echo "OK $DIR"
