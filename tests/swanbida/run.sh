#!/usr/bin/env bash
# Lance toute la batterie de tests de SWANBIDA sur un faux hébergement neuf :
#   tests/swanbida/run.sh [dossier]   (par défaut : un dossier temporaire)
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
DIR=${1:-$(mktemp -d)}
"$HERE/setup.sh" "$DIR" >/dev/null
cleanup() { kill "${P1:-}" "${P2:-}" 2>/dev/null || true; }
trap cleanup EXIT
FAKE_LOG="$DIR/fake.jsonl" php -S 127.0.0.1:8091 "$HERE/fake-services.php" >"$DIR/fake.log" 2>&1 & P1=$!
php -S 127.0.0.1:8090 -t "$DIR/public_html" "$HERE/router.php" >"$DIR/server.log" 2>&1 & P2=$!
sleep 1
B=http://127.0.0.1:8090/swanbida
J="$DIR/cookies.txt"
# Connexion propriétaire + configuration du pixel et de la Conversions API (fausse API Meta).
CSRF=$(curl -s -c "$J" -b "$J" -H 'Content-Type: application/json' -d '{"password":"test-owner-pass"}' "$B/admin/api.php?action=login" | php -r 'echo json_decode(stream_get_contents(STDIN),true)["csrf"] ?? "";')
[ -n "$CSRF" ] || { echo "Connexion admin impossible"; exit 1; }
curl -s -c "$J" -b "$J" -H "X-CSRF: $CSRF" -H 'Content-Type: application/json' \
  -d '{"pixel_id":"123456789012345","token":"EAABtesttokentesttokentesttokentesttoken1234","capi_enabled":true,"capi_browser":true,"test_code":"TEST123","graph_version":"v24.0","purchase_trigger":"confirmed","delivered_event":"OrderDelivered"}' \
  "$B/admin/api.php?action=meta_save" >/dev/null
curl -s -c "$J" -b "$J" -H "X-CSRF: $CSRF" -H 'Content-Type: application/json' \
  -d '{"section":"global","data":{"whatsapp":"0661234567","phone":"0661234567","email":"contact@example.com","facebook":"https://facebook.com/swanbida","instagram":"https://instagram.com/swanbida","tiktok":"","announcement":{"fr":"Paiement à la livraison · Livraison dans toute l'"'"'Algérie","ar":"الدفع عند الاستلام · التوصيل إلى كل ولايات الجزائر"},"tagline":{"fr":"L'"'"'élégance dans chaque détail","ar":"الأناقة في كل التفاصيل"},"footer_about":{"fr":"SWANBIDA crée des articles de bain en coton.","ar":"تقدّم SWANBIDA مستلزمات حمّام من القطن."},"address":{"fr":"Alger, Algérie","ar":"الجزائر العاصمة"},"hours":{"fr":"Tous les jours, 9 h – 20 h","ar":"كل يوم، 9 – 20"}}}' \
  "$B/admin/api.php?action=content_save" | sed 's/^/contenu : /'; echo
cd "$REPO" && node "$HERE/e2e.js" "$DIR" "${2:-$DIR/shots}"
