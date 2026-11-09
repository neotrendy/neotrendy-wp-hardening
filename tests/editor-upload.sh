#!/usr/bin/env bash
#
# Uploads tests/fixtures/gps.jpg through the block editor (Playwright, tests/editor-upload.js) and checks
# every file of the attachment on disk, with NWPH_STRIP_IMAGE_METADATA enabled and disabled.
#
# Usage: tests/editor-upload.sh
# Environment: SITE_URL, WP_PATH, CONFIG_DELAY as in smoke.sh. Node.js with the "playwright" package
# must be resolvable (for example through NODE_PATH) and its Chromium installed.
#
# wp-config.php is backed up and restored; a temporary administrator is created and deleted.

set -uo pipefail

SITE_URL="${SITE_URL:-http://wordpress.localhost}"
SITE_URL="${SITE_URL%/}"
WP_PATH="${WP_PATH:-/srv/wordpress.localhost}"
CONFIG_DELAY="${CONFIG_DELAY:-3}"
TESTS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

PASSES=0
FAILS=0

wpc() { wp --path="$WP_PATH" "$@"; }
pass() { echo "PASS  $*"; PASSES=$((PASSES + 1)); }
fail() { echo "FAIL  $*"; FAILS=$((FAILS + 1)); }
check() { if [[ "$3" == "$2" ]]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi; }

if ! node -e "require('playwright')" 2>/dev/null; then
	echo "FAIL  Node.js or the playwright package is not available"
	exit 1
fi
if [[ "$(wpc eval 'echo defined( "NWPH_VERSION" ) ? "yes" : "no";' 2>/dev/null)" != "yes" ]]; then
	echo "FAIL  neotrendy-wp-hardening is not loaded in $WP_PATH"
	exit 1
fi

CONFIG_FILE="$(wpc config path)"
TMP_DIR="$(mktemp -d)"
chmod 700 "$TMP_DIR"
cp -p "$CONFIG_FILE" "$TMP_DIR/wp-config.php.bak"
UPLOADS_DIR="$(wpc eval 'echo wp_upload_dir()["basedir"];')"
NAME="nwph-editor-$$"
USER_PASS="$(php -r 'echo bin2hex( random_bytes( 16 ) );')"
USER_ID="$(wpc user create "$NAME" "$NAME@example.invalid" --role=administrator --user_pass="$USER_PASS" --porcelain)"

cleanup() {
	[[ -s "$TMP_DIR/attachments" ]] && xargs wp --path="$WP_PATH" post delete --force --quiet < "$TMP_DIR/attachments"
	# WordPress 7.1 can leave the replaced original on disk after client-side processing.
	find "$UPLOADS_DIR" -type f -name "$NAME*" -delete
	wpc user delete "$USER_ID" --yes --quiet
	cat "$TMP_DIR/wp-config.php.bak" > "$CONFIG_FILE"
	rm -rf "$TMP_DIR"
}
trap cleanup EXIT

cp "$TESTS_DIR/fixtures/gps.jpg" "$TMP_DIR/$NAME.jpg"

# Uploads the fixture in the editor; prints "<client-side processing> <attachment ID>" and stores errors.
editor_upload() {
	local out
	out="$(SITE_URL="$SITE_URL" WP_USER="$NAME" WP_PASS="$USER_PASS" UPLOAD_FILE="$TMP_DIR/$NAME.jpg" node "$TESTS_DIR/editor-upload.js")"
	php -r '$r = json_decode( $argv[1], true ); file_put_contents( $argv[2], implode( "\n", $r["errors"] ?? array( "invalid output" ) ) ); echo var_export( $r["clientSideProcessing"] ?? null, true ), " ", $r["attachmentId"] ?? "";' "$out" "$TMP_DIR/errors"
}

# Prints tests/metadata.php output for every file of the attachment (main, original_image, sub-sizes).
attachment_report() {
	local files
	mapfile -t files < <(NWPH_ID="$1" wpc eval '
		$id  = (int) getenv( "NWPH_ID" );
		$f   = get_attached_file( $id );
		$out = array( $f, wp_get_original_image_path( $id ) );
		foreach ( wp_get_attachment_metadata( $id )["sizes"] ?? array() as $s ) { $out[] = dirname( $f ) . "/" . $s["file"]; }
		echo implode( "\n", array_unique( array_filter( $out ) ) );')
	php "$TESTS_DIR/metadata.php" "${files[@]}"
}

for state in true false; do
	echo "== NWPH_STRIP_IMAGE_METADATA $state"
	wpc config set NWPH_STRIP_IMAGE_METADATA "$state" --raw --type=constant --quiet
	sleep "$CONFIG_DELAY"

	read -r CLIENT_SIDE ID < <(editor_upload)
	if [[ -z "${ID:-}" ]]; then
		fail "Upload through the block editor: $(tr '\n' ';' < "$TMP_DIR/errors")"
		continue
	fi
	echo "$ID" >> "$TMP_DIR/attachments"
	check "Upload through the block editor without editor errors" "" "$(cat "$TMP_DIR/errors")"
	REPORT="$(attachment_report "$ID")"

	if [[ "$state" == true ]]; then
		check "Client-side media processing off (server processes the upload)" false "$CLIENT_SIDE"
		if ! grep -qv ' none$' <<< "$REPORT"; then pass "All $(wc -l <<< "$REPORT") files without metadata"; else fail "Files with metadata: $(tr '\n' ';' <<< "$REPORT")"; fi
		check "Original rotated by EXIF orientation (fixture 400x200)" "$NAME.jpg 200x400 none" "$(grep "^$NAME.jpg " <<< "$REPORT")"
	else
		check "Client-side media processing back to core default" true "$CLIENT_SIDE"
		if grep -q 'gps' <<< "$REPORT"; then pass "Sensitivity: GPS found when the feature is off"; else fail "Sensitivity: no GPS found when the feature is off: $(tr '\n' ';' <<< "$REPORT")"; fi
	fi
done

echo
echo "Passed: $PASSES  Failed: $FAILS"
[[ "$FAILS" -eq 0 ]] && exit 0 || exit 1
