# ADR-0003: Hermes integration through HERMES_HOME, external skill dirs and shell hooks

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted; the provider key wiring, hook consent and guard action are superseded by [ADR-0005](0005-hermes-wiring-verified-against-source.md), the same-name skill override by [ADR-0007](0007-customising-shipped-skills.md), and pack doctrine fragments by [ADR-0006](0006-every-pack-always-loaded.md), and the skill directories by [ADR-0015](0015-tool-units-one-skill-per-tool.md), and the hook layout by [ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md), and the Hermes install (a vanilla git checkout in `pikkumies/hermes`) by [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md).

## Context
Hermes Agent (MIT, NousResearch) is the harness. Pikkumies must wire its skills, hooks and doctrine into Hermes without forking it. It must also keep Hermes state out of git and avoid Python in this repository.

## Options Considered
- **Fork Hermes.** High maintenance burden.
- **Gateway hooks (`HOOK.yaml` + `handler.py`).** Python only, run in the gateway only, and cannot block a tool call.
- **Shell hooks declared in `config.yaml`.** Any language; run in CLI and gateway; can block a tool call (ADR-0005: block is the only `pre_tool_call` action Hermes acts on).

## Decision
- **Home and install.** `HERMES_HOME` is `pikkumies/local/hermes` (gitignored). Hermes itself goes into a virtualenv in `pikkumies/include/hermes/`, not through its upstream installer, which edits shell profiles.
- **`config.yaml`** is generated on install and update:
  - `model`: `provider: custom`, `base_url`, `default`, and `key_env: PIKKUMIES_API_KEY`. The key lives only in `$HERMES_HOME/.env`.
  - `skills.external_dirs`: core `skills/` plus each enabled pack's `skills/`. These have the lowest precedence, so a local skill with the same name overrides a shipped one.
  - `hooks`: every `hooks/<event>/<name>.php` in core and enabled packs, run as `php <script>` with JSON on stdin and stdout.
- **`SOUL.md`** is assembled from `doctrine/{soul,voice,doctrine,safety}.md` plus each enabled pack's `doctrine.md`.
- **Hooks are not a security boundary.** They are safety prompts for the agent's own mistakes; the user owns the config and can remove them. Real limits come from the OS and from the provider's server.

## Consequences
- No Python in the repository; hooks are PHP like the rest.
- Hand edits to generated Hermes files are overwritten. Users change `local/config.php` or add local skills instead.

## References
- Hermes docs: `website/docs/user-guide/features/skills.md` (external_dirs, precedence), `hooks.md` (shell hooks), `integrations/providers.md` (custom endpoint)
