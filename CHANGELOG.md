# Changelog

All notable changes to this project are documented in this file. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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

[0.1.0]: https://github.com/neotrendy/neotrendy-wp-hardening/releases/tag/v0.1.0
