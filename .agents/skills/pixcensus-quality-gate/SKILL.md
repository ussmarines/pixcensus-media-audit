---
name: pixcensus-quality-gate
description: "Use when verifying PixCensus changes, selecting necessary tests, preparing a correction or pre-commit validation, checking WordPress metadata or translations, or building and inspecting the distribution ZIP."
---

# PixCensus quality gate

1. Read `AGENTS.md`, `docs/codex/PROJECT_MAP.md`, and `.codex/test-ledger.json`.
2. Inspect the current read-only Git diff and identify the changed surfaces.
3. Reuse valid ledger results; run targeted checks first. Run global QA once only when the diff invalidates it.
4. Check plugin versions/metadata, text domain, GPL license, and the ZIP allow-list when distribution changes.
5. Record executed checks in `.codex/test-ledger.json`, inspect the final diff, and report modified files, reused/executed/blocked tests, Git status, and one proposed commit message.

Never write with Git, change branches, stage, commit, push, create a PR/tag/release, auto-bump versions, rerun unchanged passing tests before modification, or install tooling without demonstrated need.
