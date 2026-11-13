#!/usr/bin/env bash
# Lance toute la batterie de tests de Maillage sur un faux hébergement neuf :
#   tests/maillage/run.sh [dossier] [port]   (par défaut : un dossier temporaire, port 8096)
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
DIR=${1:-$(mktemp -d)}
PORT=${2:-8096}
"$HERE/setup.sh" "$DIR" >/dev/null
php -r '$i = imagecreatetruecolor(600, 600); imagefill($i, 0, 0, imagecolorallocate($i, 230, 120, 80)); imagepng($i, $argv[1]);' "$DIR/photo.png"
php -r '$i = imagecreatetruecolor(640, 480); imagefill($i, 0, 0, imagecolorallocate($i, 235, 235, 230)); imagefilledellipse($i, 300, 260, 380, 150, imagecolorallocate($i, 37, 99, 235)); imagejpeg($i, $argv[1], 90);' "$DIR/photo2.jpg"
echo "pas une image" > "$DIR/faux.jpg"
if curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then echo "Le port $PORT est déjà utilisé : choisir un autre port (2e argument)."; exit 1; fi
# Le serveur et ses processus de travail sont arrêtés ensemble, et on attend leur fin (port libéré).
cleanup() { pkill -P "${P1:-0}" 2>/dev/null || true; kill "${P1:-0}" 2>/dev/null || true; wait "${P1:-0}" 2>/dev/null || true; }
trap cleanup EXIT
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t "$DIR/public_html" "$HERE/router.php" >"$DIR/server.log" 2>&1 & P1=$!
for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://127.0.0.1:$PORT/maillage/assets/favicon.svg" && break; sleep 0.3; done
cd "$REPO" && node "$HERE/e2e.js" "http://127.0.0.1:$PORT" "$DIR"
