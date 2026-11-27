#!/usr/bin/env bash
# Run the official Plugin Check against exactly what ships: the release zip.
#
# Builds the zip with bin/build-zip.sh (assets, .distignore, production-only
# Composer autoloader), unpacks it into a temporary folder and checks that copy, so dev files such as
# phpcs.xml.dist never show up as false errors and nothing that ships is
# skipped. Plugin Check exits 0 even when it reports errors, so this script
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

bash "$ROOT/bin/build-zip.sh" "$@"
ZIP=$(ls -t "$ROOT"/build/novawork-*.zip | head -1)

OUT="$ROOT/build/check"
rm -rf "$OUT"
mkdir -p "$OUT"
# The copy goes in a fresh folder outside the repository: a Finder window (or
# Quick Look) showing build/ writes .DS_Store files into the folders it shows,
# and Plugin Check then reports a hidden file the zip never contained.
UNPACKED="$(mktemp -d)"
trap 'rm -rf "$UNPACKED"' EXIT
COPY="$UNPACKED/novawork"
unzip -q "$ZIP" -d "$UNPACKED"

# Plugin Check runs as a WP-CLI command of the Plugin Check plugin: if it is
# installed but switched off on the site, switch it on for this run and back
# off at the end, even when the run fails.
if "${WP[@]}" --path="$NOVAWORK_WP_PATH" plugin is-installed plugin-check 2>/dev/null \
	&& ! "${WP[@]}" --path="$NOVAWORK_WP_PATH" plugin is-active plugin-check 2>/dev/null; then
	"${WP[@]}" --path="$NOVAWORK_WP_PATH" plugin activate plugin-check --quiet
	trap 'rm -rf "$UNPACKED"; "${WP[@]}" --path="$NOVAWORK_WP_PATH" plugin deactivate plugin-check --quiet' EXIT
fi

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
