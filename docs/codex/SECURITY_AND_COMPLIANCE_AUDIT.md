# Security and compliance audit

## Audit identity

- Date: 2026-07-12 (Europe/Paris).
- Original audit reference commit: `54e640aaeb0ded2999273cd08ba16c8a70e3260c`; final distribution revalidation commit: `def18f0be8fe0b2ebe248dbc33e39d9f86847efa`.
- Inspected branch: `main` (pre-existing; Codex did not create or switch branches).
- Scope: every tracked runtime, development, test, documentation, CI, skill, configuration, translation, lock, and distribution source in the repository, plus the generated untracked ZIP.
- Starting worktree: clean. All changes described here were produced by this audit; `dist/pixcensus-media-audit.zip` is generated and ignored.
- Compatibility preserved: WordPress 5.9+, PHP 7.4+, tested metadata through WordPress 7.0.

## Executive assessment

Before correction, the posture was **moderate with two validated high-severity issues**: authors could invoke a site-wide audit and export global provenance, and exported site data was not protected against spreadsheet formula interpretation. Availability, settings-validation, uninstall, CI, dependency, and packaging gaps were also validated. After correction and final CI revalidation, the posture is **good but not proven secure**: the high findings are fixed and unit/static/package/runtime checks pass, while synchronous large-site scanning, heuristic false negatives, and missing full AJAX/multisite coverage remain material residual risks.

Finding totals: **0 critical, 2 high, 4 medium, and 4 low**. All critical/high findings are corrected. No validated medium finding is intentionally left uncorrected; residual limitations are documented separately.

## Threat model

The repository-specific model is maintained in [`docs/codex/pixcensus-media-audit-threat-model.md`](pixcensus-media-audit-threat-model.md). It covers anonymous users, subscribers, contributors, authors, editors, administrators, multisite super administrators, admin pages, AJAX/admin-post actions, GET/POST input, options, posts/drafts, metadata, terms, CDN settings, stored results, CSV, activation/uninstall, multisite, dependencies, CI, and the ZIP.

Trust boundaries are:

1. authenticated browser to WordPress admin handlers;
2. administrator-controlled settings to scanner normalization;
3. scanner to WordPress database and uploads filesystem;
4. stored snapshot to HTML/JavaScript and CSV consumers;
5. repository/dependencies/actions to CI and distribution ZIP.

The most important integrity consequence is indirect: a false negative can label a used image “unused”; a human may then delete it outside this non-destructive plugin, breaking content. The plugin itself contains no media deletion, move, rewrite, or update path.

## Tools and skills

Used tools: RTK, Git read-only commands, ripgrep, PowerShell 7.6.3, Node.js 24.18.0, npm 11.16.0, Docker 29.6.1, PHP 7.4.33 and 8.3.32 containers, Composer 2.2 plus Composer 2 current for advisory lookup, PHPCS 3.12.2, WPCS 3.1.0, PHPCompatibilityWP 2.1.5, PHPStan 1.12.27, PHPUnit 9.6.35, `@wordpress/env` 11.10.0, GitHub public Actions API/logs, and the project ZIP scripts.

Used skills: `wp-project-triage`, `wp-plugin-development`, `wp-plugin-directory-guidelines`, `wp-phpstan`, `wp-playground`, `security-threat-model`, and `openai-docs`. No skill or third-party tool was installed. The triage/plugin detectors returned `unknown`/zero plugins because their known header regex misses standard PHPDoc plugin headers; manual inspection and `PROJECT_MAP.md` supplied the correct classification.

Codex Security’s official `deep-security-scan` and `security-diff-scan` workflows were documented but not callable because the Codex Security plugin was absent. No deep scan is claimed. The fallback was the complete repository review, centralized finding validation, targeted corrections/tests, and a final security review of the complete diff.

## Official sources consulted

All sources were consulted on 2026-07-12.

| Source | Requirement used | Resulting change |
| --- | --- | --- |
| [WordPress Plugin Security](https://developer.wordpress.org/plugins/security/) and [Nonces](https://developer.wordpress.org/apis/security/nonces/) | Nonces are CSRF controls, not authorization; validate capability server-side | Restricted all surfaces to `manage_options`; split AJAX nonces by action |
| [Sanitizing Data](https://developer.wordpress.org/apis/security/sanitizing/) and [Escaping Data](https://developer.wordpress.org/apis/security/escaping/) | Validate early, reject malformed structures, escape late by context | Bounded scalar IDs/CDN rules; retained contextual escaping |
| [Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) | 18 directory rules, GPL, readable source, no telemetry/remote code/trialware/spam | Removed fictitious screenshots; documented source/privacy; verified all 18 rules |
| [WordPress Coding Standards](https://github.com/WordPress/WordPress-Coding-Standards) | WPCS and PHP compatibility | PHPCS/PHPCompatibility configuration retained and passed |
| [Plugin Check](https://github.com/WordPress/plugin-check) | Official static/runtime directory checks without broad exclusions | Corrected CI target; Plugin Check passes against the ZIP-installed plugin |
| [WP-CLI `i18n make-pot`](https://developer.wordpress.org/cli/commands/i18n/make-pot/) | Generate catalog from real source strings | Corrected mounted path/exclusions; deterministic POT comparison passes in CI |
| [Uninstall methods](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/) | Guard uninstall and remove only owned data | Preserved guard; fixed multisite restoration and batched site iteration |
| [GitHub Actions security hardening](https://docs.github.com/en/actions/security-for-github-actions/security-guides/security-hardening-for-github-actions) | Least-privilege token and immutable action references | Added `contents: read`; pinned current actions to full commit SHAs |
| [Composer audit](https://getcomposer.org/doc/03-cli.md#audit) and [npm audit](https://docs.npmjs.com/cli/v11/commands/npm-audit) | Check locked development dependencies | Upgraded development-only PHPUnit 9.6.24 to 9.6.35; npm audit passed |
| [Codex deep security scan](https://learn.chatgpt.com/use-cases/deep-security-scan) | Deep scan for repository discovery; diff scan for working-tree patch | Documented unavailable plugin/workflow accurately; used grounded fallback |

## File inventory and distribution classification

| Class | Files |
| --- | --- |
| Runtime/distribution | `pixcensus-media-audit.php`, `includes/*.php`, `views/admin-page.php`, `assets/admin.{js,css}`, `uninstall.php`, `readme.txt`, `LICENSE`, `languages/pixcensus-media-audit.pot` |
| Development/QA | `composer.*`, `package*.json`, `phpcs.xml.dist`, `phpstan.neon.dist`, `phpunit.xml.dist`, `.wp-env.json`, `scripts/*` |
| Tests | `tests/bootstrap.php`, `tests/unit/*`, `tests/integration/*`, `tests/README.md` |
| Documentation | `README.md`, `SECURITY.md`, `docs/codex/*` |
| CI | `.github/workflows/qa.yml` |
| Codex | `AGENTS.md`, `.codex/test-ledger.json`, `.agents/skills/*` |
| Distribution policy | `.distignore`, `.gitignore` |
| Generated/temporary | `vendor/`, `node_modules/`, `dist/pixcensus-media-audit.zip`, wp-env/Docker state; all excluded from Git/ZIP as applicable |
| Obsolete | None proven; no tracked file was deleted |

The ZIP allow-list contains exactly 11 runtime files beneath `pixcensus-media-audit/`: the main plugin, uninstall, readme, license, two assets, three include classes, POT, and admin view. It excludes Git, CI, Codex, docs, tests, scripts, locks/configs, caches, development dependencies, and prior archives.

## Validated findings and corrections

| ID | Severity | Evidence and exploitability | Correction | Validation |
| --- | --- | --- | --- | --- |
| IUA-SEC-001 | High | `upload_files` normally lets authors invoke a global scan/export exposing private post IDs, option names, filenames, and paths | All menu/render/action/export checks now require `manage_options`; action-specific nonces retained | Static review, PHPCS/PHPStan, and successful wp-env role smoke in public CI |
| IUA-SEC-002 | High | `fputcsv` quoting does not stop spreadsheet interpretation of values beginning `=`, `+`, `-`, `@`, tab, or carriage return | Added `PIXCENSUS_CSV::neutralize_formula()`, UTF-8 BOM, nosniff header, quoted filename | Dedicated data-provider tests for all markers; 20 tests/35 assertions pass |
| IUA-PERF-001 | Medium | Concurrent full-site scans and an unbounded options result could exhaust workers/memory; result option could autoload | Atomic expiring lock, non-autoloaded writes, options batches of 500, plugin-option exclusion, bulk cap, safe iterator failure | Unit/static checks and successful scan-lock assertion in wp-env smoke |
| IUA-SEC-003 | Medium | CDN aliases/rewrites accepted malformed, excessive, overly broad values, increasing CPU and false classifications | Pure validator: host-only aliases, max 20 aliases/rules, byte limits, HTTP(S)/path sources, upload-path targets, explicit rejection | Valid/invalid/long/count unit matrix passes |
| IUA-SC-001 | Medium | Mutable GitHub Action tags and PHPUnit 9.6.24 advisory `CVE-2026-24765` weakened development supply chain | Pinned checkout v5, setup-node v5, setup-php 2.35.5 by SHA; upgraded PHPUnit to 9.6.35; least-privilege workflow | Composer audit after update and final workflow review |
| IUA-DIST-001 | Medium | No reproducible allow-listed ZIP or package smoke gate existed | Added `.distignore`, cross-platform PowerShell builder/inspector, CI ZIP installation/smoke/Plugin Check path | Local ZIP passes with 11 entries and correct root/metadata |
| IUA-DATA-001 | Low | Scanner included its own old results and retained invalid/deleted manual IDs, allowing stale self-feedback | Excluded plugin options from option scan and intersected manual IDs with current image IDs | Static review and unit suite |
| IUA-LIFE-001 | Low | Multisite uninstall used `switch_to_blog()` repeatedly without unwinding the switch stack and loaded all site IDs | `restore_current_blog()` after each switch, batches of 100, plugin-only option list including lock | Static/PHPCS validation and successful single-site uninstall/media-preservation smoke; multisite runtime coverage remains open |
| IUA-CI-001 | Low | Public HEAD CI failed because `rg` was absent and the mounted plugin path/slug was wrong | Node metadata validator replaces runner `rg`; corrected source/ZIP plugin paths; added timeouts/audits/cleanup | Public CI run `29207713713` passed all four jobs at the corrected commit |
| IUA-DOC-001 | Low | `readme.txt` listed three absent screenshots and privacy/security/distribution details were incomplete | Removed screenshot section; expanded privacy/data/uninstall/source docs; added `SECURITY.md` and accurate README | Metadata validator: five tags, 124-character summary, no screenshot claim |

## Authorization, CSRF, input, output, SQL, files, and privacy review

- Anonymous, subscriber, contributor, and author direct requests are denied by `manage_options`; editors are denied unless a site grants that capability; administrators and multisite super administrators retain access through WordPress capability mapping.
- Settings, CSV, scan, single mark/unmark, and bulk mark/unmark use server-side capability checks and purpose-specific nonces. A nonce is never treated as authorization.
- GET tab/filter/page/notice values are sanitized and allow-listed. Single IDs are absolute integers and verified as attachments. Bulk arrays reject non-scalars, empty/malformed input, and more than 500 values.
- CDN aliases/rules are normalized and rejected rather than silently accepting malformed structures. Stored legacy-invalid settings are ignored by the scanner.
- Admin HTML uses late `esc_html*`, `esc_attr*`, `esc_url`, `esc_textarea`, or constrained `wp_kses_post`; JavaScript creates notices with `.text()` and contains no `.html()` sink.
- No user data enters SQL. The only direct query uses a trusted core table name, a prepared numeric cursor, fixed `LIMIT 500`, ordered batches, no cache, and no write.
- No controllable include/path, arbitrary read/write, upload endpoint, remote fetch, eval, shell execution, archive extraction, telemetry, analytics, external script/style, pixel, webhook, or self-update path exists at runtime.
- Serialized content is scanned as text/arrays supplied by WordPress; the plugin does not call unsafe `unserialize()` on attacker data.
- Upload traversal is read-only and catches inaccessible-directory iterator failures. No deactivation cleanup deletes data. Uninstall deletes only six plugin-owned options and never user media/content.

## WordPress.org 18-guideline review

| Guideline | Verdict |
| --- | --- |
| 1 GPL-compatible | Pass: `GPL-2.0-or-later`, complete GPLv2 text, no runtime third-party bundle |
| 2 developer responsibility | Pass from inspected repository; authorship/asset ownership beyond the repository cannot be independently proven |
| 3 stable SVN version | Not applicable until WordPress.org publication; do not let GitHub releases supersede future SVN |
| 4 readable code/source | Pass: unminified PHP/JS/CSS and public GitHub source |
| 5 no trialware | Pass: no license key, quota, expiry, locked feature, or upsell |
| 6 SaaS conditions | Not applicable: no SaaS |
| 7 consent/data collection | Pass: no outbound runtime request or telemetry |
| 8 remote executable code | Pass: all runtime JS/CSS is local; no self-update/remote loader |
| 9 honest/legal behavior | Pass from inspected code/docs; no compliance guarantee or harmful behavior |
| 10 forced links | Pass: no frontend output/backlink |
| 11 admin hijacking | Pass: plugin-scoped native Media submenu; no global nag/iframe/ad |
| 12 readme spam | Pass: five relevant tags, no affiliate link/keyword stuffing |
| 13 core libraries | Pass: WordPress `jquery` handle; no bundled core library |
| 14 SVN release use | Not applicable before submission; ZIP procedure is release-oriented |
| 15 versions | Pass for the coordinated 2.2.6 functional release |
| 16 complete plugin | Pass in the validated ZIP and runtime CI baseline |
| 17 trademarks/copyright | Pass: functional name/slug, no third-party trademark prefix |
| 18 directory discretion | Informational; future review may impose updated requirements |

The validated GPL ZIP is technically aligned with the WordPress.org guidelines and passed Plugin Check/runtime CI. This is submission readiness, not an acceptance guarantee. A verified private vulnerability-reporting channel remains a recommended owner operation before broad public distribution.

## False positives and justified exceptions

- WPCS direct-database/no-cache warnings are narrowly ignored only on the necessary read-only options enumeration; WordPress has no API to list all options, the table name is core-controlled, the cursor is prepared, and the limit is fixed.
- WPCS filesystem advice is narrowly ignored for `fwrite()` to `php://output`; WP_Filesystem is not appropriate for streaming an HTTP CSV response.
- The recursive upload scan and `file_exists()` calls are intentional read-only operations; no user-supplied path is accepted.
- PHPCompatibility must run under PHP 7.4 because the pinned sniff itself emits newer-runtime deprecations; this does not weaken the declared PHP 7.4 target.

## Tests and validation status

Reusable pre-change baseline: JavaScript syntax, version/domain/readme/license checks, secret scan, diff whitespace, PHP 7.4 PHPCS/PHPStan/PHPUnit (3 tests/7 assertions), PHP 8.3 syntax, and JSON validation from `.codex/test-ledger.json`; public CI independently confirmed PHP 7.4/8.3 analysis/tests/syntax before failing on missing `rg`.

Post-change targeted results:

- PHPUnit 9.6.35: 20 tests, 35 assertions, pass.
- PHPCS/WPCS/PHPCompatibility on runtime PHP: pass after targeted corrections.
- PHPStan level 1 with WordPress stubs and 1G: pass.
- Node syntax: pass.
- Metadata/readme/license validator: pass (five tags, 124-character summary).
- npm audit: zero vulnerabilities.
- Composer validate: strict pass.
- Composer audit initially found `CVE-2026-24765`; PHPUnit was upgraded to 9.6.35. Composer 2.2 cannot run `audit`, so Composer 2 current is the advisory authority.
- ZIP: pass, 11 runtime entries, correct root/required metadata, no development path.

Local `wp-env start` attempts failed during Docker image builds because the container could not resolve `api.github.com`. The later public GitHub Actions run `29207713713` at commit `def18f0be8fe0b2ebe248dbc33e39d9f86847efa` completed successfully: ZIP build/install/activation, role separation, functional scan, scan locking, uninstall/media preservation, Plugin Check, and deterministic POT comparison all passed. Full AJAX request and multisite runtime coverage remain future work.

## Residual risks and limitations

- The scanner remains synchronous and non-resumable. Posts, metadata, terms, attachments, and the upload tree are still broad enumerations; very large sites can time out or exhaust memory despite options batching and locking.
- Heuristic false negatives remain possible for custom CSS, theme/plugin files, dynamic/external data, unsupported builders, unusual ID structures, non-standard paths, and unconfigured CDN transforms. False positives remain possible for generic builder `id` keys.
- Full server-level tests for missing/invalid nonces, AJAX error codes, manual actions, export headers/body, multisite uninstall, and partial scanner failures are authored only in part; the successful smoke covers role separation, scanning, locking, single-site uninstall, and media preservation.
- Provenance is intentionally capped at 12 labels and snapshots become stale until rerun.
- GitHub private vulnerability reporting is enabled and linked from `SECURITY.md`; no public issue or email is required for an initial private report.
- Plugin Check and the source-message POT catalog are verified by public CI run `29207713713`; complete AJAX request and multisite test coverage is still absent.
- This is a time-bounded code/configuration audit, not a guarantee of perfect security or a penetration test of a production WordPress installation.

## 2.2.6 release delta (2026-07-13)

- GitHub Private Vulnerability Reporting was enabled through the official repository API and rechecked as enabled. `SECURITY.md` now links directly to the private `Report a vulnerability` flow without an email address.
- AJAX handlers now require POST, validate the exact action, return stable JSON status codes for authentication/authorization/nonce/input failures, and expose matching unauthenticated hooks that return JSON 401 without performing work.
- The expiring scan lock now carries an owner token. Expired takeover uses a conditional delete before atomic `add_option()`, and an older request cannot release a replacement lock. Scanner exceptions preserve the last complete snapshot and return a generic failure.
- Attachment, post, metadata, term, and option reads are bounded. Result and lock options remain non-autoloaded; the synchronous full attachment map/upload traversal remains the documented scale boundary.
- New unit and disposable WordPress coverage exercises 39 tests / 131 assertions, authenticated HTTP AJAX, WordPress 5.9.13 and 7.0.1, multisite isolation/network activation/uninstall, more than one post/options batch, stale/concurrent locks, draft behavior, heuristic variants, exact-ZIP activation, Plugin Check, and media/content preservation.
- No new runtime dependency, custom table, cron, queue, background process, or destructive media behavior was introduced.

## Release recommendation

The audited technical changes justify the coordinated `2.2.6` release. Publish only after the exact ZIP, checksum, compatibility jobs, Plugin Check, POT reproducibility, pull-request CI, and post-merge `main` validation pass. Documentation-only updates after this coordinated release do not independently justify another version bump.
