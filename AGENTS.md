# Codex project rules

- Read `docs/codex/PROJECT_MAP.md` and `.codex/test-ledger.json` before changing the project. Do not rescan the whole repository when the project map is current.
- Use `git diff` to identify changes since the audited commit and inspect the complete diff before delivery.
- Preserve WordPress 5.9+ and PHP 7.4+ compatibility unless an explicit decision changes them. Project compatibility overrides the installed WordPress skills' newer target baseline.
- Follow the WordPress Coding Standards.
- Keep scans read-only. The explicit cleanup action may trash reviewed attachments through WordPress from any review category when manual protection is absent. Permanent deletion requires a separately confirmed selection of already-trashed images through WordPress. Never manipulate user files directly.
- Preserve capability checks, nonces, input validation, sanitization, SQL safety, and late output escaping.
- Do not add a frontend framework or pipeline without a demonstrated need.
- Do not add a production dependency without justification; use development dependencies for QA tools.
- Never push directly to `main` unless the owner has explicitly authorized that exact bounded operation in the current request. Never force-push or rewrite history.
- Do not bump the plugin version for documentation-only or CI-only changes.
- For every future release, synchronize the PHP plugin header, `PIXCENSUS_VERSION`, `readme.txt` stable tag and changelog, and POT version/catalog.

## Secrets, identité et agents

- Lire et appliquer `SECURITY_PRODUCTION_RULES.md` avant toute opération touchant la configuration, la CI, la production, une release ou des credentials.
- Ne jamais ouvrir, afficher, copier ou résumer un `.env`, un fichier de credentials, un coffre, une clé privée ou `~/.codex/auth.json` sans nécessité exacte et autorisation explicite.
- Vérifier les chemins, permissions, schémas et noms de variables sans exposer les valeurs.
- Les secrets restent dans un coffre et sont injectés uniquement à l’exécution. Ils ne passent ni dans les prompts, arguments, URL, logs, captures, artefacts ou rapports.
- Tout secret exposé doit être révoqué ou tourné immédiatement, puis l’incident et sa cause doivent être examinés.
- Utiliser uniquement l’identité publique `ussmarines` et le profil `https://github.com/ussmarines`.

## Test reuse protocol

Before modifying code, do not rerun a previously passing test when its tool, exact command, configuration, relevant environment, and covered files are unchanged since the tested commit. Treat that entry in `.codex/test-ledger.json` as the valid baseline, apply the requested change first, then run only checks affected by the new diff.

A pre-modification rerun is allowed only when the result is missing, failed, stale, or invalidated by a code, configuration, dependency, tool, command, or environment change. Record every executed check in `.codex/test-ledger.json` with its exact command, tool version, date, tested commit, result, duration, coverage, configuration, and environment signature.
