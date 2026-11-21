#!/usr/bin/env bash
# Fill an empty dev workspace with generic demo content (tests/seed/dev-data.php).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$ROOT/.dev.env" ]] && source "$ROOT/.dev.env"
: "${NOVAWORK_WP_PATH:?Set NOVAWORK_WP_PATH in .dev.env}"
WP=(wp)
[[ -n "${NOVAWORK_MYSQL_SOCKET:-}" ]] && WP=(php -d "mysqli.default_socket=${NOVAWORK_MYSQL_SOCKET}" "$(command -v wp)")
"${WP[@]}" --path="$NOVAWORK_WP_PATH" eval-file "$ROOT/tests/seed/dev-data.php"
