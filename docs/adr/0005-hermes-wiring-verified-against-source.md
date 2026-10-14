# ADR-0005: Hermes wiring verified against the Hermes source (supersedes parts of ADR-0003)

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted. The guard bullet ("Escalation is not available to shell hooks in this version") is superseded by ADR-0020; the skill directories by ADR-0015.

## Context
The first live run of Pikkumies under Hermes Agent 0.19.0 against an OpenAI-compatible endpoint found that three ADR-0003 decisions, taken from the Hermes documentation, do not match what the Hermes code does:

1. **Key.** With a bare `model.provider: custom`, Hermes takes the key from an inline `model.api_key` only. It never reads `model.key_env`, so the request went out without a key and failed with HTTP 401. `key_env` is read for entries under `providers:` (`hermes_cli/runtime_provider.py`).
2. **Hook consent.** A shell hook declared in `config.yaml` is registered only after first-use consent. A non-interactive run skips an unapproved hook without a message, unless `hooks_auto_accept: true` is set or `--accept-hooks` is passed (`agent/shell_hooks.py`). The destructive-command guard therefore never ran.
3. **Hook actions.** A `pre_tool_call` shell hook can only block. Its output is parsed for `{"action": "block"}` (or `{"decision": "block"}`); any other action, including `approve`, is ignored and the tool call runs (`_parse_response` in `agent/shell_hooks.py`).

Two Pikkumies gaps showed up in the same run:

4. **Model.** No model was written when the deployer set none, and Hermes needs one.
5. **Late key.** `run` did not regenerate the Hermes config, so a key set after install, as the README tells users to do, never reached Hermes.

## Options Considered
- **Inline `model.api_key` in `config.yaml`.** Works, but puts the key in a second file, against ADR-0004.
- **A named provider `providers.pikkumies` with `key_env`.** Keeps the key in `$HERMES_HOME/.env` only.
- **Hooks:** pass `--accept-hooks` from `run`, or write `hooks_auto_accept: true`. The flag would not cover the gateway or a direct `hermes` call; the config line covers every entry point.
- **Guard:** block, or keep escalating to human approval. Escalation is not available to shell hooks in this version.

## Decision
- `config.yaml` declares `providers.pikkumies` (`base_url`, `key_env: PIKKUMIES_API_KEY`, `default_model`). `model` is `provider: "custom:pikkumies"` with `default` set to the model.
- The model is the deployer's `provider.model`, or else the first id the endpoint lists at `/models`.
- When any hook is shipped, `config.yaml` sets `hooks_auto_accept: true`. Pikkumies ships these hooks, and the installer gives the consent.
- The guard blocks destructive commands. Its message tells the agent to show the user the exact command, so the user can run it themselves.
- `run` regenerates the Hermes config (profile, packs, `config.yaml`, `.env`, `SOUL.md`) before starting Hermes.
- ADR-0003 stays in force for everything else: home, venv, external skill directories, SOUL assembly, and hooks being safety prompts rather than security.

## Consequences
- The documented flow (install, then set the key, then `run`) works.
- The guard really stops `rm -rf` and the other listed commands in non-interactive runs. Verified live: before the fix the agent deleted a scratch directory; after it, the call is blocked.
- Hermes behaviour is pinned to what the installed version's code does. Re-verify against the source when the Hermes version changes, not against the documentation.

## References
- ADR-0003 (superseded on the points above), ADR-0004 (key only in `.env`)
- Hermes Agent 0.19.0: `hermes_cli/runtime_provider.py` (named provider and bare custom key resolution), `agent/shell_hooks.py` (consent, `_parse_response`)
