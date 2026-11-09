<?php
/**
 * Plugin Name:       Neotrendy WP Hardening
 * Plugin URI:        https://github.com/neotrendy/neotrendy-wp-hardening
 * Description:       Small, readable, secure defaults: blocks user enumeration, disables XML-RPC and application passwords, hides users from the sitemap, saves uploaded images as WebP without metadata and adds Site Health checks. No settings screen.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Neotrendy s. r. o.
 * Author URI:        https://www.neotrendy.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       neotrendy-wp-hardening
 * Update URI:        https://github.com/neotrendy/neotrendy-wp-hardening
 *
 * @package NeotrendyWpHardening
 */

defined( 'ABSPATH' ) || exit;

// Load once, even if installed both as a must-use plugin and as a regular plugin.
if ( defined( 'NWPH_VERSION' ) ) {
	return;
}

define( 'NWPH_VERSION', '0.2.0' );

// Must-use plugins are loaded before the "muplugins_loaded" action fires.
define( 'NWPH_IS_MU_PLUGIN', ! did_action( 'muplugins_loaded' ) );

if ( NWPH_IS_MU_PLUGIN ) {
	load_muplugin_textdomain( 'neotrendy-wp-hardening' );
}

/**
 * Feature keys. Each key maps to the constant NWPH_<KEY> and the filter nwph_<key>.
 *
 * @return string[]
 */
function nwph_feature_keys(): array {
	return array(
		'block_user_enumeration',
		'disable_xmlrpc',
		'disable_application_passwords',
		'sitemap_hide_users',
		'webp_uploads',
		'site_health_checks',
		'strip_image_metadata',
	);
}

/**
 * Whether a feature is enabled. A constant in wp-config.php takes precedence over the filter.
 *
 * @param string $feature Feature key, see nwph_feature_keys().
 */
function nwph_is_enabled( string $feature ): bool {
	$constant = 'NWPH_' . strtoupper( $feature );

	if ( defined( $constant ) ) {
		return (bool) constant( $constant );
	}

	return (bool) apply_filters( 'nwph_' . $feature, true );
}

/*
 * 1. User enumeration.
 */

/**
 * Restricts /wp/v2/users and /wp/v2/users/<id> to users who can list users or use the editor.
 *
 * @param WP_REST_Response|WP_HTTP_Response|WP_Error|mixed $response Result to send to the client.
 * @param array                                            $handler  Route handler.
 * @param WP_REST_Request                                  $request  Request.
 * @return mixed
 */
function nwph_rest_restrict_users( $response, $handler, $request ) {
	if ( is_wp_error( $response ) || ! nwph_is_enabled( 'block_user_enumeration' ) ) {
		return $response;
	}

	if ( ! preg_match( '#^/wp/v2/users(?:/(\d+))?/?$#i', $request->get_route(), $matches ) ) {
		return $response;
	}

	// The block editor (author selector, post author blocks) needs this for users who can edit posts.
	if ( current_user_can( 'list_users' ) || current_user_can( 'edit_posts' ) ) {
		return $response;
	}

	if ( isset( $matches[1] ) && current_user_can( 'edit_user', (int) $matches[1] ) ) {
		return $response;
	}

	return new WP_Error(
		'rest_user_cannot_view',
		__( 'Sorry, you are not allowed to list users.', 'neotrendy-wp-hardening' ),
		array( 'status' => rest_authorization_required_code() )
	);
}
add_filter( 'rest_request_before_callbacks', 'nwph_rest_restrict_users', 10, 3 );

/**
 * Returns 404 for anonymous author archives and ?author=N, before redirect_canonical() reveals the slug.
 */
function nwph_block_author_archives(): void {
	global $wp_query;

	if ( is_user_logged_in() || ! nwph_is_enabled( 'block_user_enumeration' ) ) {
		return;
	}

	if ( ! is_author() && '' === (string) get_query_var( 'author' ) && '' === (string) get_query_var( 'author_name' ) ) {
		return;
	}

	$wp_query->set_404();
	status_header( 404 );
	nocache_headers();
}
add_action( 'template_redirect', 'nwph_block_author_archives', 0 );

/**
 * Removes author data from oEmbed responses.
 *
 * @param array $data oEmbed response data.
 */
function nwph_oembed_remove_author( $data ) {
	if ( is_array( $data ) && nwph_is_enabled( 'block_user_enumeration' ) ) {
		unset( $data['author_name'], $data['author_url'] );
	}

	return $data;
}
add_filter( 'oembed_response_data', 'nwph_oembed_remove_author', 99 );

/**
 * Replaces "unknown user" and "wrong password" errors with one message that does not reveal whether an account exists.
 *
 * @param WP_User|WP_Error|null $user Authentication result.
 * @return WP_User|WP_Error|null
 */
function nwph_generic_login_error( $user ) {
	if ( ! is_wp_error( $user ) || ! nwph_is_enabled( 'block_user_enumeration' ) ) {
		return $user;
	}

	$revealing = array( 'invalid_username', 'invalid_email', 'incorrect_password' );

	if ( ! array_intersect( $revealing, $user->get_error_codes() ) ) {
		return $user;
	}

	return new WP_Error(
		'nwph_invalid_credentials',
		sprintf(
			/* translators: %s: Lost password URL. */
			__( '<strong>Error:</strong> The username, email address or password is incorrect. <a href="%s">Lost your password?</a>', 'neotrendy-wp-hardening' ),
			esc_url( wp_lostpassword_url() )
		)
	);
}
add_filter( 'authenticate', 'nwph_generic_login_error', 100 );

/**
 * Keeps the login form "shake" for the generic error.
 *
 * @param string[] $codes Error codes.
 * @return string[]
 */
function nwph_shake_error_codes( $codes ) {
	$codes[] = 'nwph_invalid_credentials';
	return $codes;
}
add_filter( 'shake_error_codes', 'nwph_shake_error_codes' );

/*
 * 2. XML-RPC.
 */

/**
 * Disables XML-RPC authenticated methods.
 *
 * @param bool $enabled Whether XML-RPC is enabled.
 */
function nwph_xmlrpc_enabled( $enabled ) {
	return nwph_is_enabled( 'disable_xmlrpc' ) ? false : $enabled;
}
add_filter( 'xmlrpc_enabled', 'nwph_xmlrpc_enabled', 99 );

/**
 * Removes all XML-RPC methods, including pingbacks.
 *
 * @param array $methods XML-RPC methods.
 */
function nwph_xmlrpc_methods( $methods ) {
	return nwph_is_enabled( 'disable_xmlrpc' ) ? array() : $methods;
}
add_filter( 'xmlrpc_methods', 'nwph_xmlrpc_methods', 99 );

/**
 * Removes the X-Pingback header.
 *
 * @param string[] $headers HTTP headers.
 * @return string[]
 */
function nwph_remove_pingback_header( $headers ) {
	if ( nwph_is_enabled( 'disable_xmlrpc' ) ) {
		unset( $headers['X-Pingback'] );
	}

	return $headers;
}
add_filter( 'wp_headers', 'nwph_remove_pingback_header', 99 );

/**
 * Removes the RSD link from <head>.
 */
function nwph_remove_rsd_link(): void {
	if ( nwph_is_enabled( 'disable_xmlrpc' ) ) {
		remove_action( 'wp_head', 'rsd_link' );
	}
}
add_action( 'wp_head', 'nwph_remove_rsd_link', 0 );

/*
 * 3. Application passwords.
 */

/**
 * Disables application passwords.
 *
 * @param bool $available Whether application passwords are available.
 */
function nwph_application_passwords_available( $available ) {
	return nwph_is_enabled( 'disable_application_passwords' ) ? false : $available;
}
add_filter( 'wp_is_application_passwords_available', 'nwph_application_passwords_available', 99 );

/*
 * 4. Core sitemap.
 */

/**
 * Removes the users sitemap provider.
 *
 * @param WP_Sitemaps_Provider|false $provider Provider instance.
 * @param string                     $name     Provider name.
 * @return WP_Sitemaps_Provider|false
 */
function nwph_sitemap_remove_users( $provider, $name ) {
	if ( 'users' === $name && nwph_is_enabled( 'sitemap_hide_users' ) ) {
		return false;
	}

	return $provider;
}
add_filter( 'wp_sitemaps_add_provider', 'nwph_sitemap_remove_users', 10, 2 );

/**
 * Removes taxonomies listed by the nwph_sitemap_excluded_taxonomies filter (empty by default).
 *
 * @param WP_Taxonomy[] $taxonomies Taxonomy objects keyed by name.
 * @return WP_Taxonomy[]
 */
function nwph_sitemap_exclude_taxonomies( $taxonomies ) {
	$excluded = (array) apply_filters( 'nwph_sitemap_excluded_taxonomies', array() );

	return array_diff_key( $taxonomies, array_flip( array_map( 'strval', $excluded ) ) );
}
add_filter( 'wp_sitemaps_taxonomies', 'nwph_sitemap_exclude_taxonomies' );

/*
 * 5. WebP for uploaded images.
 */

/**
 * Saves JPEG and PNG images (full size and sub-sizes) as WebP when the image editor supports it.
 * The uploaded file is kept as the original image.
 *
 * @param array<string, string> $formats Source MIME type => output MIME type.
 * @return array<string, string>
 */
function nwph_webp_output_format( $formats ) {
	if ( ! nwph_is_enabled( 'webp_uploads' ) || ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		return $formats;
	}

	foreach ( array( 'image/jpeg', 'image/png' ) as $mime_type ) {
		// The REST API (client-side media processing) passes an identity mapping, treat it as "not set".
		if ( ! isset( $formats[ $mime_type ] ) || $formats[ $mime_type ] === $mime_type ) {
			$formats[ $mime_type ] = 'image/webp';
		}
	}

	return $formats;
}
add_filter( 'image_editor_output_format', 'nwph_webp_output_format' );

/*
 * 6. Site Health checks (informational only).
 */

/**
 * Registers the Site Health tests.
 *
 * @param array $tests Site Health tests.
 */
function nwph_site_status_tests( $tests ) {
	if ( ! nwph_is_enabled( 'site_health_checks' ) ) {
		return $tests;
	}

	$own = array(
		'nwph_disallow_file_edit' => __( 'File editor', 'neotrendy-wp-hardening' ),
		'nwph_debug_display'      => __( 'Error display', 'neotrendy-wp-hardening' ),
		'nwph_debug_log'          => __( 'Debug log location', 'neotrendy-wp-hardening' ),
		'nwph_force_ssl_admin'    => __( 'HTTPS for administration', 'neotrendy-wp-hardening' ),
		'nwph_environment_type'   => __( 'Environment type', 'neotrendy-wp-hardening' ),
		'nwph_hardening_features' => __( 'Neotrendy WP Hardening features', 'neotrendy-wp-hardening' ),
	);

	foreach ( $own as $id => $label ) {
		$tests['direct'][ $id ] = array(
			'label' => $label,
			'test'  => 'nwph_test_' . substr( $id, 5 ),
		);
	}

	return $tests;
}
add_filter( 'site_status_tests', 'nwph_site_status_tests' );

/**
 * Builds a Site Health result.
 *
 * @param string $test    Test ID.
 * @param bool   $passed  Whether the check passed.
 * @param string $good    Label when passed.
 * @param string $bad     Label when not passed.
 * @param string $what    What is checked (plain text).
 * @param string $why     Why it matters (plain text).
 * @param string $snippet Code that fixes it (plain text), shown when not passed.
 * @param string $extra   Optional additional HTML, already escaped.
 */
function nwph_site_health_result( string $test, bool $passed, string $good, string $bad, string $what, string $why, string $snippet, string $extra = '' ): array {
	$description = '<p>' . esc_html( $what ) . '</p><p>' . esc_html( $why ) . '</p>' . $extra;

	if ( ! $passed ) {
		$description .= '<p>' . esc_html__( 'How to fix: add this to wp-config.php, above the line "That\'s all, stop editing!":', 'neotrendy-wp-hardening' ) . '</p>'
			. '<pre><code>' . esc_html( $snippet ) . '</code></pre>';
	}

	return array(
		'label'       => $passed ? $good : $bad,
		'status'      => $passed ? 'good' : 'recommended',
		'badge'       => array(
			'label' => __( 'Security', 'neotrendy-wp-hardening' ),
			'color' => 'blue',
		),
		'description' => $description,
		'actions'     => '',
		'test'        => $test,
	);
}

/**
 * Whether the environment is local or development.
 */
function nwph_is_dev_environment(): bool {
	return in_array( wp_get_environment_type(), array( 'local', 'development' ), true );
}

/**
 * Site Health: DISALLOW_FILE_EDIT.
 */
function nwph_test_disallow_file_edit(): array {
	$passed = ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) || ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS );

	return nwph_site_health_result(
		'nwph_disallow_file_edit',
		$passed,
		__( 'The theme and plugin file editor is disabled', 'neotrendy-wp-hardening' ),
		__( 'The theme and plugin file editor is enabled', 'neotrendy-wp-hardening' ),
		__( 'Checks whether the built-in theme and plugin file editor is disabled with DISALLOW_FILE_EDIT.', 'neotrendy-wp-hardening' ),
		__( 'An attacker who gains access to an administrator account can use the editor to run arbitrary PHP code on the server.', 'neotrendy-wp-hardening' ),
		"define( 'DISALLOW_FILE_EDIT', true );"
	);
}

/**
 * Site Health: WP_DEBUG_DISPLAY outside local and development environments.
 */
function nwph_test_debug_display(): array {
	$display = WP_DEBUG && ( null === WP_DEBUG_DISPLAY ? (bool) ini_get( 'display_errors' ) : (bool) WP_DEBUG_DISPLAY );
	$passed  = ! $display || nwph_is_dev_environment();

	return nwph_site_health_result(
		'nwph_debug_display',
		$passed,
		__( 'PHP errors are not displayed to visitors', 'neotrendy-wp-hardening' ),
		__( 'PHP errors are displayed to visitors', 'neotrendy-wp-hardening' ),
		__( 'Checks that WP_DEBUG_DISPLAY is off unless the environment type is "local" or "development".', 'neotrendy-wp-hardening' ),
		__( 'Error messages can reveal file paths, plugin names and other details useful to an attacker, and they break page output.', 'neotrendy-wp-hardening' ),
		"define( 'WP_DEBUG_DISPLAY', false );\n@ini_set( 'display_errors', '0' );"
	);
}

/**
 * Returns the effective debug log path, or '' when WordPress does not write a log file.
 */
function nwph_debug_log_path(): string {
	if ( ! WP_DEBUG || ! WP_DEBUG_LOG ) {
		return '';
	}

	if ( in_array( strtolower( (string) WP_DEBUG_LOG ), array( 'true', '1' ), true ) ) {
		return WP_CONTENT_DIR . '/debug.log';
	}

	return is_string( WP_DEBUG_LOG ) ? WP_DEBUG_LOG : '';
}

/**
 * Site Health: WP_DEBUG_LOG should not write into a publicly accessible directory.
 */
function nwph_test_debug_log(): array {
	$path   = nwph_debug_log_path();
	$public = false;

	if ( '' !== $path ) {
		$dir   = realpath( dirname( $path ) );
		$dir   = $dir ? trailingslashit( wp_normalize_path( $dir ) ) : trailingslashit( wp_normalize_path( dirname( $path ) ) );
		$roots = array( ABSPATH, WP_CONTENT_DIR );

		if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			$roots[] = sanitize_text_field( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) );
		}

		foreach ( $roots as $root ) {
			$real = realpath( $root );
			$root = trailingslashit( wp_normalize_path( $real ? $real : $root ) );

			if ( str_starts_with( $dir, $root ) ) {
				$public = true;
				break;
			}
		}
	}

	$extra = '' === $path ? '' : '<p>' . sprintf(
		/* translators: %s: Log file path. */
		esc_html__( 'Current log file: %s', 'neotrendy-wp-hardening' ),
		'<code>' . esc_html( $path ) . '</code>'
	) . '</p>';

	return nwph_site_health_result(
		'nwph_debug_log',
		! $public,
		__( 'The debug log is not written to a public directory', 'neotrendy-wp-hardening' ),
		__( 'The debug log may be publicly accessible', 'neotrendy-wp-hardening' ),
		__( 'Checks that WP_DEBUG_LOG does not write into the web root, such as the default wp-content/debug.log.', 'neotrendy-wp-hardening' ),
		__( 'A log file inside the web root can often be downloaded by anyone and may contain paths, queries and personal data.', 'neotrendy-wp-hardening' ),
		"define( 'WP_DEBUG_LOG', '/path/outside/web-root/logs/wp-debug.log' );",
		$extra
	);
}

/**
 * Site Health: FORCE_SSL_ADMIN.
 */
function nwph_test_force_ssl_admin(): array {
	return nwph_site_health_result(
		'nwph_force_ssl_admin',
		force_ssl_admin() || nwph_is_dev_environment(),
		__( 'Administration and login use HTTPS, or HTTPS is not required here', 'neotrendy-wp-hardening' ),
		__( 'HTTPS is not enforced for administration and login', 'neotrendy-wp-hardening' ),
		__( 'Checks whether FORCE_SSL_ADMIN is enabled (not required in "local" and "development" environments).', 'neotrendy-wp-hardening' ),
		__( 'Without HTTPS, passwords and authentication cookies can be intercepted on the network.', 'neotrendy-wp-hardening' ),
		"define( 'FORCE_SSL_ADMIN', true );"
	);
}

/**
 * Site Health: WP_ENVIRONMENT_TYPE is set explicitly.
 */
function nwph_test_environment_type(): array {
	$explicit = defined( 'WP_ENVIRONMENT_TYPE' ) || false !== getenv( 'WP_ENVIRONMENT_TYPE' );

	$extra = '<p>' . sprintf(
		/* translators: %s: Environment type. */
		esc_html__( 'Current environment type: %s', 'neotrendy-wp-hardening' ),
		'<code>' . esc_html( wp_get_environment_type() ) . '</code>'
	) . '</p>';

	return nwph_site_health_result(
		'nwph_environment_type',
		$explicit,
		__( 'The environment type is set explicitly', 'neotrendy-wp-hardening' ),
		__( 'The environment type is not set explicitly', 'neotrendy-wp-hardening' ),
		__( 'Checks whether WP_ENVIRONMENT_TYPE is defined in wp-config.php or in the server environment.', 'neotrendy-wp-hardening' ),
		__( 'Plugins, themes and this plugin\'s checks behave differently in production, staging, development and local environments. Without an explicit value WordPress assumes "production".', 'neotrendy-wp-hardening' ),
		"define( 'WP_ENVIRONMENT_TYPE', 'production' ); // Or 'staging', 'development', 'local'.",
		$extra
	);
}

/**
 * Site Health: status of this plugin's features.
 */
function nwph_test_hardening_features(): array {
	$labels = array(
		'block_user_enumeration'        => __( 'Block user enumeration', 'neotrendy-wp-hardening' ),
		'disable_xmlrpc'                => __( 'Disable XML-RPC', 'neotrendy-wp-hardening' ),
		'disable_application_passwords' => __( 'Disable application passwords', 'neotrendy-wp-hardening' ),
		'sitemap_hide_users'            => __( 'Hide users from the sitemap', 'neotrendy-wp-hardening' ),
		'webp_uploads'                  => __( 'WebP for uploaded images', 'neotrendy-wp-hardening' ),
		'strip_image_metadata'          => __( 'Remove metadata (EXIF, GPS, XMP, IPTC) from uploaded images', 'neotrendy-wp-hardening' ),
	);

	$list     = '';
	$disabled = array();

	foreach ( $labels as $key => $label ) {
		$enabled = nwph_is_enabled( $key );
		$state   = $enabled ? __( 'enabled', 'neotrendy-wp-hardening' ) : __( 'disabled', 'neotrendy-wp-hardening' );
		$list   .= '<li>' . esc_html( $label ) . ' (<code>NWPH_' . esc_html( strtoupper( $key ) ) . '</code>): ' . esc_html( $state ) . '</li>';

		if ( ! $enabled ) {
			$disabled[] = "define( 'NWPH_" . strtoupper( $key ) . "', true ); // Or remove the line that sets it to false.";
		}
	}

	if ( nwph_is_enabled( 'strip_image_metadata' ) && ! extension_loaded( 'gd' ) ) {
		$list .= '<li>' . esc_html__( 'The PHP GD extension is not available, so metadata is not removed from uploaded images.', 'neotrendy-wp-hardening' ) . '</li>';
	}

	if ( nwph_is_enabled( 'webp_uploads' ) && ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
		$list .= '<li>' . esc_html__( 'The image editor on this server does not support WebP, so images are saved in their original format.', 'neotrendy-wp-hardening' ) . '</li>';
	}

	return nwph_site_health_result(
		'nwph_hardening_features',
		! $disabled,
		__( 'All Neotrendy WP Hardening features are enabled', 'neotrendy-wp-hardening' ),
		__( 'Some Neotrendy WP Hardening features are disabled', 'neotrendy-wp-hardening' ),
		__( 'Shows which features of this plugin are active. Features are disabled with constants in wp-config.php or with filters.', 'neotrendy-wp-hardening' ),
		__( 'A disabled feature may be intentional (for example Jetpack needs XML-RPC), but it should be a conscious decision.', 'neotrendy-wp-hardening' ),
		implode( "\n", $disabled ),
		'<ul>' . $list . '</ul>'
	);
}

/*
 * 7. Image metadata.
 */

/**
 * Removes EXIF (including GPS), XMP and IPTC metadata from an uploaded image by re-saving it with GD.
 * The EXIF orientation is applied first. On failure the file is left unchanged.
 *
 * @param array $upload Upload data: file, url, type.
 */
function nwph_strip_image_metadata( $upload ) {
	$types = (array) apply_filters( 'nwph_strip_image_metadata_types', array( 'image/jpeg', 'image/png', 'image/webp' ) );

	if ( empty( $upload['file'] ) || ! in_array( $upload['type'] ?? '', $types, true ) || ! nwph_is_enabled( 'strip_image_metadata' ) ) {
		return $upload;
	}

	$gd_only = static fn() => array( 'WP_Image_Editor_GD' );
	add_filter( 'wp_image_editors', $gd_only, PHP_INT_MAX );
	add_filter( 'image_editor_output_format', '__return_empty_array', PHP_INT_MAX );

	$editor = wp_get_image_editor( $upload['file'] );
	$result = is_wp_error( $editor ) ? $editor : $editor->maybe_exif_rotate();

	if ( ! is_wp_error( $result ) ) {
		$editor->set_quality( 90 );
		$result = $editor->save( $upload['file'], $upload['type'] );
	}

	remove_filter( 'wp_image_editors', $gd_only, PHP_INT_MAX );
	remove_filter( 'image_editor_output_format', '__return_empty_array', PHP_INT_MAX );

	if ( is_wp_error( $result ) ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- One line, no file name or user data.
		error_log( sprintf( 'Neotrendy WP Hardening: image metadata not removed (%s, %s).', $upload['type'], $result->get_error_code() ) );
	}

	return $upload;
}
add_filter( 'wp_handle_upload', 'nwph_strip_image_metadata' );

/**
 * Processes uploads on the server, where metadata is removed. In WordPress 7.1+ the block editor can
 * otherwise process images in the browser and send copies that still contain metadata.
 *
 * @param bool $enabled Whether client-side media processing is enabled.
 */
function nwph_disable_client_side_media_processing( $enabled ) {
	return nwph_is_enabled( 'strip_image_metadata' ) ? false : $enabled;
}
add_filter( 'wp_client_side_media_processing_enabled', 'nwph_disable_client_side_media_processing' );
