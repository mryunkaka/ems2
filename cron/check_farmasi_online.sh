#!/bin/sh
set -eu

SCRIPT_DIR="$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)"
PHP_BIN="${PHP_BIN:-$(command -v php || true)}"

if [ -z "$PHP_BIN" ]; then
    echo "php binary not found" >&2
    exit 1
fi

exec "$PHP_BIN" "$SCRIPT_DIR/check_farmasi_online.php"
