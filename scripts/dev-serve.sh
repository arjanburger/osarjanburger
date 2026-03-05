#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="/opt/homebrew/opt/php@8.4/bin/php"

if [[ ! -x "$PHP_BIN" ]]; then
  echo "[ERROR] Missing PHP binary at $PHP_BIN"
  exit 1
fi

cd "$ROOT_DIR"
exec "$PHP_BIN" -S 127.0.0.1:18093 "$ROOT_DIR/router.php"
