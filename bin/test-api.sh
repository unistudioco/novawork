#!/usr/bin/env bash
# Run the NovaWork API integration tests against the dev site.
# Everything runs in one database transaction that is rolled back.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
if [[ -f .dev.env ]]; then
	# shellcheck disable=SC1091
	source .dev.env
fi
: "${NOVAWORK_WP_PATH:?Set NOVAWORK_WP_PATH in .dev.env (see .dev.env.example)}"
WP=(wp)
if [[ -n "${NOVAWORK_MYSQL_SOCKET:-}" ]]; then
	WP=(php -d "mysqli.default_socket=${NOVAWORK_MYSQL_SOCKET}" -d memory_limit=1G "$(command -v wp)")
fi
# NovaWork alone: plugins that require it are not loaded for this run
# (bin/addons.sh), so nothing changes on the site.
SKIP="$(bash "$ROOT/bin/addons.sh" skip)"
"${WP[@]}" --path="$NOVAWORK_WP_PATH" ${SKIP:+--skip-plugins="$SKIP"} eval-file "$ROOT/tests/api/run.php"
