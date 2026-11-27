#!/usr/bin/env bash
# HTTP smoke test: what rest_do_request() cannot show.
#
# Logs two members in with real cookies and checks, over HTTP:
#   - the REST API refuses a logged-in cookie without the wp_rest nonce
#   - a change by one member reaches the other on their next poll
#   - a real multipart upload, the private download (headers, owner only)
# It deletes the task and the upload it creates, and the task's row in the
# team activity feed (through WP-CLI, when .dev.env names the site). Change-log
# rows stay (harmless).
set -uo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
[[ -f "$ROOT/.dev.env" ]] && source "$ROOT/.dev.env"
: "${NOVAWORK_URL:?Set NOVAWORK_URL in .dev.env}" "${NOVAWORK_USER_A:?}" "${NOVAWORK_USER_B:?}"
API="$NOVAWORK_URL/wp-json/novawork/v1"
# Run PHP in the dev site through WP-CLI (when .dev.env names the site).
wpcli() { php ${NOVAWORK_MYSQL_SOCKET:+-d mysqli.default_socket="$NOVAWORK_MYSQL_SOCKET"} "$(command -v wp)" --path="$NOVAWORK_WP_PATH" eval "$1"; }
# Where the workspace really lives: /novawork/ (or the chosen address), or the
# home page when Settings → "Where it lives" is the home page.
APP="$NOVAWORK_URL/novawork/"
if [[ -n "${NOVAWORK_WP_PATH:-}" ]]; then
	APP="$(wpcli 'echo NovaWork\App\Route::url();' 2>/dev/null || echo "$APP")"
fi
TMP="$(mktemp -d)"
trap 'rm -r "$TMP"' EXIT
# NovaWork alone: plugins that require it are off for this run and back on
# at the end, even when it fails (bin/addons.sh).
if [[ -n "${NOVAWORK_WP_PATH:-}" ]]; then
	bash "$ROOT/bin/addons.sh" off
	trap 'rm -r "$TMP"; bash "$ROOT/bin/addons.sh" on' EXIT
fi
pass=0; fail=0
ok() { if [[ "$1" == "$2" ]]; then echo "  ✓ $3"; pass=$((pass+1)); else echo "  ✗ $3 (got: $1, want: $2)"; fail=$((fail+1)); fi; }
json() { python3 -I -c "import json,sys; d=json.load(sys.stdin); print(eval(sys.argv[1], {'d': d}))" "$1"; }

login() { # name user:pass
	local jar="$TMP/$1.jar" user="${2%%:*}" pass="${2#*:}"
	curl -s -o /dev/null -c "$jar" -b "wordpress_test_cookie=WP%20Cookie%20check" \
		--data-urlencode "log=$user" --data-urlencode "pwd=$pass" -d "testcookie=1" "$NOVAWORK_URL/wp-login.php"
	curl -s -b "$jar" "$NOVAWORK_URL/wp-admin/admin-ajax.php?action=rest-nonce"
}
NA="$(login a "$NOVAWORK_USER_A")"; NB="$(login b "$NOVAWORK_USER_B")"
A=(-s -b "$TMP/a.jar" -H "X-WP-Nonce: $NA"); B=(-s -b "$TMP/b.jar" -H "X-WP-Nonce: $NB")

echo "Auth"
ok "$(curl -s -o /dev/null -w '%{http_code}' -b "$TMP/a.jar" "$API/sync")" 401 "logged-in cookie without nonce → 401"
ok "$(curl "${A[@]}" -o /dev/null -w '%{http_code}' "$API/sync")" 200 "with the nonce → 200"
ok "$(curl -s -o /dev/null -w '%{http_code}' "$API/sync")" 401 "no cookie at all → 401"
ok "$(curl "${A[@]}" -o /dev/null -D - "$API/sync" | tr -d '\r' | grep -i '^cache-control:' | grep -c 'no-store')" 1 "sync responses are never cached"

echo "Two members"
CURSOR="$(curl "${B[@]}" "$API/sync" | json "d['cursor']")"
TASK="$(curl "${A[@]}" -H 'Content-Type: application/json' -d '{"title":"HTTP smoke test task"}' "$API/tasks" | json "d['id']")"
SEEN="$(curl "${B[@]}" "$API/sync?since=$CURSOR" | json "[t['title'] for t in d['data'].get('tasks', []) if t['id'] == $TASK]")"
ok "$SEEN" "['HTTP smoke test task']" "a task alex creates reaches sam on his next poll"
ok "$(curl "${A[@]}" -X DELETE -o /dev/null -w '%{http_code}' "$API/tasks/$TASK")" 200 "alex deletes it"
GONE="$(curl "${B[@]}" "$API/sync?since=$CURSOR" | json "$TASK in d['deleted'].get('tasks', [])")"
ok "$GONE" "True" "sam's next poll reports the delete"

echo "Private files"
printf '\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\0\0\0\0IEND\xaeB`\x82' > "$TMP/pixel one.png"
UP="$(curl "${A[@]}" -F "file=@$TMP/pixel one.png;type=image/png" "$API/attachments")"
ID="$(echo "$UP" | json "d.get('id', 0)")"
ok "$( [[ "$ID" -gt 0 ]] && echo yes )" yes "real multipart upload accepted"
ok "$(echo "$UP" | json "d['name']")" "pixel one.png" "keeps the readable file name"
HDR="$(curl -s -b "$TMP/a.jar" -D - -o "$TMP/down.png" "$API/attachments/$ID/file?_wpnonce=$NA" | tr -d '\r')"
ok "$(echo "$HDR" | head -1 | awk '{print $2}')" 200 "the uploader can download it"
ok "$(echo "$HDR" | grep -i '^content-type:' | awk '{print $2}')" "image/png" "served as an image"
ok "$(echo "$HDR" | grep -ic '^x-content-type-options: nosniff')" 1 "with nosniff"
ok "$(echo "$HDR" | grep -ic "^content-security-policy: default-src 'none'")" 1 "with a locked-down CSP"
ok "$(cmp -s "$TMP/pixel one.png" "$TMP/down.png" && echo same)" same "bytes match the upload"
ok "$(curl -s -b "$TMP/b.jar" -o /dev/null -w '%{http_code}' "$API/attachments/$ID/file?_wpnonce=$NB")" 404 "another member can't open an unsent upload"
ok "$(curl -s -b "$TMP/a.jar" -o /dev/null -w '%{http_code}' "$API/attachments/$ID/file")" 401 "the file route needs the nonce too"
DIRECT="$(curl -s -o /dev/null -w '%{http_code}' "$NOVAWORK_URL/wp-content/uploads/novawork/")"
echo "  · the private folder URL itself answers $DIRECT (index.php: empty page; .htaccess applies on Apache only)"
ok "$(curl "${A[@]}" -X DELETE -o /dev/null -w '%{http_code}' "$API/attachments/$ID")" 200 "the uploader discards it"
ok "$(curl -s -b "$TMP/a.jar" -o /dev/null -w '%{http_code}' "$API/attachments/$ID/file?_wpnonce=$NA")" 404 "and it is gone"

echo "Installable app (no cookies, as browsers fetch the manifest)"
MAN="$(curl -s "$NOVAWORK_URL/?novawork=manifest")"
ok "$(echo "$MAN" | json "d['display']")" standalone "the manifest opens the workspace in its own window"
ok "$(echo "$MAN" | json "len(d['icons'])")" 3 "with its icons"
ICON="$(echo "$MAN" | json "d['icons'][1]['src']")"
HDR="$(curl -s -D - -o "$TMP/icon.png" "$ICON" | tr -d '\r')"
ok "$(echo "$HDR" | grep -i '^content-type:' | awk '{print $2}')" "image/png" "an icon is a PNG"
ok "$(echo "$HDR" | grep -ic '^cache-control: public, max-age=31536000')" 1 "cached for good while its address is current"
ok "$(head -c 8 "$TMP/icon.png" | od -An -c | tr -d ' ')" '211PNG\r\n032\n' "real PNG bytes"
ok "$(curl -s "$NOVAWORK_URL/?novawork=app-worker" | grep -c 'const NOVAWORK = ')" 1 "the app worker comes with its settings"
ok "$(curl -s -o /dev/null -w '%{http_code}' "$NOVAWORK_URL/?novawork=icon-nope")" 404 "an unknown icon is a 404"
ok "$(curl -s -b "$TMP/a.jar" "$APP" | grep -c 'rel="manifest"')" 1 "the workspace links its manifest"

if [[ -n "${NOVAWORK_WP_PATH:-}" && -n "${TASK:-}" ]]; then
	wpcli \
		"global \$wpdb; \$r = new NovaWork\\Data\\ActivityRepository(); foreach ( \$wpdb->get_col( \$wpdb->prepare( 'SELECT id FROM %i WHERE object_type = %s AND object_id = %d', \$r->table_name(), 'task', (int) '$TASK' ) ) as \$id ) { \$r->delete( (int) \$id ); }" >/dev/null 2>&1
fi
echo; echo "$pass passed, $fail failed."
[[ $fail -eq 0 ]]
