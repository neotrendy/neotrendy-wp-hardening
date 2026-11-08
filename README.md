# Neotrendy WP Hardening

Small, readable, secure defaults for WordPress in a single PHP file. Built primarily as a
must-use plugin, works as a regular plugin too. No settings screen, no JavaScript, no CSS,
no database writes, no external requests, no cron jobs.

- Requires WordPress 6.5+ and PHP 8.1+ (all hooks used exist since WordPress 5.8 or earlier;
  6.5 is the supported and tested baseline).
- License: GPL-2.0-or-later.
- Author: [Neotrendy s. r. o.](https://www.neotrendy.com/)

## What it does

Every feature is **enabled by default** and can be disabled with a constant in `wp-config.php`
or with a filter of the same name. **The constant takes precedence over the filter.**

1. **Blocks user enumeration**
   - REST API `/wp/v2/users` and `/wp/v2/users/<id>` respond with `401` to anonymous visitors
     (`403` to logged-in users without permission). Allowed for users who can `list_users`, and
     for users who can `edit_posts`, because the block editor's author selector and post author
     blocks use these endpoints. WordPress core still applies its own, stricter checks for them
     (for example only authors are returned to editors). `/wp/v2/users/me` is not affected.
   - `?author=N` and author archives (`/author/<slug>/`, including author feeds) return `404`
     to anonymous visitors, instead of the core redirect that reveals the user's slug.
   - oEmbed responses no longer contain `author_name` and `author_url`.
   - Failed logins show one message whether the username/email exists or the password is wrong.
2. **Disables XML-RPC** – `xmlrpc_enabled` is `false`, all `xmlrpc_methods` are removed
   (including pingbacks), the `X-Pingback` header and the RSD `<link>` are removed.
   `xmlrpc.php` still answers the three introspection methods built into the XML-RPC server
   library (`system.listMethods`, `system.getCapabilities`, `system.multicall`), but there are
   no WordPress methods to call. Block `xmlrpc.php` on the web server to stop the requests
   completely (see below).
3. **Disables application passwords** – `wp_is_application_passwords_available` is `false`.
4. **Hides users from the core sitemap** – the `users` sitemap provider is removed. Taxonomies
   can be removed with the `nwph_sitemap_excluded_taxonomies` filter (empty by default; it works
   independently of the constant).
5. **WebP for uploaded images** – new JPEG and PNG uploads are saved as WebP, but only if the
   server's image editor (Imagick or GD) supports WebP:
   - **the uploaded file stays on disk unchanged** and WordPress records it as the original
     image (`original_image` in the attachment metadata, `wp_get_original_image_path()`);
   - **the full size is WebP too**, because WordPress converts it whenever the output format
     differs (`photo.jpg` → `photo.webp`; above the "big image" threshold of 2560 px the
     scaled copy `photo-scaled.webp`). Posts and the media library use this WebP file;
   - all sub-sizes (thumbnail, medium, large, …) are WebP;
   - the attachment's MIME type (`post_mime_type`) stays `image/jpeg` or `image/png`; this is
     how WordPress core records converted images, plugins that filter attachments by MIME type
     still see the original type.

   Uploads processed in the browser by the block editor (WordPress 7.1+, client-side media
   processing on HTTPS and `localhost`) use the same setting: WordPress tells the editor the
   output format through the REST field `image_output_format`. Images edited in the media
   library (crop, rotate) are also saved as WebP. Images uploaded before activation are not
   converted. A mapping to another format set by another plugin (for example AVIF) is kept;
   an identity mapping (`image/jpeg => image/jpeg`) is treated as "not set", so use
   `NWPH_WEBP_UPLOADS` or the `nwph_webp_uploads` filter to turn the conversion off.
6. **Site Health checks** – informational only, they change nothing. Under
   *Tools → Site Health* (category *Security*):
   - `DISALLOW_FILE_EDIT` is enabled;
   - errors are not displayed (`WP_DEBUG_DISPLAY`) outside `local` and `development`;
   - `WP_DEBUG_LOG` does not write into the web root (the default `wp-content/debug.log`
     is reported);
   - `FORCE_SSL_ADMIN` is enabled (not required in `local` and `development`);
   - `WP_ENVIRONMENT_TYPE` is set explicitly;
   - status of features 1–5 of this plugin.

   Each check explains what it checks, why it matters and how to fix it, with a code snippet.

## What it does not do

- It is **not a firewall**. It does not block attacks, rate-limit logins, scan files or
  detect malware. Use a web application firewall (WAF) at the server, hosting or CDN level and
  keep WordPress, plugins and themes updated.
- It does not change server configuration (`.htaccess`, `wp-config.php`, HTTP headers). The
  recommended configuration is documented below.
- It does not hide that the site runs WordPress, and it does not hide author names that your
  theme prints on pages.

## Installation

### As a must-use plugin (recommended)

Copy (or symlink) the single file into `wp-content/mu-plugins/`:

```bash
mkdir -p wp-content/mu-plugins
cp neotrendy-wp-hardening.php wp-content/mu-plugins/
```

Must-use plugins are always active and cannot be disabled from the administration.

### As a regular plugin

Copy the repository folder into `wp-content/plugins/neotrendy-wp-hardening/` and activate it
under *Plugins*, or:

```bash
wp plugin activate neotrendy-wp-hardening
```

If the plugin is installed both ways, it is loaded only once.

### Translations

The text domain is `neotrendy-wp-hardening`; the template is
`languages/neotrendy-wp-hardening.pot`. Put compiled translations into
`wp-content/languages/plugins/` (for example `neotrendy-wp-hardening-sk_SK.mo`). As a
must-use plugin, translations are also looked up in `wp-content/mu-plugins/`
(`load_muplugin_textdomain()`).

## Constants and filters

Set a constant to `false` in `wp-config.php` to disable a feature, for example
`define( 'NWPH_DISABLE_XMLRPC', false );`. A filter returning `false` does the same when the
constant is not defined, for example `add_filter( 'nwph_disable_xmlrpc', '__return_false' );`
(in another must-use plugin or in the theme's `functions.php`).

| Constant | Filter | Default | Feature | Known conflicts |
| --- | --- | --- | --- | --- |
| `NWPH_BLOCK_USER_ENUMERATION` | `nwph_block_user_enumeration` | `true` | User enumeration protection | Headless front ends or integrations that read `/wp/v2/users` anonymously; themes linking to author archives (links return 404 to visitors). |
| `NWPH_DISABLE_XMLRPC` | `nwph_disable_xmlrpc` | `true` | XML-RPC off | **Jetpack** (connection and several modules use XML-RPC), the **WordPress mobile app** on sites without application passwords, old desktop blogging clients, incoming pingbacks/trackbacks via XML-RPC, some remote management services. |
| `NWPH_DISABLE_APPLICATION_PASSWORDS` | `nwph_disable_application_passwords` | `true` | Application passwords off | The **WordPress mobile app** (logs in with application passwords), **REST API integrations** that authenticate with application passwords (automation services, CI, headless sites, some backup and management tools). |
| `NWPH_SITEMAP_HIDE_USERS` | `nwph_sitemap_hide_users` | `true` | No users in `/wp-sitemap.xml` | None known; SEO plugins with their own sitemap are not affected. |
| `NWPH_WEBP_UPLOADS` | `nwph_webp_uploads` | `true` | WebP for new uploads (full size and sub-sizes) | Image or CDN plugins that do their own conversion (they may override or duplicate it); workflows that require JPEG files. |
| `NWPH_SITE_HEALTH_CHECKS` | `nwph_site_health_checks` | `true` | Site Health checks | None known. |
| – | `nwph_sitemap_excluded_taxonomies` | `array()` | Taxonomy names to remove from the core sitemap | – |

Example:

```php
// Keep core sitemaps for categories only.
add_filter( 'nwph_sitemap_excluded_taxonomies', fn() => array( 'post_tag', 'post_format' ) );
```

Filters must be added before the hook they affect runs; adding them in a must-use plugin or in
the theme's `functions.php` is early enough.

## Compatibility

- **Block editor and site editor**: work for logged-in users (see the REST rule above). Tested
  as administrator and as editor, including selecting the post author and saving.
- **REST API for logged-in users**: not affected except `/wp/v2/users` for users who can
  neither `list_users` nor `edit_posts` (for example subscribers; `/wp/v2/users/me` still works).
- **WP-CLI**: not affected.
- **Site Health**: only adds tests.
- **Multisite**: not specifically tested; the features apply per site.
- The login message is replaced for the `invalid_username`, `invalid_email` and
  `incorrect_password` errors; plugins that rely on these error codes in the `wp_login_failed`
  action receive `nwph_invalid_credentials` instead.

## Recommended server configuration

This section is documentation only. The plugin does not apply any of it.

### wp-config.php

```php
// Disable the theme and plugin file editor: a stolen admin account cannot run PHP code from the dashboard.
define( 'DISALLOW_FILE_EDIT', true );

// Always use HTTPS for login and administration, so passwords and cookies are never sent in clear text.
define( 'FORCE_SSL_ADMIN', true );

// Tell WordPress and plugins which environment this is: 'production', 'staging', 'development' or 'local'.
define( 'WP_ENVIRONMENT_TYPE', 'production' );

// Log errors to a file outside the web root, where it cannot be downloaded.
define( 'WP_DEBUG', false );          // true only while investigating a problem.
define( 'WP_DEBUG_LOG', '/var/log/wordpress/example-debug.log' ); // Writable by PHP, not reachable over HTTP.

// Never show errors to visitors; they reveal paths and break output.
define( 'WP_DEBUG_DISPLAY', false );
@ini_set( 'display_errors', '0' );

// Install minor (security and maintenance) core releases automatically; major releases manually after testing.
define( 'WP_AUTO_UPDATE_CORE', 'minor' );
```

### .htaccess (Apache 2.4)

Put these rules **above** the `# BEGIN WordPress` block. Test on staging first.

```apache
# Redirect all HTTP requests to HTTPS.
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTPS} !=on
    # Behind a proxy or load balancer that terminates TLS, use instead:
    # RewriteCond %{HTTP:X-Forwarded-Proto} !=https
    RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [R=301,L]
</IfModule>

<IfModule mod_headers.c>
    # HSTS: enable only when the site AND every subdomain work over HTTPS (includeSubDomains).
    # Start with a short max-age (e.g. 300) and increase it. Do not add "preload" unless you
    # understand that removal from browser preload lists takes months.
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" "expr=%{HTTPS} == 'on'"

    # Do not let browsers guess content types.
    Header always set X-Content-Type-Options "nosniff"

    # Send only the origin to other sites.
    Header always set Referrer-Policy "strict-origin-when-cross-origin"

    # Prevent clickjacking. X-Frame-Options for older browsers, frame-ancestors for current ones.
    # Use 'self' (not DENY): the site editor and the customizer preview the site in an iframe.
    Header always set X-Frame-Options "SAMEORIGIN"
    Header always set Content-Security-Policy "frame-ancestors 'self'"

    # Disable browser features the site does not use. Adjust if you embed maps, video calls, payments, …
    Header always set Permissions-Policy "camera=(), microphone=(), geolocation=(), payment=(), usb=()"
</IfModule>

# Block XML-RPC completely (remove if you need Jetpack or other XML-RPC clients).
<Files "xmlrpc.php">
    Require all denied
</Files>

# Block the debug log, wp-config.php and .htaccess/.htpasswd files.
<FilesMatch "^(debug\.log|wp-config\.php|\.ht.*)$">
    Require all denied
</FilesMatch>
```

If a `Content-Security-Policy` header is already sent (by a plugin or the server), add
`frame-ancestors 'self'` to it instead of sending a second header.

### Staging behind HTTP Basic Auth

Protect staging sites with a password, but let the server reach itself. WP-Cron, Site Health
loopback tests, the REST API availability test and some plugins make HTTP requests from the
server to its own URL; without the `Require ip` rule they fail with `401`.

```apache
AuthType Basic
AuthName "Staging"
# Keep the password file OUTSIDE the web root.
AuthUserFile /etc/apache2/htpasswd/example-staging
<RequireAny>
    Require valid-user
    # The server's own public and loopback addresses (replace 203.0.113.10 with the server IP).
    Require ip 127.0.0.1 ::1 203.0.113.10
</RequireAny>
```

Create the password file with `htpasswd -c /etc/apache2/htpasswd/example-staging username`.
Never store it in the web root, and do not commit it (`.htpasswd` is in `.gitignore`).

## Testing

```bash
php -l neotrendy-wp-hardening.php
```

`tests/smoke.sh` runs end-to-end checks against a local installation with `curl` and WP-CLI.
The plugin must be loaded (must-use or active). The script backs up `wp-config.php`, sets the
`NWPH_*` and other constants temporarily with `wp config set`, and restores the file on exit.
It creates and deletes two temporary users (editor, subscriber), a post and test attachments.

```bash
SITE_URL=http://wordpress.localhost WP_PATH=/srv/wordpress.localhost tests/smoke.sh
```

It checks every feature both enabled and disabled and prints `PASS`/`FAIL` per check; the exit
code is `0` when all checks pass, `1` otherwise. Use it on local or disposable installations
only.

Manual checks in a browser: open the block editor as an administrator and as an editor,
change the post author and save; open *Tools → Site Health* and check the *Security* tests.

The translation template is generated with:

```bash
wp i18n make-pot . languages/neotrendy-wp-hardening.pot --exclude=tests \
    --headers='{"Report-Msgid-Bugs-To":"https://github.com/neotrendy/neotrendy-wp-hardening/issues"}'
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
