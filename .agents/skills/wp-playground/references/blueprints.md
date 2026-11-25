## Blueprint guidance when the optional skill is unavailable

Use an installed `blueprint` skill when available. Otherwise consult the [official Blueprint handbook](https://developer.wordpress.org/playground/blueprints/) and validate against the [published JSON schema](https://playground.wordpress.net/blueprint-schema.json).

- Read the current schema before authoring step names, resource shapes or version fields. Do not reuse removed examples as an API reference.
- Keep the requested WordPress/PHP compatibility explicit; project requirements override generic skill targets.
- Review Blueprint PHP, file writes, downloads, networking and persistence against the authorized task before execution.
- Run untrusted recipes only in an authorized disposable test environment, with no production data or credentials mounted.
- Enable adjacent-file reads only when the requested local bundle needs them and its contents have been reviewed.
- Validate the setup, plugins/themes, pages and other requested side effects before claiming success.
- Use [local CLI workflows](cli.md) or [website workflows](website.md) for execution and sharing.
