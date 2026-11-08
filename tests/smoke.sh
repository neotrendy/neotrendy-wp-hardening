#!/usr/bin/env bash
#
# Smoke tests for neotrendy-wp-hardening against a local WordPress installation.
#
# Usage: tests/smoke.sh
# Environment: SITE_URL (default http://wordpress.localhost), WP_PATH (default /srv/wordpress.localhost),
#              CONFIG_DELAY seconds to wait after editing wp-config.php so OPcache picks it up (default 3).
#
# The plugin must already be loaded (mu-plugin or active plugin). wp-config.php is backed up and
# restored on exit; temporary users, a post and an attachment are created and deleted.
# HTTP checks of xmlrpc.php are skipped (SKIP) when the web server blocks it (403/404); the same
# behaviour is then verified in PHP only.

set -uo pipefail

SITE_URL="${SITE_URL:-http://wordpress.localhost}"
SITE_URL="${SITE_URL%/}"
WP_PATH="${WP_PATH:-/srv/wordpress.localhost}"
CONFIG_DELAY="${CONFIG_DELAY:-3}"

PASSES=0
FAILS=0
SKIPS=0

wpc() { wp --path="$WP_PATH" "$@"; }

pass() { echo "PASS  $*"; PASSES=$((PASSES + 1)); }
fail() { echo "FAIL  $*"; FAILS=$((FAILS + 1)); }
skip() { echo "SKIP  $*"; SKIPS=$((SKIPS + 1)); }

check() { # description expected actual
	if [[ "$3" == "$2" ]]; then pass "$1"; else fail "$1 (expected '$2', got '$3')"; fi
}

check_contains() { # description needle haystack
	if [[ "$3" == *"$2"* ]]; then pass "$1"; else fail "$1 ('$2' not found)"; fi
}

check_absent() { # description needle haystack
	if [[ "$3" != *"$2"* ]]; then pass "$1"; else fail "$1 ('$2' found)"; fi
}

http_code() { curl -s -o /dev/null -w '%{http_code}' "$@"; }

# Runs PHP code with WP-CLI and prints the value after the RESULT: marker, ignoring notices.
# Usage: wp_eval [--wp-cli-option ...] <php-code> [arg ...]; args are read in PHP with nwph_arg( N ).
wp_eval() {
	local opts=() code i=0
	while [[ "${1:-}" == --* ]]; do opts+=("$1"); shift; done
	code="$1"; shift
	local env_vars=()
	for arg in "$@"; do env_vars+=("NWPH_SMOKE_ARG$i=$arg"); i=$((i + 1)); done
	env "${env_vars[@]}" wp --path="$WP_PATH" "${opts[@]}" eval \
		'function nwph_arg( $n ) { return (string) getenv( "NWPH_SMOKE_ARG$n" ); } '"$code" 2>/dev/null \
		| sed -n 's/^RESULT://p' | tail -n 1
}

wait_config() { sleep "$CONFIG_DELAY"; }

set_const() { wpc config set "$1" "$2" --raw --type=constant --quiet; }
set_const_str() { wpc config set "$1" "$2" --type=constant --quiet; }
del_const() { wpc config has "$1" --type=constant 2>/dev/null && wpc config delete "$1" --type=constant --quiet; return 0; }

FEATURES=(BLOCK_USER_ENUMERATION DISABLE_XMLRPC DISABLE_APPLICATION_PASSWORDS SITEMAP_HIDE_USERS WEBP_UPLOADS SITE_HEALTH_CHECKS)

all_features() { # true|false
	local f
	for f in "${FEATURES[@]}"; do set_const "NWPH_$f" "$1"; done
	wait_config
}

# --- Setup -------------------------------------------------------------------

CONFIG_FILE="$(wpc config path)" || { echo "FAIL  wp-config.php not found in $WP_PATH"; exit 1; }
TMP_DIR="$(mktemp -d)"
chmod 700 "$TMP_DIR"
cp -p "$CONFIG_FILE" "$TMP_DIR/wp-config.php.bak"
DEBUG_LOG="$WP_PATH/wp-content/debug.log"
[[ -e "$DEBUG_LOG" ]] && DEBUG_LOG_EXISTED=1 || DEBUG_LOG_EXISTED=0

EDITOR_ID=""
SUBSCRIBER_ID=""
POST_ID=""

cleanup() {
	[[ -n "$POST_ID" ]] && wpc post delete "$POST_ID" --force --quiet
	[[ -n "$EDITOR_ID" ]] && wpc user delete "$EDITOR_ID" --yes --quiet
	[[ -n "$SUBSCRIBER_ID" ]] && wpc user delete "$SUBSCRIBER_ID" --yes --quiet
	cat "$TMP_DIR/wp-config.php.bak" > "$CONFIG_FILE"
	[[ "$DEBUG_LOG_EXISTED" -eq 0 ]] && rm -f "$DEBUG_LOG"
	rm -rf "$TMP_DIR"
}
trap cleanup EXIT

if [[ "$(wp_eval 'echo "RESULT:" . ( defined( "NWPH_VERSION" ) ? "yes" : "no" );')" != "yes" ]]; then
	echo "FAIL  neotrendy-wp-hardening is not loaded in $WP_PATH (symlink it to wp-content/mu-plugins/ or activate it)"
	exit 1
fi

ADMIN_ID="$(wpc user list --role=administrator --field=ID | head -n 1)"
ADMIN_SLUG="$(wpc user get "$ADMIN_ID" --field=user_nicename)"
EDITOR_ID="$(wpc user create "nwph-smoke-editor-$$" "nwph-smoke-editor-$$@example.invalid" --role=editor --porcelain)"
SUBSCRIBER_ID="$(wpc user create "nwph-smoke-subscriber-$$" "nwph-smoke-subscriber-$$@example.invalid" --role=subscriber --porcelain)"
POST_ID="$(wpc post create --post_type=post --post_status=publish --post_title="NWPH smoke test $$" --post_author="$ADMIN_ID" --ping_status=open --comment_status=open --porcelain)"
POST_URL="$(wpc post url "$POST_ID")"

echo "Site: $SITE_URL  Admin ID: $ADMIN_ID  Test post: $POST_URL"

rest_status() { # user_id route [query-string]
	wp_eval --user="$1" '
		$r = new WP_REST_Request( "GET", nwph_arg( 0 ) );
		parse_str( nwph_arg( 1 ), $q );
		$r->set_query_params( $q );
		echo "RESULT:" . rest_do_request( $r )->get_status();' "$2" "${3:-}"
}

xmlrpc_call() { # method
	curl -s -H 'Content-Type: text/xml' --data "<?xml version=\"1.0\"?><methodCall><methodName>$1</methodName><params></params></methodCall>" "$SITE_URL/xmlrpc.php"
}

xmlrpc_http_status() { http_code -H 'Content-Type: text/xml' --data '<?xml version="1.0"?><methodCall><methodName>system.listMethods</methodName><params></params></methodCall>' "$SITE_URL/xmlrpc.php"; }

# Method names from system.listMethods over HTTP, excluding the system.* methods built into the
# IXR server. Prints "invalid-response" unless the server sent an XML-RPC method response.
xmlrpc_methods() {
	local body
	body="$(xmlrpc_call system.listMethods)"
	if [[ "$body" != *"<methodResponse>"* ]]; then echo "invalid-response"; return; fi
	grep -o '<string>[^<]*</string>' <<< "$body" | sed 's/<[^>]*>//g' | grep -v '^system\.' | tr '\n' ' '
}

# The same list computed in PHP from the server class xmlrpc.php would use.
xmlrpc_methods_php() {
	wp_eval '
		require_once ABSPATH . WPINC . "/class-IXR.php";
		require_once ABSPATH . WPINC . "/class-wp-xmlrpc-server.php";
		$server = new wp_xmlrpc_server();
		echo "RESULT:" . implode( " ", array_filter( array_keys( $server->methods ), fn( $m ) => ! str_starts_with( $m, "system." ) ) );'
}

xmlrpc_enabled_php() { wp_eval 'echo "RESULT:" . var_export( (bool) apply_filters( "xmlrpc_enabled", true ), true );'; }

login_error() { # username
	curl -s -b 'wordpress_test_cookie=WP%20Cookie%20check' --data-urlencode "log=$1" --data-urlencode 'pwd=nwph-wrong-password' \
		--data-urlencode 'testcookie=1' "$SITE_URL/wp-login.php" | tr '\n' ' ' | grep -o '<div id="login_error"[^>]*>.*</div>' | sed 's/<\/div>.*//'
}

import_test_image() { # prints attachment ID
	php -r '$i = imagecreatetruecolor( 1600, 1200 ); imagefilledrectangle( $i, 0, 0, 800, 600, imagecolorallocate( $i, 200, 40, 40 ) ); imagejpeg( $i, $argv[1], 85 );' "$TMP_DIR/nwph-smoke.jpg"
	wpc media import "$TMP_DIR/nwph-smoke.jpg" --porcelain 2>/dev/null
}

subsize_mimes() { # attachment ID
	wp_eval '$m = wp_get_attachment_metadata( (int) nwph_arg( 0 ) ); $t = array_unique( array_column( $m["sizes"] ?? array(), "mime-type" ) ); sort( $t ); echo "RESULT:" . implode( ",", $t );' "$1"
}

original_mime() { # attachment ID
	wp_eval 'echo "RESULT:" . wp_check_filetype( wp_get_original_image_path( (int) nwph_arg( 0 ) ) )["type"];' "$1"
}

full_size_mime() { # attachment ID -> MIME type of the file used as the full size
	wp_eval 'echo "RESULT:" . wp_check_filetype( get_attached_file( (int) nwph_arg( 0 ) ) )["type"];' "$1"
}

# REST field the block editor reads to choose the output format for client-side processing.
rest_output_format() { # attachment ID
	wp_eval --user="$ADMIN_ID" '
		$r = new WP_REST_Request( "GET", "/wp/v2/media/" . nwph_arg( 0 ) );
		$r->set_query_params( array( "context" => "edit", "_fields" => "image_output_format" ) );
		$d = rest_do_request( $r )->get_data();
		echo "RESULT:" . ( array_key_exists( "image_output_format", (array) $d ) ? var_export( $d["image_output_format"], true ) : "missing" );' "$1"
}

site_health() { # test id -> status, or "missing"
	wp_eval '
		require_once ABSPATH . "wp-admin/includes/class-wp-site-health.php";
		$t = WP_Site_Health::get_tests()["direct"][ nwph_arg( 0 ) ] ?? null;
		echo "RESULT:" . ( $t ? call_user_func( $t["test"] )["status"] : "missing" );' "$1"
}

SH_TESTS=(nwph_disallow_file_edit nwph_debug_display nwph_debug_log nwph_force_ssl_admin nwph_environment_type nwph_hardening_features)

# --- Features enabled --------------------------------------------------------

echo "== All features enabled"
all_features true

check "REST /wp/v2/users anonymous -> 401" 401 "$(http_code "$SITE_URL/wp-json/wp/v2/users")"
check "REST /wp/v2/users/$ADMIN_ID anonymous -> 401" 401 "$(http_code "$SITE_URL/wp-json/wp/v2/users/$ADMIN_ID")"
check "REST ?rest_route=/wp/v2/users anonymous -> 401" 401 "$(http_code "$SITE_URL/?rest_route=/wp/v2/users")"
check "REST /wp/v2/users as administrator -> 200" 200 "$(rest_status "$ADMIN_ID" /wp/v2/users)"
check "REST /wp/v2/users?who=authors as editor -> 200" 200 "$(rest_status "$EDITOR_ID" /wp/v2/users who=authors)"
check "REST /wp/v2/users/$ADMIN_ID as editor -> 200" 200 "$(rest_status "$EDITOR_ID" "/wp/v2/users/$ADMIN_ID")"
check "REST /wp/v2/users as subscriber -> 403" 403 "$(rest_status "$SUBSCRIBER_ID" /wp/v2/users)"
check "REST /wp/v2/users/me as subscriber -> 200" 200 "$(rest_status "$SUBSCRIBER_ID" /wp/v2/users/me)"
check "?author=$ADMIN_ID anonymous -> 404" 404 "$(http_code "$SITE_URL/?author=$ADMIN_ID")"
check "/author/$ADMIN_SLUG/ anonymous -> 404" 404 "$(http_code "$SITE_URL/author/$ADMIN_SLUG/")"
OEMBED="$(curl -s -G --data-urlencode "url=$POST_URL" "$SITE_URL/wp-json/oembed/1.0/embed")"
check_contains "oEmbed response returned" '"title"' "$OEMBED"
check_absent "oEmbed without author_name" 'author_name' "$OEMBED"
check_absent "oEmbed without author_url" 'author_url' "$OEMBED"
check "wp_authenticate unknown user -> generic error" nwph_invalid_credentials \
	"$(wp_eval 'echo "RESULT:" . wp_authenticate( "nwph-no-such-user", "x" )->get_error_code();')"
check "wp_authenticate wrong password -> generic error" nwph_invalid_credentials \
	"$(wp_eval 'echo "RESULT:" . wp_authenticate( get_userdata( (int) nwph_arg( 0 ) )->user_login, "nwph-wrong-password" )->get_error_code();' "$ADMIN_ID")"
ERR_UNKNOWN="$(login_error "nwph-no-such-user")"
ERR_KNOWN="$(login_error "$(wpc user get "$ADMIN_ID" --field=user_login)")"
check_contains "wp-login.php shows generic error" 'password is incorrect' "$ERR_UNKNOWN"
check "wp-login.php error identical for unknown and existing user" "$ERR_UNKNOWN" "$ERR_KNOWN"

check "XML-RPC disabled (xmlrpc_enabled filter)" false "$(xmlrpc_enabled_php)"
check "XML-RPC server class without WordPress methods" "" "$(xmlrpc_methods_php)"
XMLRPC_STATUS="$(xmlrpc_http_status)"
if [[ "$XMLRPC_STATUS" == 403 || "$XMLRPC_STATUS" == 404 ]]; then
	skip "XML-RPC HTTP checks: xmlrpc.php is blocked by the web server (HTTP $XMLRPC_STATUS)"
else
	check "XML-RPC system.listMethods over HTTP without WordPress methods" "" "$(xmlrpc_methods)"
	check_contains "XML-RPC wp.getUsersBlogs -> fault" 'faultCode' "$(xmlrpc_call wp.getUsersBlogs)"
fi
check_absent "X-Pingback header absent" 'x-pingback' "$(curl -sI "$POST_URL" | tr 'A-Z' 'a-z')"
check_absent "RSD link absent" 'EditURI' "$(curl -s "$SITE_URL/")"

check "Application passwords unavailable" false \
	"$(wp_eval '$_SERVER["HTTPS"] = "on"; echo "RESULT:" . var_export( wp_is_application_passwords_available(), true );')"

check_absent "Sitemap index without users" 'wp-sitemap-users-' "$(curl -s "$SITE_URL/wp-sitemap.xml")"
check "Users sitemap -> 404" 404 "$(http_code "$SITE_URL/wp-sitemap-users-1.xml")"

TAX_EXPR='echo "RESULT:" . ( isset( wp_sitemaps_get_server()->registry->get_provider( "taxonomies" )->get_object_subtypes()["category"] ) ? "listed" : "excluded" );'
check "Sitemap taxonomies unchanged by default" listed "$(wp_eval "$TAX_EXPR")"
check "nwph_sitemap_excluded_taxonomies removes category" excluded \
	"$(wp_eval "add_filter( 'nwph_sitemap_excluded_taxonomies', fn() => array( 'category' ) ); $TAX_EXPR")"

if [[ "$(wp_eval 'echo "RESULT:" . ( wp_image_editor_supports( array( "mime_type" => "image/webp" ) ) ? "yes" : "no" );')" == "yes" ]]; then
	ATTACHMENT_ID="$(import_test_image)"
	check "JPEG upload: sub-sizes are WebP" image/webp "$(subsize_mimes "$ATTACHMENT_ID")"
	check "JPEG upload: full size is WebP" image/webp "$(full_size_mime "$ATTACHMENT_ID")"
	check "JPEG upload: original kept as JPEG" image/jpeg "$(original_mime "$ATTACHMENT_ID")"
	check "REST image_output_format for the block editor -> image/webp" "'image/webp'" "$(rest_output_format "$ATTACHMENT_ID")"
	wpc post delete "$ATTACHMENT_ID" --force --quiet
else
	fail "Image editor does not support WebP on this server, WebP test skipped"
fi

for t in "${SH_TESTS[@]}"; do
	STATUS="$(site_health "$t")"
	if [[ "$STATUS" == good || "$STATUS" == recommended ]]; then pass "Site Health test $t registered ($STATUS)"; else fail "Site Health test $t registered (got '$STATUS')"; fi
done
check "Site Health: all features enabled -> good" good "$(site_health nwph_hardening_features)"

check "Filter nwph_disable_xmlrpc is overridden by the constant" true \
	"$(wp_eval 'add_filter( "nwph_disable_xmlrpc", "__return_false" ); echo "RESULT:" . var_export( nwph_is_enabled( "disable_xmlrpc" ), true );')"
del_const NWPH_DISABLE_XMLRPC
check "Filter nwph_disable_xmlrpc disables the feature without a constant" false \
	"$(wp_eval 'add_filter( "nwph_disable_xmlrpc", "__return_false" ); echo "RESULT:" . var_export( nwph_is_enabled( "disable_xmlrpc" ), true );')"
check "Feature enabled by default without constant or filter" true \
	"$(wp_eval 'echo "RESULT:" . var_export( nwph_is_enabled( "disable_xmlrpc" ), true );')"

# --- Site Health with specific wp-config.php values ---------------------------

echo "== Site Health states"
del_const DISALLOW_FILE_MODS
del_const DISALLOW_FILE_EDIT
del_const FORCE_SSL_ADMIN
del_const WP_ENVIRONMENT_TYPE
set_const WP_DEBUG false
check "DISALLOW_FILE_EDIT missing -> recommended" recommended "$(site_health nwph_disallow_file_edit)"
check "WP_ENVIRONMENT_TYPE missing -> recommended" recommended "$(site_health nwph_environment_type)"
check "FORCE_SSL_ADMIN missing in production -> recommended" recommended "$(site_health nwph_force_ssl_admin)"
check "WP_DEBUG off -> debug display good" good "$(site_health nwph_debug_display)"
check "WP_DEBUG off -> debug log good" good "$(site_health nwph_debug_log)"

set_const DISALLOW_FILE_EDIT true
set_const FORCE_SSL_ADMIN true
set_const_str WP_ENVIRONMENT_TYPE production
set_const WP_DEBUG true
set_const WP_DEBUG_DISPLAY false
set_const WP_DEBUG_LOG true
check "DISALLOW_FILE_EDIT true -> good" good "$(site_health nwph_disallow_file_edit)"
check "WP_ENVIRONMENT_TYPE set -> good" good "$(site_health nwph_environment_type)"
check "FORCE_SSL_ADMIN true -> good" good "$(site_health nwph_force_ssl_admin)"
check "WP_DEBUG_DISPLAY false -> good" good "$(site_health nwph_debug_display)"
check "WP_DEBUG_LOG true (wp-content/debug.log) -> recommended" recommended "$(site_health nwph_debug_log)"

set_const_str WP_DEBUG_LOG "$TMP_DIR/debug.log"
set_const WP_DEBUG_DISPLAY true
check "WP_DEBUG_LOG outside web root -> good" good "$(site_health nwph_debug_log)"
check "WP_DEBUG_DISPLAY true in production -> recommended" recommended "$(site_health nwph_debug_display)"
set_const_str WP_ENVIRONMENT_TYPE local
check "WP_DEBUG_DISPLAY true in local -> good" good "$(site_health nwph_debug_display)"

cat "$TMP_DIR/wp-config.php.bak" > "$CONFIG_FILE"

# --- Features disabled -------------------------------------------------------

echo "== All features disabled"
all_features false

check "REST /wp/v2/users anonymous -> 200" 200 "$(http_code "$SITE_URL/wp-json/wp/v2/users")"
check "REST /wp/v2/users as subscriber -> 200 (core behaviour)" 200 "$(rest_status "$SUBSCRIBER_ID" /wp/v2/users)"
check "?author=$ADMIN_ID anonymous -> 301 (core redirect)" 301 "$(http_code "$SITE_URL/?author=$ADMIN_ID")"
check "/author/$ADMIN_SLUG/ anonymous -> 200" 200 "$(http_code "$SITE_URL/author/$ADMIN_SLUG/")"
check_contains "oEmbed with author_name" 'author_name' "$(curl -s -G --data-urlencode "url=$POST_URL" "$SITE_URL/wp-json/oembed/1.0/embed")"
check "wp_authenticate unknown user -> core error" invalid_username \
	"$(wp_eval 'echo "RESULT:" . wp_authenticate( "nwph-no-such-user", "x" )->get_error_code();')"

check "XML-RPC enabled (xmlrpc_enabled filter)" true "$(xmlrpc_enabled_php)"
check_contains "XML-RPC server class lists WordPress methods" 'wp.getUsersBlogs' "$(xmlrpc_methods_php)"
XMLRPC_STATUS="$(xmlrpc_http_status)"
if [[ "$XMLRPC_STATUS" == 403 || "$XMLRPC_STATUS" == 404 ]]; then
	skip "XML-RPC HTTP checks: xmlrpc.php is blocked by the web server (HTTP $XMLRPC_STATUS)"
else
	check_contains "XML-RPC system.listMethods over HTTP lists WordPress methods" 'wp.getUsersBlogs' "$(xmlrpc_methods)"
fi
check_contains "X-Pingback header present" 'x-pingback' "$(curl -sI "$POST_URL" | tr 'A-Z' 'a-z')"
check_contains "RSD link present" 'EditURI' "$(curl -s "$SITE_URL/")"

check "Application passwords available" true \
	"$(wp_eval '$_SERVER["HTTPS"] = "on"; echo "RESULT:" . var_export( wp_is_application_passwords_available(), true );')"

check_contains "Sitemap index lists users" 'wp-sitemap-users-' "$(curl -s "$SITE_URL/wp-sitemap.xml")"
check "Users sitemap -> 200" 200 "$(http_code "$SITE_URL/wp-sitemap-users-1.xml")"

ATTACHMENT_ID="$(import_test_image)"
check "JPEG upload: sub-sizes stay JPEG" image/jpeg "$(subsize_mimes "$ATTACHMENT_ID")"
check "JPEG upload: full size stays JPEG" image/jpeg "$(full_size_mime "$ATTACHMENT_ID")"
check "REST image_output_format for the block editor -> null" NULL "$(rest_output_format "$ATTACHMENT_ID")"
wpc post delete "$ATTACHMENT_ID" --force --quiet

for t in "${SH_TESTS[@]}"; do
	check "Site Health test $t not registered" missing "$(site_health "$t")"
done

# --- Summary -----------------------------------------------------------------

echo
echo "Passed: $PASSES  Failed: $FAILS  Skipped: $SKIPS"
[[ "$FAILS" -eq 0 ]] && exit 0 || exit 1
