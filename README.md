<p align="center">
  <img src=".wordpress-org/banner-1544x500.png" alt="PixCensus — Media Usage Audit" width="772">
</p>

# PixCensus — Media Usage Audit

Find where WordPress uses each image, protect files you want to keep, and manage Trash from the Media menu.

[![WordPress.org](https://img.shields.io/badge/WordPress.org-PixCensus-21759B?logo=wordpress)](https://wordpress.org/plugins/pixcensus-media-audit/)
[![Latest release](https://img.shields.io/github/v/release/ussmarines/pixcensus-media-audit)](https://github.com/ussmarines/pixcensus-media-audit/releases/latest)
[![QA](https://github.com/ussmarines/pixcensus-media-audit/actions/workflows/qa.yml/badge.svg)](https://github.com/ussmarines/pixcensus-media-audit/actions/workflows/qa.yml)
[![License](https://img.shields.io/badge/license-GPL--2.0--or--later-blue.svg)](LICENSE)

## Official release

**PixCensus — Media Usage Audit 3.4.9 is the current source release.**

- **WordPress.org:** https://wordpress.org/plugins/pixcensus-media-audit/
- **GitHub release:** https://github.com/ussmarines/pixcensus-media-audit/releases/tag/v3.4.9
- **Current version:** `3.4.9`
- **WordPress:** `5.9+`, tested through `7.1`
- **PHP:** `7.4+`
- **License:** GPL-2.0-or-later

For normal WordPress installations, the WordPress.org package is the recommended distribution channel. GitHub remains the source repository and provides release artifacts, checksums, attestations, development history, and issue tracking.

## What PixCensus does

PixCensus scans registered WordPress image attachments and helps classify them as:

- **Used in published content**
- **Used only in draft, pending, or scheduled content**
- **Potentially unused**

For each detected reference, PixCensus records provenance so you can review where the image was found before making a cleanup decision.

It can also report image files found in the uploads directory that are not registered WordPress attachments and export scan results to CSV.

## Key features

- Scans published content and optionally draft, pending, and scheduled content.
- Detects featured images, galleries, upload URLs, generated image sizes, site icons, custom logos, metadata, options, and term descriptions.
- Handles common builder data from Elementor, Divi, Beaver Builder, Oxygen, Bricks, SiteOrigin, and WPBakery.
- Records provenance and match counts for detected references.
- Supports reversible manual **used** markings for reviewed false negatives.
- Supports CDN host aliases and read-only path rewrite rules.
- Exports used, draft-only, and potentially unused results to CSV.
- Reports orphan image files in the WordPress uploads directory.

## Review and clean up images

In **Media → PixCensus**, select images in **To review**, **Drafts** or **In use** and choose **Move to Trash**. Open **Which images are protected?** for help removing manual protection or locating references. In **Trash**, restore images or confirm permanent deletion. WordPress controls automatic deletion using the site retention period. At zero days, images stay in Trash for manual restoration or deletion.

## Installation

### Recommended — WordPress.org

1. In WordPress, open **Plugins → Add New Plugin**.
2. Search for **PixCensus — Media Usage Audit**.
3. Install and activate the plugin.
4. Open **Media → PixCensus — Media Usage Audit**.

Official directory page:

https://wordpress.org/plugins/pixcensus-media-audit/

### GitHub release

You can also download the release ZIP with a checksum and GitHub attestation from:

https://github.com/ussmarines/pixcensus-media-audit/releases/latest

Upload `pixcensus-media-audit.zip` from **Plugins → Add New Plugin → Upload Plugin**.

## Quick start

1. Open **Media → PixCensus — Media Usage Audit**.
2. Review the scan settings and choose whether draft content should be included.
3. Run a scan.
4. Review **To review**, **Drafts**, and **In use** results.
5. Inspect provenance before acting on any image.
6. Mark reviewed false negatives manually when necessary or export results to CSV.

Scan results are snapshots. Run a new scan after meaningful content, media, builder, CDN, or configuration changes.

## Screenshots

![Review images, inspect their uses and protection, and apply an action to the selection.](.wordpress-org/screenshot-1.png)

![Restore images or review a permanent deletion from Trash. Each row shows its retention deadline.](.wordpress-org/screenshot-2.png)

## Important limitations

PixCensus cannot prove that an image is safe to delete. WordPress sites can reference media in ways that are impossible to discover reliably from the database alone.

Manual review may still be required for references stored in:

- theme or plugin files;
- custom CSS or JavaScript;
- dynamically generated markup or URLs;
- external services;
- unsupported page builders or unusual metadata;
- custom or unconfigured CDN transformations.

Large sites may also reach PHP execution-time or memory limits during a synchronous scan.

## Changes since 3.0.4

Manage selected images directly in each review tab. Protected images stay in place when a mixed selection moves to Trash. Follow the result link to review those images and change their protection. Only Trash offers permanent deletion, with a separate confirmation. Each trashed image shows when it was moved and the time remaining under the site's retention policy.

See the [3.4.9 audit and database test results](docs/security-audit/2026-11-21-pixcensus-3.4.9.md).

## Privacy and security

Scanning runs locally inside WordPress. PixCensus makes no remote requests, loads no remote executable code, and does not collect or transmit personal data.

Administrative screens and state-changing actions require the `manage_options` capability and protected requests. CSV formula-leading values are neutralized before export.

Scan results and CSV files may reveal filenames, paths, option names, and other site structure. Restrict them to trusted administrators.

Security reports should be submitted privately as described in [SECURITY.md](SECURITY.md).

## Development

Runtime code has no third-party dependencies. Development and QA dependencies are locked in `composer.lock` and `package-lock.json`.

The project CI covers supported PHP versions, WordPress 5.9 and current WordPress, WordPress 7.1 compatibility, multisite, authenticated AJAX behavior, Plugin Check, static analysis, security checks, reproducible packaging, and installation of the exact release ZIP.

Contributions should use a focused topic branch and preserve WordPress 5.9+, PHP 7.4+, WordPress Coding Standards, and the plugin's review and Media Trash safeguards.

## License

PixCensus — Media Usage Audit is licensed under [GPL-2.0-or-later](LICENSE).
