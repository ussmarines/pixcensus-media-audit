# Seven media-review improvements — 3.5.0

Prepared for the owner-authorized 3.5.0 GitHub and WordPress.org release. The qualification below was performed before release preparation. Compatibility remains WordPress 5.9+ and PHP 7.4+.

## Behavior

1. **Storage:** each scan records local original/generated-file byte sizes, dimensions, MIME type and upload date. Canonical file paths must resolve inside uploads before measurement. Stored/UI file keys are SHA-256 identifiers, not absolute paths. The selection total deduplicates shared files. Missing/offloaded files are marked incomplete. Trash retains files; measured bytes are not a promise of reclaimed disk space.
2. **Freshness:** native post, attachment, metadata, taxonomy, option and theme hooks update a site-local revision. The complete result carries the revision captured at scan start; a mismatch or an older snapshot without a revision displays a warning. Plugin bookkeeping, cron and transient changes are excluded. Direct database writes, external service changes and direct filesystem changes cannot reliably be detected.
3. **Explanation:** each row exposes detection context and raw escaped provenance, alongside existing permission-aware location links. Evidence remains capped at 12 labels per image. Absence of a match never proves safe deletion.
4. **Filtering:** weight, dimensions, upload-date and MIME filters/sorts apply to the entire tab before pagination. Search remains page-local and clears hidden selections. Byte/width filters use snapshot measurements; rerun the scan after changes. CSV is explicitly labelled as an export of the entire tab and does not apply the new measurement filters.
5. **Detection:** native `parse_blocks()` recognizes explicit image IDs in core image, cover, media-text, gallery and site-logo blocks, including nested blocks. Video cover/media-text and unrelated numeric attributes are excluded. Existing URL, shortcode, builder and metadata scanners remain in use. This adds targeted native-editor coverage, not a claim of support for every custom field or third-party integration.
6. **Batches:** the authenticated existing AJAX action advances one source batch under the existing atomic scan lock. A non-autoloaded, site-local checkpoint is persisted only after a successful batch. Client requests supply neither source cursors nor scan state. The last complete snapshot remains intact until all 14 phases finish. Closing the page pauses the work; another administrator can resume it within 24 hours of the last batch. Expired/version-mismatched checkpoints restart on the next scan request. Progress is phase-based, not a prediction of elapsed time. No background daemon, external queue, scheduled cleanup or runtime dependency is added.
7. **History:** site-local completed protection, trash, restore and deletion actions retain only timestamp, attachment ID, action and WordPress user ID. Up to 500 records from the last 90 days are shown; expired records are pruned on the next history write/view. Account-specific history participates in WordPress personal-data export and erasure. User 0 denotes an automated action. The history is an activity aid, not a tamper-proof or transactionally complete audit trail.

## WordPress API and directory rules

Official documentation consulted on 2026-11-25:

| Concern | Native mechanism / official source |
| --- | --- |
| Plugin architecture, libraries, privacy, directory conduct | [Plugin Handbook](https://developer.wordpress.org/plugins/), [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) |
| Authenticated batch requests | [AJAX](https://developer.wordpress.org/plugins/javascript/ajax/), existing POST, action nonce and `manage_options` checks |
| Local plugin storage | [Options API](https://developer.wordpress.org/plugins/settings/options-api/); plugin options are non-autoloaded and deleted on uninstall, including multisite |
| Image data | [`wp_get_attachment_metadata()`](https://developer.wordpress.org/reference/functions/wp_get_attachment_metadata/), core post metadata and uploads API |
| Editor structure | [`parse_blocks()`](https://developer.wordpress.org/reference/functions/parse_blocks/) (since 5.0) |
| Freshness and actual image actions | [`wp_after_insert_post`](https://developer.wordpress.org/reference/hooks/wp_after_insert_post/) (since 5.6), [`attachment_updated`](https://developer.wordpress.org/reference/hooks/attachment_updated/) (since 4.4), native metadata/option hooks |
| Personal-data tools | [Exporter](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/), [eraser](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/) |
| Current release reference | [New and updated in 7.1.0](https://developer.wordpress.org/reference/since/7.1.0/); new private or 7.1-only APIs are not required |

`wp_filesize()` was also checked: it is newer than the minimum compatibility target. Local file measurement uses PHP `filesize()` only after the existing canonical uploads-boundary checks. This is read-only; attachment trash/restore/deletion continues through the existing WordPress action handlers and APIs. No core files are patched, no remote executable code/telemetry is added, and native jQuery is retained.

## Limits and validation

Source reads are bounded to 200 attachments/posts/terms, 500 options, or 200 filesystem directory entries per batch. A single exceptionally large post/metadata value can still be expensive. The native block parser parses a whole document before the bounded block walk. The complete path map and accumulated results still occupy PHP memory and one serialized checkpoint; batches reduce request work but do not make memory independent of site size. Existing cleanup/restore verification still uses the synchronous scanner and is a separate scaling limit. Unreadable or changed directories can pause a batch while preserving the prior complete snapshot.

The native fixture is `tests/integration/review-improvements.php`; it refuses execution outside the explicitly named disposable loopback site. It exercises multiple attachment/post/option batches, synchronous/incremental result parity, shared-byte accounting, explicit native block detection, stale snapshots, bounded history, zero-retention image status changes and native privacy callbacks. Additional exact commands and outcomes are recorded in `.codex/test-ledger.json`. No production-scale, remote CI or production result should be inferred from these local tests.


## Qualification completed

- Exact runtime ZIP on WordPress 7.1.5 / PHP 8.3.33 / SQLite: 25 native integration assertions; 47 source batches and 1,231 images in the accumulated disposable fixture, including 205 orphan files and more than 200 terms.
- Same runtime on WordPress 5.9.13 / PHP 7.4.33 / MySQL 8.4.9: 25 assertions; 22 batches and 206 images; positive retention. The current fixture uses zero retention. Initial minimum-version setup with the current SQLite drop-in failed; the supported native MySQL fixture supersedes that infrastructure failure.
- PHPUnit: 64 tests / 271 assertions on PHP 7.4.33 and PHP 8.3.33, with the existing platform-dependent symlink case skipped.
- WPCS/PHPCompatibilityWP and PHPStan passed. Official Plugin Check reported no findings on the installed package.
- Chromium: complete scan, network failure recovery, persisted resume after reload, forged client cursors ignored, whole-tab filters, hidden-checkbox clearing, shared-file byte total, evidence expansion, desktop/mobile screenshots, no JavaScript errors. French strings were checked using native locale filters in the disposable fixture.
- The first browser response-body wait raced the automatic reload; the final test waits for completed navigation. Earlier failures remain visible in the ledger.
- The published version remains 3.4.9. Final packaging includes an updated privacy statement; runtime validation is reused when only that documentation changes. No commit, push, tag, external CI or publication was performed.

The portable browser regression is `tests/integration/review-ui.cjs`. It requires the explicitly named loopback fixture and its temporary login endpoint, a Playwright module (`PIXCENSUS_PLAYWRIGHT_MODULE`), optional Chromium executable (`PIXCENSUS_CHROMIUM_PATH`), and artifact directory (`PIXCENSUS_QA_ARTIFACTS`). Do not install the temporary login endpoint on a real site.

Final ZIP SHA-256: `57c732c8b6b662848b54d4bc0e027a58076071271f49847708df8819949dc2b9`. The fixed-header POT matches regeneration from the installed runtime with the CI WP-CLI 2.7.1 tool. The final French MO changes catalog metadata only; all translated messages match the browser-tested catalog.
