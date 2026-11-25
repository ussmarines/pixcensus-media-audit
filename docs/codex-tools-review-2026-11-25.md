# Vendored skills and test evidence review — 2026-11-25

This maintenance change publishes the remaining local skill updates after PixCensus 3.5.0. It changes no addon runtime, release metadata, dependency manifest or workflow. The addon stays at 3.5.0.

## Review

The modified sources were compared with `WordPress/agent-skills` at `7374e6f1c913b3b02b25465d266d9c0e0a04fc96`. Per-file results are recorded in `codex-tools-update-2026-11-21.json`. The header-detector fixes come from that upstream revision. Local adaptations keep project rules authoritative and provide a Blueprint fallback because the optional `blueprint` skill is not bundled here.

The Playground CLI guidance was checked against the [official CLI reference](https://developer.wordpress.org/playground/developers/local-development/wp-playground-cli/). The `wp doctor check` arguments were checked in the [official command implementation](https://github.com/wp-cli/doctor-command/blob/main/src/Command.php). Browser/MCP/Sites API references were reviewed as text and compared with pinned upstream files; those integrations were not executed.

The helper scripts read files and write reports to stdout. The new regression tests execute them only against generated temporary fixtures, without site configuration or credentials. No downloaded skill snippets, runtime, connectors or production operations were activated by this review.

The local inventory remains a dated snapshot from 2026-11-21; global tool versions were not refreshed or certified as current. Public paths are repository-relative. Private backup paths are omitted while local backups remain intact.

## Test evidence

- Retain prior ledger entries unchanged, including historical failures and skips.
- Newly published historical commands have explicit path-redaction notes and SHA-256 fingerprints; the original exact commands remain in the local backup.
- Reuse the prior passing syntax baselines for unchanged detector scripts on Node 24.22.0.
- Execute `node --test tests/tooling/skill-detectors.test.mjs`: plain and PHPDoc plugin/theme headers, metadata, classification and a negative fixture.
- Execute `python tests/tooling/verify-skill-updates.py`: reference integrity, public inventory paths, historical ledger preservation, unchanged addon runtime and version.
- Reuse the 3.5.0 functional, compatibility, packaging and security evidence because runtime files, configuration and dependencies are unchanged. CI may still run its normal PR checks.

These are maintenance and source-review results, not a pre-install security certification or a new production qualification.
