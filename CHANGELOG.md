# Changelog

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

Planned for 0.2.0 (TODO, not implemented yet):

- Generic messages on the lost password and registration forms, so they do not reveal whether
  an account exists.
- Optionally hide the `author` field and author links in REST post responses for anonymous
  visitors, and filter `author_link`.
- Optionally stop `xmlrpc.php` requests completely in PHP (403), including the built-in
  `system.*` methods.
- Site Health tests for security headers (via a loopback request) and for HTTP access to
  `debug.log`.
- WP-CLI command `wp nwph status`.
- Testing and tooling:
  - multisite tests;
  - PHPUnit unit/integration tests (WordPress test suite, e.g. `wp scaffold plugin-tests` or
    wp-env);
  - Playwright tests in the repository (block editor as administrator and editor: author
    selection and saving; site editor; Site Health tests);
  - GitHub Actions with PHPCS (WordPress) and PHPCompatibility for PHP 8.1+;
  - Slovak translation (`.po`/`.mo`).

## [0.1.1] - 2026-11-08

### Fixed

- WebP: images uploaded in the block editor kept JPEG/PNG sub-sizes when WordPress 7.1 processes
  them in the browser. The identity mapping that the REST API passes to
  `image_editor_output_format` is now treated as "not set", so the REST field
  `image_output_format` reports `image/webp`.
- README: the full size of a new upload is WebP as well (not only above the "big image"
  threshold); the uploaded file is kept as the original image and the attachment MIME type
  stays unchanged.
- `tests/smoke.sh`: XML-RPC is also verified in PHP; HTTP checks are skipped when the web server
  blocks `xmlrpc.php` (403/404), and an empty response no longer passes. New checks for the
  full-size WebP file and the REST `image_output_format` field.

## [0.1.0] - 2026-11-08

### Added

- User enumeration protection: REST `/wp/v2/users` restricted, anonymous author archives and
  `?author=N` return 404, no author data in oEmbed, one generic login error message
  (`NWPH_BLOCK_USER_ENUMERATION`).
- XML-RPC disabled, `X-Pingback` header and RSD link removed (`NWPH_DISABLE_XMLRPC`).
- Application passwords disabled (`NWPH_DISABLE_APPLICATION_PASSWORDS`).
- Users removed from the core sitemap; `nwph_sitemap_excluded_taxonomies` filter
  (`NWPH_SITEMAP_HIDE_USERS`).
- JPEG and PNG sub-sizes saved as WebP when supported (`NWPH_WEBP_UPLOADS`).
- Informational Site Health checks (`NWPH_SITE_HEALTH_CHECKS`).
- Smoke tests (`tests/smoke.sh`).

[Unreleased]: https://github.com/neotrendy/neotrendy-wp-hardening/compare/v0.1.1...HEAD
[0.1.1]: https://github.com/neotrendy/neotrendy-wp-hardening/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/neotrendy/neotrendy-wp-hardening/releases/tag/v0.1.0
