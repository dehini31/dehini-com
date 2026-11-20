#!/usr/bin/env bash
# Lance toute la batterie de tests de Studioma sur un faux hébergement neuf :
#   tests/studioma/run.sh [dossier] [port]   (par défaut : un dossier temporaire, port 8097)
# D'abord l'API seule (api.js), le partage avec des invités sans compte (share.js), puis le serveur MCP
# et son OAuth (mcp.js), puis l'application dans un navigateur (e2e.js, Playwright / Chromium).
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
DIR=${1:-$(mktemp -d)}
PORT=${2:-8097}
if curl -s -o /dev/null "http://127.0.0.1:$PORT/"; then echo "Le port $PORT est déjà utilisé : choisir un autre port (2e argument)."; exit 1; fi
# Le serveur et ses processus de travail sont arrêtés ensemble, et on attend leur fin (port libéré).
P1=""
cleanup() { [ -n "$P1" ] || return 0; pkill -P "$P1" 2>/dev/null || true; kill "$P1" 2>/dev/null || true; wait "$P1" 2>/dev/null || true; P1=""; }
trap cleanup EXIT
fresh() {
  cleanup
  "$HERE/setup.sh" "$DIR" >/dev/null
  php -r '$i = imagecreatetruecolor(900, 600); imagefill($i, 0, 0, imagecolorallocate($i, 91, 75, 255)); imagefilledellipse($i, 450, 300, 380, 380, imagecolorallocate($i, 255, 122, 89)); imagepng($i, $argv[1]);' "$DIR/photo.png"
  echo "pas une image" > "$DIR/faux.jpg"
  echo "Notes de tournage" > "$DIR/notes.txt"
  PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:$PORT -t "$DIR/public_html" "$HERE/router.php" >"$DIR/server.log" 2>&1 & P1=$!
  for _ in 1 2 3 4 5 6 7 8 9 10; do curl -s -o /dev/null "http://127.0.0.1:$PORT/studioma/assets/favicon.svg" && break; sleep 0.3; done
}
cd "$REPO"
fresh
node "$HERE/api.js" "http://127.0.0.1:$PORT" "$DIR"
fresh
node "$HERE/share.js" "http://127.0.0.1:$PORT" "$DIR"
fresh
node "$HERE/mcp.js" "http://127.0.0.1:$PORT" "$DIR"
fresh
node "$HERE/e2e.js" "http://127.0.0.1:$PORT" "$DIR"
