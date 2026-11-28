#!/usr/bin/env bash
# Build the WordPress.org release zip: build/novawork-<version>.zip
#
# The zip holds one folder, novawork/, with exactly what ships:
# - everything in the repository except what .distignore lists (the single
#   list of what never ships; bin/plugin-check.sh checks this same zip),
# - the built assets (assets/dist) next to their source,
# - a production-only Composer autoloader (vendor/ is rebuilt with --no-dev,
#   never copied from a dev checkout).
#
# It refuses to build when the version is not the same everywhere, when a
# file that must ship is missing, or when something that must never ship
# slipped in.
#
# Usage: bin/build-zip.sh [--no-build] [--beta=N]
#   --no-build  skips npm run build
#   --beta=N    an internal build for our own workspace: the zip's plugin
#               header and NOVAWORK_VERSION say <version>-beta.N (only in the
#               zip), so WordPress still offers the real <version> when it
#               ships. The repository keeps <version>.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

fail() {
	echo "build-zip: $*" >&2
	exit 1
}

# One version everywhere: the plugin header, the constant, readme's stable
# tag and package.json.
header=$(sed -n 's/^ \* Version:[[:space:]]*//p' novawork.php | tr -d '[:space:]')
constant=$(sed -n "s/^define( 'NOVAWORK_VERSION', '\([^']*\)' );/\1/p" novawork.php)
stable=$(sed -n 's/^Stable tag:[[:space:]]*//p' readme.txt | tr -d '[:space:]')
package=$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' package.json | head -1)
[[ -n "$header" ]] || fail "no Version in the novawork.php header"
for pair in "NOVAWORK_VERSION:$constant" "readme.txt Stable tag:$stable" "package.json:$package"; do
	[[ "${pair##*:}" == "$header" ]] || fail "${pair%:*} is ${pair##*:}, the plugin header says $header"
done
VERSION="$header"
BUILD=1
BETA=""
for arg in "$@"; do
	case "$arg" in
		--no-build) BUILD=0 ;;
		--beta=*) BETA="${arg#--beta=}"; [[ "$BETA" =~ ^[0-9]+$ ]] || fail "--beta needs a number, like --beta=1" ;;
		*) fail "unknown option $arg" ;;
	esac
done
SHIP="$VERSION${BETA:+-beta.$BETA}"

if [[ "$BUILD" == 1 ]]; then
	npm run build --silent
fi
[[ -f assets/dist/manifest.json ]] || fail "assets/dist/manifest.json is missing: run npm run build"

OUT="$ROOT/build/release"
COPY="$OUT/novawork"
ZIP="$ROOT/build/novawork-$SHIP.zip"
rm -rf "$OUT" "$ZIP"
mkdir -p "$COPY"

# vendor/ is rebuilt below with production packages only.
rsync -a --exclude-from=.distignore --exclude=/vendor ./ "$COPY/"
cp composer.lock "$COPY/"
composer install --no-dev --optimize-autoloader --no-interaction --quiet --working-dir="$COPY"
rm -f "$COPY/composer.lock"
# Finder can write .DS_Store into a folder it shows, even during the copy.
find "$COPY" -name .DS_Store -delete
# An internal build says so in the copy only.
if [[ -n "$BETA" ]]; then
	sed -i.bak -e "s/^ \* Version:\([[:space:]]*\)$VERSION\$/ * Version:\1$SHIP/" -e "s/^define( 'NOVAWORK_VERSION', '$VERSION' );/define( 'NOVAWORK_VERSION', '$SHIP' );/" "$COPY/novawork.php"
	rm -f "$COPY/novawork.php.bak"
	grep -q "^define( 'NOVAWORK_VERSION', '$SHIP' );" "$COPY/novawork.php" || fail "could not set $SHIP in the zip's novawork.php"
fi

# What must ship.
for f in novawork.php uninstall.php readme.txt LICENSE vendor/autoload.php assets/dist/manifest.json assets/dist/i18n.json assets/alerts-worker.js assets/app-worker.js assets/app-icons/shape.png assets/app-icons/flag.png assets/app-icons/icon-512.png languages/novawork.pot package.json vite.config.js vite-i18n-map.js assets/src/js/app.js assets/src/js/admin-kit.js; do
	[[ -e "$COPY/$f" ]] || fail "$f is missing from the release"
done
# What must never ship.
for f in .git node_modules tests bin build test-results playwright-report CLAUDE.md HANDOFF.md .dev.env .distignore composer.lock vendor/bin vendor/phpstan vendor/squizlabs; do
	[[ ! -e "$COPY/$f" ]] || fail "$f must not be in the release"
done

# The source ships with its build tools (guideline 4): the shipped
# vite.config.js, with everything it imports, must build. Rebuild a scratch
# copy of the release with this checkout's node_modules and compare entries.
SCRATCH="$(mktemp -d)"
trap 'rm -rf "$SCRATCH"' EXIT
cp -R "$COPY" "$SCRATCH/novawork"
ln -s "$ROOT/node_modules" "$SCRATCH/novawork/node_modules"
(cd "$SCRATCH/novawork" && npx --no-install vite build --logLevel error) >/dev/null || fail "the shipped vite.config.js does not build"
for f in manifest.json i18n.json; do
	[[ -f "$SCRATCH/novawork/assets/dist/$f" ]] || fail "the shipped build tools did not write assets/dist/$f"
done
entries() { node -e 'const m=require(process.argv[1]);console.log(Object.keys(m).filter((k)=>m[k].isEntry).sort().join(" "))' "$1"; }
[[ "$(entries "$SCRATCH/novawork/assets/dist/manifest.json")" == "$(entries "$COPY/assets/dist/manifest.json")" ]] || fail "the shipped build tools build different entries"

(cd "$OUT" && zip -rqX "$ZIP" novawork)

files=$(find "$COPY" -type f | wc -l | tr -d ' ')
size=$(du -h "$ZIP" | cut -f1)
echo "Built ${ZIP#"$ROOT"/} (version $SHIP, $files files, $size)."
