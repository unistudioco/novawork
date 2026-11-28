#!/usr/bin/env bash
# Offline test mode: while a test run is on, the site makes no request
# outside itself. tests/fixtures/test-offline.php is linked into mu-plugins
# and turned on with an option: each outside request is refused and noted,
# and WordPress's update checks are answered locally. Avatars (Gravatar) and
# Fontshare are switched off for the run and restored after.
#
#   bin/offline.sh on          link the fixture, save and switch off avatars and Fontshare
#   bin/offline.sh outside     print the outside requests noted so far (JSON list)
#   bin/offline.sh off         undo all of it; says what it undid
#   bin/offline.sh off --quiet the same, silently (the end of a normal run)
#
# The browser run (setup.js, teardown.js) and bin/test-http.sh use it. An
# interrupted run can leave it on: "off" (also run by bin/addons.sh on) puts
# the site back, and the saved settings outlive the run until then.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$ROOT/.dev.env" ]] && source "$ROOT/.dev.env"
: "${NOVAWORK_WP_PATH:?Set NOVAWORK_WP_PATH in .dev.env (see .dev.env.example)}"
LINK="$NOVAWORK_WP_PATH/wp-content/mu-plugins/novawork-test-offline.php"

wpcli() { php ${NOVAWORK_MYSQL_SOCKET:+-d mysqli.default_socket="$NOVAWORK_MYSQL_SOCKET"} "$(command -v wp)" --path="$NOVAWORK_WP_PATH" eval "$1"; }

case "${1:-}" in
	on)
		mkdir -p "$(dirname "$LINK")"
		[[ -e "$LINK" ]] || ln -s "$ROOT/tests/fixtures/test-offline.php" "$LINK"
		wpcli 'if ( false === get_option( "novawork_test_offline_saved" ) ) { $s = (array) get_option( NovaWork\Settings::OPTION, array() ); update_option( "novawork_test_offline_saved", array( "show_avatars" => get_option( "show_avatars" ), "load_fontshare" => $s["load_fontshare"] ?? null ), false ); } update_option( "show_avatars", "0" ); $s = (array) get_option( NovaWork\Settings::OPTION, array() ); $s["load_fontshare"] = false; update_option( NovaWork\Settings::OPTION, $s ); update_option( "novawork_test_offline", "1", false ); delete_option( "novawork_test_outside" );' >/dev/null
		;;
	outside)
		wpcli 'echo wp_json_encode( array_values( (array) get_option( "novawork_test_outside", array() ) ) );'
		;;
	off)
		left="$(wpcli 'echo ( false !== get_option( "novawork_test_offline_saved" ) || false !== get_option( "novawork_test_offline" ) ) ? "yes" : "no";')"
		[[ -L "$LINK" || -e "$LINK" ]] && left="yes"
		if [[ "$left" == "yes" ]]; then
			wpcli '$saved = get_option( "novawork_test_offline_saved" ); if ( is_array( $saved ) ) { update_option( "show_avatars", $saved["show_avatars"] ); $s = (array) get_option( NovaWork\Settings::OPTION, array() ); if ( null === $saved["load_fontshare"] ) { unset( $s["load_fontshare"] ); } else { $s["load_fontshare"] = $saved["load_fontshare"]; } update_option( NovaWork\Settings::OPTION, $s ); } delete_option( "novawork_test_offline_saved" ); delete_option( "novawork_test_offline" ); delete_option( "novawork_test_outside" );' >/dev/null
			rm -f "$LINK"
			[[ "${2:-}" == "--quiet" ]] || echo "Offline test mode was still on (an interrupted run): the fixture link and its option are removed, and avatars and Fontshare are back as they were."
		fi
		;;
	*)
		echo "Usage: bin/offline.sh on|outside|off [--quiet]" >&2
		exit 2
		;;
esac
