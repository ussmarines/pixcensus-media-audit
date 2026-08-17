# WordPress 7.1 compatibility validation

Target: WordPress 7.1 RC2, ahead of the scheduled August 19, 2026 final release.

## Applicability review

PixCensus does not hook into WordPress image generation or editing APIs such as `wp_generate_attachment_metadata`, `image_make_intermediate_size`, `wp_image_editors`, or `image_memory_limit`. It also does not depend on `@wordpress/components`, Gutenberg editor DOM access, the persistent editor toolbar, the Abilities API, or jQuery UI.

The WordPress 7.1 client-side media-processing change is therefore treated as a compatibility risk for the shape and references of Media Library data that PixCensus reads, not as a direct API integration change.

## Automated validation

The dedicated `.github/workflows/wordpress-71-compat.yml` workflow builds the distributable ZIP and installs it into a WordPress 7.1 RC2 `wp-env` environment. It verifies activation, WordPress core version, AJAX authorization/integration behavior, scanner smoke assertions, Plugin Check, and relevant WordPress debug-log output.

The existing smoke suite covers attachment creation and usage detection, Gutenberg-style image IDs, URLs with query strings/fragments, drafts, options beyond the first scan batch, large post batches, scan locking, non-autoloaded result storage, and non-destructive uninstall behavior.

## Browser-only client-side processing

WordPress 7.1 client-side image resize/compression/conversion behavior depends on a supported browser upload path and cannot be exercised by the CLI smoke suite. Because PixCensus has no upload/editor hooks and reads the resulting attachment records rather than participating in processing, this browser-only path is not a release blocker unless automated WordPress 7.1 tests expose a data-shape regression or a manual browser test finds one.
