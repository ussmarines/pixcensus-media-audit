# Project map

## Audit baseline

- Audited source commit: `1f86ca9e844d1602e22a09526415851d5824fd61` (`main`, external audit reference verified unchanged on 2026-08-10).
- Release preparation: `3.0.2` on `release/3.0.2-hardening`.
- Plugin version: `3.0.2`.
- Declared compatibility: WordPress 5.9+, PHP 7.4+, tested through WordPress 7.0.2.
- Entry point: `pixcensus-media-audit.php`.
- Text domain: `pixcensus-media-audit`; translations live under `languages/`.
- Canonical project URL: `https://github.com/ussmarines/WP_image_usage_audit`; WordPress.org slug: `pixcensus-media-audit`.

## Architecture and responsibilities

| Path | Responsibility |
| --- | --- |
| `pixcensus-media-audit.php` | Metadata, constants, autoloader, plugin lifecycle, administrator-only Media submenu, action-specific nonces, scan lock, settings handlers, AJAX scan/manual actions, and hardened CSV export. |
| `includes/class-pixcensus-scanner.php` | Image attachment discovery, path map, content/meta/options/terms/site-identity scans, CDN normalization, provenance, classification, and orphan-file enumeration. |
| `includes/class-pixcensus-cdn-settings.php` | Pure, bounded validation/canonicalization for host aliases and upload-path rewrite rules. |
| `includes/class-pixcensus-csv.php` | Spreadsheet-formula neutralization for exported site-derived values. |
| `views/admin-page.php` | Admin settings, result tabs, pagination, filters, escaped output, bulk/manual controls, and export link. |
| `assets/admin.js` | Authenticated AJAX calls, result-row updates, quick filtering, column preferences in browser local storage, density controls, and notices. |
| `assets/admin.css` | Admin-only layout and responsive presentation. |
| `uninstall.php` | Deletes only plugin-owned options for the current site and every multisite site. |
| `scripts/build-zip.ps1` | Builds and inspects an allow-listed, deterministic `pixcensus-media-audit/` distribution ZIP and writes its SHA-256 checksum. |
| `scripts/validate-metadata.mjs` | Checks version, text-domain, GPL, tags, short-description, and screenshot metadata invariants. |
| `scripts/validate-release-tag.mjs` | Rejects a release tag that does not exactly match the semantic plugin version. |
| `readme.txt` | WordPress plugin metadata, end-user description, changelog, and privacy statement. |
| `languages/pixcensus-media-audit.pot` | Reproducible translation template generated from the PHP and JavaScript source with the `pixcensus-media-audit` text domain. |

Runtime code remains dependency-free. Composer/npm are development-only, WordPress is supplied ephemerally by wp-env, unit and disposable integration-smoke tests live under `tests/`, and GitHub Actions runs the locked QA workflow.

## Data flow

1. An administrator with `manage_options` opens **Media → PixCensus — Media Usage Audit**.
2. WordPress localizes the admin AJAX URL, action-specific nonces, last-scan time, page URLs, and UI strings into `PixCensusAdmin`.
3. **Run scan** posts to `wp_ajax_pixcensus_run_scan`; the handler verifies nonce and capability, then calls `PIXCENSUS_Scanner::run()`.
4. The scanner loads settings, enumerates image attachments, maps originals/generated sizes, scans supported sources, classifies IDs, enumerates orphan files, and limits provenance to 12 labels per attachment.
5. An atomic 15-minute option lock rejects concurrent scans. Results are stored with autoload disabled in `pixcensus_usage_results` and rendered from the saved snapshot. Manual decisions are merged into display/export classifications.
6. Settings and CSV exports use authenticated `admin-post.php` handlers. CSV generation reads saved results and attachment metadata, neutralizes formula-leading cells, and does not alter media.

## Sources inspected by the scanner

- All registered image attachments (`post_status=inherit`) and their `_wp_attached_file`/generated-size metadata.
- `post_content` for all public and non-public post types except attachments, revisions, and menu items; published/private and optionally draft/pending/future statuses.
- `wp-image-{id}` CSS classes and `/wp-content/uploads/...` paths in content.
- Featured image `_thumbnail_id` and WooCommerce `_product_image_gallery`.
- Builder metadata keys for Elementor, Beaver Builder, Oxygen, SiteOrigin, Bricks, WPBakery, and Divi; upload paths plus generic nested/JSON `id` values.
- Any post metadata value that contains an upload/CDN search pattern.
- All non-plugin option names/values through read-only batches of 500 rows against `$wpdb->options`, scanning values for upload paths.
- All taxonomy term descriptions.
- `site_icon` and the active theme's `custom_logo`.
- Files under the uploads directory with `jpg`, `jpeg`, `png`, `gif`, `webp`, `svg`, or `avif` extensions for orphan reporting.

## WordPress options

| Option | Shape / purpose | Lifecycle |
| --- | --- | --- |
| `pixcensus_include_drafts` | `'1'` or `'0'`; enables draft-only scanning. | Defaulted on bootstrap; deleted on uninstall. |
| `pixcensus_manual_used_ids` | Array of validated attachment IDs manually treated as used. | Defaulted on bootstrap; deleted on uninstall. |
| `pixcensus_cdn_aliases` | Comma-separated CDN hosts. | Defaulted on bootstrap; deleted on uninstall. |
| `pixcensus_cdn_rewrites` | Newline-separated `FROM => TO` rules. | Defaulted on bootstrap; deleted on uninstall. |
| `pixcensus_usage_results` | Used/draft-only/unused IDs, orphan paths, timestamp, draft flag, and provenance. | Written after scans; deleted on uninstall. |
| `pixcensus_scan_lock` | Owner token and expiry for the atomic concurrent-scan guard. | Non-autoloaded; written only during a scan, owner-released afterward, and deleted on uninstall. |

## Security-sensitive surfaces

- Capability: every admin render/action uses `manage_options` so authors cannot inspect global private provenance or options.
- CSRF: settings use section-specific admin nonces; CSV uses `pixcensus_export_csv`; every AJAX action uses a distinct nonce.
- Input: tabs/filters/sections are sanitized then allow-listed; IDs use `absint`, attachment validation, scalar checks, and a 500-ID bulk cap; CDN settings are structurally validated and bounded.
- SQL: the sole direct query is a prepared, read-only, ID-paginated enumeration of the current site's options table. No user input enters SQL.
- Output: admin HTML uses `esc_html*`, `esc_attr*`, `esc_url`, `esc_textarea`, or constrained `wp_kses_post`; redirects use `wp_safe_redirect`.
- Privacy: no remote requests or telemetry. Saved provenance exposes IDs, option names, and source locations to authorized administrators; CSV and orphan paths should be treated as sensitive operational data.
- CSV: site-derived cells that begin with spreadsheet formula markers are prefixed with an apostrophe; exports remain operationally sensitive and should be treated as untrusted files.

## Known functional limits

- Heuristic results can contain false negatives for theme/plugin files, custom CSS, dynamic/external data, unsupported builders, IDs outside recognized structures, and unconfigured CDN transformations.
- Generic builder `id` extraction can create false positives when an unrelated numeric ID equals an image attachment ID.
- Scan work remains synchronous and may exhaust time/memory on very large sites. Attachments, posts, metadata, terms, and options are queried in bounded batches, nested values are limited to 64 levels and 10,000 examined elements per value, and concurrent scans are rejected, but the complete attachment map and upload-file inventory still live in one request.
- Only a fixed image-extension list participates in orphan detection.
- Results are snapshots and become stale until the next manual scan.
- Provenance is capped at 12 labels per attachment.
- The POT is reproducible and contains the current runtime strings. Release changes must keep its project version and catalog synchronized with the source.

## Commands and decisions

- QA configuration: `composer.json`/`composer.lock`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `package.json`/`package-lock.json`, `.wp-env.json`, and `.github/workflows/qa.yml`.
- Workflow configuration also includes Dependency Review, JavaScript CodeQL, OpenSSF Scorecard, immutable release publication, and `.github/dependabot.yml`; run `npm run actionlint` and `npm run validate:config` for persistent workflow invariants.
- Composer development tools: PHPCS 3.13.6 + WPCS + PHPCompatibilityWP, PHPStan with WordPress stubs, PHPUnit 9.6.35, and PHPUnit polyfills. `composer qa` runs lint, analysis, and isolated scanner tests; PHPStan uses a 1G limit for the WordPress stubs under PHP 7.4.
- Reproducible runtime: `@wordpress/env` 11.12.0 with WordPress 7.0.2/PHP 7.4. Dedicated configs exercise WordPress 5.9.13 and a WordPress 7.0.2 multisite network; CI also runs a PHP 8.3 static/test lane.
- Tests: `tests/unit` has 50 cases / 239 assertions for AJAX envelopes, capabilities, action-specific nonces, bounded IDs, lock ownership, network activation, URL/block/shortcode normalization, batching, bounded cycle-safe metadata walking, CDN validation, CSV neutralization, builder IDs, provenance, and uploads-relative orphan paths. Integration scripts exercise authenticated HTTP AJAX, more than one post/options batch, draft behavior, non-autoloaded results, stale locks, exact-ZIP activation, multisite isolation/uninstall, Plugin Check, and media/content preservation.
- Public GitHub Actions run `29207713713` passed all four jobs at the audited commit. It covered actionlint, PHP 7.4/8.3 analysis/tests/syntax, dependency audits, metadata/config validation, ZIP construction, ZIP installation and activation, functional smoke assertions, Plugin Check, deterministic POT regeneration, and environment shutdown. Local `wp-env` may still depend on Docker networking, but the successful CI result is the current reusable runtime baseline.
- Read `.codex/test-ledger.json` before testing and reuse valid passing baselines according to `AGENTS.md`.
- Keep runtime dependency-free and the admin UI on WordPress/jQuery primitives.
- Keep scans and settings non-destructive to media; only plugin options may be written or removed.
- Preserve WordPress 5.9+ and PHP 7.4+ until explicitly changed, even though installed WordPress skills target WordPress 7.0+.

## Next implementation steps

1. Keep the synchronous scale limit explicit and evaluate an asynchronous design only from measured production evidence.
2. Add new heuristic fixtures whenever a supported builder or reference format is introduced.
