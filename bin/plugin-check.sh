#!/usr/bin/env bash
# Run the official Plugin Check against exactly what ships.
#
# Builds the assets, assembles a release copy from .distignore (with
# production-only Composer dependencies), and checks that copy, so dev files
# such as phpcs.xml.dist never show up as false errors and nothing that ships
# is skipped. Plugin Check exits 0 even when it reports errors, so this script
# reads the results and fails on any ERROR row.
#
# Usage: bin/plugin-check.sh [--no-build]
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

if [[ "${1:-}" != "--no-build" ]]; then
	npm run build --silent
fi

OUT="$ROOT/build/check"
COPY="$OUT/novawork"
rm -rf "$OUT"
mkdir -p "$COPY"
rsync -a --exclude-from=.distignore ./ "$COPY/"
cp composer.lock "$COPY/" 2>/dev/null || true
composer install --no-dev --optimize-autoloader --no-interaction --quiet --working-dir="$COPY"
rm -f "$COPY/composer.lock"
# Finder can write .DS_Store into a folder it shows, even after the copy.
find "$COPY" -name .DS_Store -delete

REPORT="$OUT/report.csv"
"${WP[@]}" --path="$NOVAWORK_WP_PATH" plugin check "$COPY" --slug=novawork \
	--format=csv --fields=file,line,type,code,message > "$REPORT" 2>&1 || true

cat "$REPORT"

if grep -q '^Error:' "$REPORT"; then
	echo "Plugin Check could not run (see above)." >&2
	exit 2
fi

errors=$(grep -c ',ERROR,' "$REPORT" || true)
warnings=$(grep -c ',WARNING,' "$REPORT" || true)
echo
echo "Plugin Check: ${errors} error(s), ${warnings} warning(s)."
[[ "$errors" -eq 0 ]]
