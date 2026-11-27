#!/usr/bin/env bash
# NovaWork's own tests check NovaWork alone, as a site without add-ons runs
# it: an add-on may change what they look at (the settings screen, the app's
# boot data). This switches off, for one test run, every active plugin that
# requires NovaWork (its "Requires Plugins" header, read by WordPress's
# WP_Plugin_Dependencies), and switches them back on afterwards.
#
#   bin/addons.sh off    deactivate them; adds them to build/addons-off.txt
#   bin/addons.sh on     reactivate everything in that file, then remove it
#   bin/addons.sh skip   print them as a --skip-plugins value (no change)
#
# The file outlives an interrupted run, so the next "on" (or the next run's
# own "off" then "on") still restores them. bin/test-http.sh and the browser
# run use off/on; bin/test-api.sh only skips them for its own process.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$ROOT/.dev.env" ]] && source "$ROOT/.dev.env"
: "${NOVAWORK_WP_PATH:?Set NOVAWORK_WP_PATH in .dev.env (see .dev.env.example)}"
STATE="$ROOT/build/addons-off.txt"

wpcli() { php ${NOVAWORK_MYSQL_SOCKET:+-d mysqli.default_socket="$NOVAWORK_MYSQL_SOCKET"} "$(command -v wp)" --path="$NOVAWORK_WP_PATH" "$@"; }

# Active plugins that require NovaWork, one plugin name (folder) per line.
active_addons() {
	wpcli eval 'require_once ABSPATH . "wp-admin/includes/plugin.php"; WP_Plugin_Dependencies::initialize(); foreach ( WP_Plugin_Dependencies::get_dependents( "novawork" ) as $file ) { if ( is_plugin_active( $file ) ) { echo "." === dirname( $file ) ? basename( $file, ".php" ) : dirname( $file ), "\n"; } }'
}

case "${1:-}" in
	off)
		mkdir -p "$(dirname "$STATE")"
		touch "$STATE"
		list="$(active_addons)"
		if [[ -n "$list" ]]; then
			printf '%s\n' "$list" >> "$STATE"
			sort -u -o "$STATE" "$STATE"
			# shellcheck disable=SC2086
			wpcli plugin deactivate $list --quiet
			echo "Add-ons off for this run: $(tr '\n' ' ' < "$STATE")"
		fi
		;;
	on)
		if [[ -s "$STATE" ]]; then
			# shellcheck disable=SC2046
			wpcli plugin activate $(cat "$STATE") --quiet
			echo "Add-ons back on: $(tr '\n' ' ' < "$STATE")"
		fi
		rm -f "$STATE"
		;;
	skip)
		active_addons | paste -sd, -
		;;
	*)
		echo "Usage: bin/addons.sh off|on|skip" >&2
		exit 2
		;;
esac
