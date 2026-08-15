=== PixCensus — Media Usage Audit ===
Contributors: ussmarines
Donate link: https://paypal.me/ussmarinesdot
Tags: media, attachments, audit, images, csv
Requires at least: 5.9
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 3.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Inventory media usage with provenance, CSV export, manual review tools, and CDN rewrite support.

== Description ==

PixCensus — Media Usage Audit helps you review where images are used before you clean up the Media Library.

Features:
* Scan published content and optionally drafts.
* Track provenance for matches found in post content, post meta, options, term descriptions, and common builders.
* Mark false negatives manually as used, with reversible actions and a dedicated filter.
* Export each tab to CSV, including provenance and match count.
* Support CDN aliases and advanced read-only rewrite rules during scans.
* Stay non-destructive: the plugin does not delete attachments or modify Media Library behavior.

Supported builders and editors include WordPress core, Elementor, Divi, Beaver Builder, Oxygen, Bricks, SiteOrigin, and WPBakery.

= Support the project =

If PixCensus — Media Usage Audit has been useful to you, you can support its continued development with an optional donation: https://paypal.me/ussmarinesdot

Important:
Images referenced only in custom CSS, raw HTML widgets, theme files, plugin files, or some external/CDN setups may still require manual review. Always make a full backup before deleting media.

== Installation ==

1. Upload and activate the plugin.
2. Open **Media → PixCensus — Media Usage Audit**.
3. Click **Run scan**.

== Frequently Asked Questions ==

= Is the scan live? =

No. Run a new scan to refresh the results.

= Why can a used image still appear as unused? =

Typical cases include custom CSS, HTML widgets, theme files, rewritten CDN domains, or third-party integrations. Use the manual mark feature when needed.

== Changelog ==

= 3.0.3 =
* Confined attachment and orphan-file filesystem resolution to the canonical WordPress uploads directory, rejecting traversal and symlink escapes outside that boundary.
* Expanded authorization regression coverage across unauthenticated, Subscriber, Contributor, Author, Editor, and Administrator access for AJAX actions, settings, and CSV export.
* Hardened CSV formula neutralization against Unicode whitespace, byte-order marks, zero-width characters, and bidi/formatting controls that can hide formula prefixes.
* Removed a repeated HTML URL unescaping pattern flagged by CodeQL from the integration test helper while preserving the same export authorization coverage.

= 3.0.2 =
* Fixed the PixCensus density control binding and improved keyboard-visible, pressed-state, live-notice, and reduced-motion behavior.
* Bounded nested metadata traversal by depth and element budget, with cycle-safe handling for arrays and objects while preserving normal URL and builder-ID detection.
* Tightened CDN rewrite targets to the uploads directory boundary and hardened the WordPress.org SVN asset allow-list and version checks.
* Updated the locked npm and Composer QA dependencies to resolve the current `js-yaml` and PHP_CodeSniffer security advisories.

= 3.0.1 =
* Renamed the plugin to PixCensus — Media Usage Audit with the distinctive `pixcensus-media-audit` slug.
* Replaced all active PHP, WordPress, JavaScript, CSS, option, nonce, and AJAX prefixes with `pixcensus_` / `PIXCENSUS_`.
* Introduced a new self-contained PixCensus visual identity for the administration screen, GitHub README, and WordPress.org directory assets.
* Revalidated administrator capabilities, action-specific nonces, package metadata, multisite behavior, and the non-destructive scan workflow.

= 3.0.0 =
* Improved the administration layout and project documentation before the PixCensus rebranding introduced in 3.0.1.
* Added bundled, self-contained artwork with no remote resources or tracking.
* Added WordPress.org banner and icon sources, submission guidance, and safer release-preparation helpers.
* Preserved the non-destructive scanner, existing security controls, and WordPress 5.9 / PHP 7.4 compatibility.

= 2.2.9 =
* Added optional PayPal support links to the GitHub repository, WordPress.org metadata, and the plugin administration page.
* Added GitHub Sponsor button configuration through the repository funding file.
* Refreshed translation and release metadata for the new support section.

= 2.2.8 =
* Sanitized single and bulk attachment IDs before strict AJAX validation, resolving the related Plugin Check warnings.
* Expanded tests for valid, malformed, oversized, duplicate, nested, and non-attachment ID inputs while preserving AJAX responses.
* Reorganized and simplified the GitHub README for users, administrators, and contributors.

= 2.2.7 =
* Added deterministic property-based security tests for CDN validation and CSV formula neutralization on PHP 7.4 and PHP 8.3.
* Improved scanner normalization and regression coverage for encoded, relative, and scheme-relative image references.
* Hardened dependency, CodeQL, Scorecard, branch-protection, and release workflows with full-SHA action pins and required checks.
* Added reproducible ZIP checksums and GitHub artifact attestations to the release pipeline.
* Updated the confirmed WordPress.org contributor identity and refreshed development dependencies.

= 2.2.6 =
* Restricted every audit action to administrators with `manage_options` and gave each AJAX action its own nonce and stable validation responses.
* Neutralized spreadsheet formulas in CSV exports and strictly bounded CDN aliases, rewrite rules, manual selections, and request values.
* Added an atomic expiring scan lock, preserved the last complete result after interrupted scans, and kept large result options out of autoload.
* Bounded attachment, post, term, metadata, and option processing while retaining the synchronous, dependency-free scanner.
* Expanded detection for encoded and relative upload URLs, `srcset`, lazy-load fields, JSON, serialized values, CSS, shortcodes, blocks, builders, CDN aliases, query strings, and fragments.
* Corrected network activation and multisite uninstall context restoration while preserving all media and content.
* Strengthened GitHub Actions with pinned actions, reproducible ZIP/POT checks, PHP 7.4 and 8.3 QA, WordPress 5.9/current smoke tests, AJAX, multisite, large-site, uninstall, and heuristic coverage.
* Updated PHPUnit and compatibility stubs, migrated static analysis to PHPStan 2, and enabled GitHub private vulnerability reporting.

= 2.2.5 =
* Removed the remaining Plugin Check SQL preparation and direct-parameter findings in the scanner.
* Reworked scan queries to use WordPress query APIs where possible.
* Kept options scanning functional with a constrained read-only core options query.
* Preserved provenance, draft handling, CDN rewrite support, CSV export, and manual review workflows.

= 2.2.3 =
* Fixed all reported Plugin Check issues from the latest audit CSV.
* Reworked SQL preparation and request sanitization.
* Removed discouraged translation loading and time limit handling.
* Updated the readme to WordPress.org directory standards.
* Kept bulk actions, scan flow, CSV export, and manual review features fully functional.

= 2.2.2 =
* Fixed admin settings forms so saving one section no longer clears the other.
* Fixed broken CSS rules in the admin UI.
* Fixed the “select all” bulk-action behavior.
* Hardened AJAX and CSV handling.
* Updated readme and plugin headers for WordPress 7.0 compatibility.

= 2.2.1 =
* First public release based on the internal stable branch.

== Upgrade Notice ==

= 3.0.3 =

Adds defense-in-depth confinement for uploads paths, stronger role-based authorization regression coverage, and broader CSV formula-injection protection for Unicode-obscured prefixes.

= 3.0.2 =

Hardens metadata scanning, CDN validation, release preparation, dependencies, and admin accessibility for the first WordPress.org publication.

= 3.0.1 =

Renames the plugin and its identifiers to PixCensus, adds the new directory artwork, and preserves the audited non-destructive workflow.

= 3.0.0 =

Introduces administration and WordPress.org publication resources without changing the plugin's non-destructive audit behavior; the PixCensus rebranding followed in 3.0.1.

= 2.2.9 =

Adds optional, non-intrusive donation links to support continued plugin development.

= 2.2.8 =

Corrects attachment-ID sanitization warnings, strengthens AJAX input tests, and provides clearer GitHub documentation.

== Privacy ==

This plugin does not collect, track, or transmit personal data. It makes no remote requests and loads no remote executable code.

It stores plugin settings, manual image decisions, scan timestamps, attachment classifications, orphan upload paths, and short provenance labels in WordPress options. Administrators can export the current snapshot as CSV. Uninstalling the plugin removes only these plugin-owned options; it never deletes or modifies media, posts, metadata, terms, or files.

Option names, filenames, paths, and provenance may reveal private site structure. Restrict plugin access and exported CSV files to trusted administrators. Formula-leading CSV values are neutralized, but exports should still be treated as untrusted files.

== Development ==

Human-readable source, development instructions, tests, and the reproducible ZIP command are available at https://github.com/ussmarines/pixcensus-media-audit.
