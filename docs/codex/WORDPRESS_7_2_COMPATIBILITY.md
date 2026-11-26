# WordPress 7.2 compatibility

Qualification date: 2026-11-26. Source: `8ab764b` plus the local compatibility changes. Plugin version remains 3.5.0; no runtime changes are required. The subsequent owner-authorized publication updates compatibility and presentation through a branch/PR and the readme-only WordPress.org workflow.

## Official references and applicability

- [7.2 RC3 announcement](https://wordpress.org/news/2026/11/wordpress-7-2-release-candidate-3/): current candidate tested; final release scheduled for December 8, 2026. The announcement asks plugin authors to update `Tested up to` after testing RC3.
- [7.2 Field Guide](https://make.wordpress.org/core/2026/11/20/wordpress-7-2-field-guide/) and [media changes](https://make.wordpress.org/core/2026/11/17/media-changes-in-wordpress-7-2/): browser processing expands to the Media Library and media modal. PixCensus reads the resulting attachment records and local files; it does not customize upload processing, image editors or cross-origin isolation.
- Context7 `/wordpress/wordpress-develop` was consulted for attachment metadata and relative attached-file storage. Its indexed examples are general API evidence; the official 7.2 notes and downloaded RC3 runtime establish the version-specific evidence.

PixCensus uses native Media submenu registration and its own namespaced admin CSS, without styling Core menu icons. It does not register REST endpoints, abilities, editor components, or Interactivity API directives. Its jQuery-based admin scan is exercised against the jQuery shipped with RC3. The new PHP 8.6 support in Core does not establish plugin compatibility with PHP 8.6.

## Local evidence

Windows, PHP 8.3.33, WP-CLI 2.12.0, SQLite Integration 3.1.0, Node 26.7.0 and PowerShell 7.6.9. WordPress `7.2-RC3` was downloaded from `https://wordpress.org/wordpress-7.2-RC3.zip` into a fresh SQLite site, served over loopback HTTP only. The allow-listed distribution ZIP was installed and activated through WP-CLI.

- `tests/integration/review-improvements.php`: 25 assertions, 22 scan batches, 206 image fixtures; synchronous/incremental equivalence, native block IDs, local byte accounting, freshness, bounded history and privacy hooks.
- `tests/integration/media-metadata.php`: registered file, `original_image`, generated thumbnail and three independent URL references, with all image bytes preserved. Uses native attachment and metadata APIs; it simulates persisted metadata rather than browser processing.
- `tests/integration/review-ui.cjs`: Chromium desktop/mobile, AJAX error recovery, checkpoint resume, server-owned cursors, complete scan, filters, shared-file byte deduplication, hidden-selection clearing, evidence details and no JavaScript page errors. Shared-file rows are now selected explicitly so unrelated fixture images cannot invalidate the 68-byte expectation.
- Official Plugin Check: no errors reported on the installed package.
- `tests/integration/ajax-smoke.mjs`: 96 HTTP assertions across unauthenticated access, restricted roles and administrator; action-specific nonces, request methods, settings, CSV and cleanup authorization.
- `tests/integration/smoke.php`: scanner, attachment references, batching, locks, non-autoloaded results and uninstall preservation assertions passed on RC3.
- New PHP fixture passes WPCS and PHP syntax; modified browser fixture passes JavaScript syntax. Metadata validation confirms version 3.5.0 and `Tested up to: 7.2`.
- Qualification ZIP before the later listing-text expansion: `dist/pixcensus-media-audit-wp72.zip`, 16 entries, SHA-256 `1d840944fe6ff6848e42fe546578b001d8abf511aa67adb1cf216c262b39a5a9`. Installed after the compatibility-header update and checked again with Plugin Check 2.2.0 without errors. Runtime bytes are identical to the previously exercised ZIP. Publication preserves the immutable GitHub 3.5.0 archive; only the two WordPress.org readmes are updated.

The ledger records commands, environment and outcomes, including failed setup attempts. Temporary paths use `$env:TEMP` in replay commands to avoid publishing a local profile name; executed argv hashes identify the original invocation. Initial installation emitted an `ABSPATH` redefinition warning in the disposable configuration; adding the standard definition guard resolved it before plugin installation. Initial browser execution timed out with the server helper; a server writing to local log files completed the run. A subsequent byte assertion exposed the test's assumption about the first two rows after adding the metadata fixture; explicitly selecting shared-file rows resolved the fixture coupling. Plugin Check initially lacked its CLI subcommand because its plugin had not yet been activated; activation resolved that setup issue.

Unchanged runtime WPCS, PHPStan, unit tests, PHP 7.4 / WordPress 5.9 minimum-version and translation evidence remain historical `PASS_REUSED` baselines. They are not new runs on RC3. Current CI still targets 7.1.5; this document records local evidence only.

## Limits

The release candidate is not the final WordPress 7.2 release. Native MySQL/MariaDB, multisite on RC3, PHP 8.6 and actual client-side media processing are not covered by this local qualification. Chromium exercised the PixCensus interface, not a browser upload/format-conversion journey. The qualification itself changed no remote state; subsequent publication is a separate owner-authorized operation described in the [publication procedure](../wordpress-org/svn-publication.md).
