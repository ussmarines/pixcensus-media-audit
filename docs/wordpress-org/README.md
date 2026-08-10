# WordPress.org publication preparation

## Submission identity

- Plugin: **PixCensus — Media Usage Audit**
- Preferred slug: `pixcensus-media-audit`
- WordPress.org account: `ussmarines`
- Publication candidate: `3.0.2`
- Source branch: `release/3.0.2-hardening`
- Expected SHA-256: generated alongside the final reproducible `pixcensus-media-audit.zip`

## Current preparation status

The publication candidate is ready only after every release gate is green:

- GPL-2.0-or-later licensing;
- WordPress 5.9 minimum;
- PHP 7.4 minimum;
- tested through the current WordPress 7.0 security release and WordPress 5.9;
- synchronized plugin version and stable tag at 3.0.2;
- text domain and preferred slug set to `pixcensus-media-audit`;
- non-destructive behavior;
- no telemetry, remote executable code, or external service dependency;
- capability, nonce, validation, sanitization, escaping, and CSV formula protections;
- reproducible release ZIP with checksum and GitHub attestation.

## Before publishing to SVN

1. Build the exact ZIP twice from one clean commit and compare SHA-256 hashes.
2. Run `scripts/verify-wordpress-org-submission.ps1` with the explicit `3.0.2` version.
3. Confirm the GitHub release candidate checks and GitHub Security gates are green.
4. Run `scripts/prepare-wordpress-org-svn.ps1` without `-Commit` and review `svn status` plus `svn diff`.
5. Publish only after explicit approval of the final report.

## Repository layout prepared for WordPress.org

- `.wordpress-org/`: directory icons, banners, and internal editable sources;
- `screenshot-plan.md`: sanitized real-interface capture plan;
- `readme-screenshots-section.txt`: section to add once screenshots exist;
- `svn-publication.md`: first publication procedure after approval;
- `scripts/verify-wordpress-org-submission.ps1`: exact ZIP verification;
- `scripts/prepare-wordpress-org-svn.ps1`: cautious SVN working-copy preparation.

The obsolete 3.0.1 submission form and review-response template are preserved under `docs/historical/wordpress-org/`.

## Important release constraint

The existing 3.0.1 release ZIP, checksum, tag, and attestation remain immutable. Publication-preparation files are repository-only material and must not enter the 3.0.2 runtime ZIP.
