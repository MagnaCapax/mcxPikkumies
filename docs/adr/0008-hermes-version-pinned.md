# ADR-0008: Pin the Hermes version the wiring was verified against

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted. The install method (PyPI) and the 0.19.0 pin are superseded by ADR-0009.

## Context
The generated Hermes config, the hook output contract and the skill rules were verified against the source of Hermes Agent 0.19.0 (ADR-0005, ADR-0007). The installer asked pip for `latest`.

Two facts, checked on 2026-10-04:
- `latest` on PyPI already is 0.19.0, uploaded 2026-07-20. Hermes's own code lists pip as an unsupported install method (`hermes_cli/config.py`, `_UNSUPPORTED_INSTALL_METHODS`), and the project has published 14 releases on GitHub since then (v2026.7.30 to v2026.9.24).
- `pikkumies update` never reinstalls Hermes; it fast-forwards Pikkumies and regenerates the config. So the realistic drift is new Pikkumies config meeting an older Hermes, not Hermes changing underneath us. One path does change Hermes: `pikkumies run update` passes through to `hermes update`.

## Options Considered
- **Keep `latest`.** Records nothing about what was tested, and means a different version the day PyPI moves.
- **Pin 0.19.0 now; move the install channel later.** One line now, and the version on record matches the version verified.
- **Install from a pinned GitHub release tag now.** Gets current releases and security fixes, but how Hermes behaves when installed that way has not been checked yet.

## Decision
- The default `hermes.version` is `0.19.0`. A deployment can still override it in `local/config.php`.
- `pikkumies doctor` shows the installed Hermes version next to the pin, read from the venv's package metadata.
- The dead `fail_closed` key is no longer written: Hermes 0.19.0 never reads it, and a hook that errors or times out always lets the tool call run.
- A version bump is a commit that re-runs the live check from ADR-0005 (key reaches the endpoint, the guard hook blocks, skills load) and re-checks the tool-name list in `tests/ContractTest.php`.

## Consequences
- What was tested is what gets installed.
- 0.19.0 lacks the fixes in the 14 later releases. Moving the install off PyPI to a pinned release tag, having `update` reconcile the installed version with the pin, and closing the `hermes update` pass-through are tracked in issue #1.

## References
- ADR-0003, ADR-0005, ADR-0007
- PyPI `hermes-agent` JSON API; GitHub releases of the Hermes Agent project (both read 2026-10-04)
