# mcxPikkumies: notes for contributors and coding agents

## What this is

A lightweight helper agent that runs on the server it manages: as the user by default, with root as an optional capability layer. The runtime is `pikkumies/`, every capability is a tool unit under `pikkumies/tools/<category>/<tool>/`, decisions are in `docs/adr/`.

Start with [`development/`](development/README.md): the [engineering principles](development/engineering-principles.md) explain every rule below with its reason and the mistake that taught it, and list the sibling repositories' docs and decisions.

## Principles

KISS, DRY, YAGNI. Delete first: question the requirement, delete the part, simplify, then speed up, then automate (a needed but broken part is debugged, not deleted). Pit of success: safe defaults, risky paths explicit and loud. Never break old users. Tests that can fail. Files around 150 lines (a target, not a hard limit). Accepted ADRs are binding: follow them or supersede them with a new ADR, never work around them. Before committing, review the change with `pikkumies/tools/development/code-review` against these rules and the ADRs.

## Rules

- **License:** Apache-2.0. New files carry no per-file headers; LICENSE and NOTICE cover the project.
- **Language:** PHP, compatible with PHP 7.3 (no arrow functions, `match`, typed properties, `str_contains` or other 7.4+/8.x-only features). No Composer dependencies. Shell only as thin glue. No Python in this repository; Hermes hooks are PHP shell hooks ([ADR-0003](docs/adr/0003-hermes-integration.md)).
- **Naming:** camelCase for variables, functions and classes; domain first, action second.
- **ADRs:** a significant behaviour, interface, security or workflow change ships with an ADR in `docs/adr/` (template `0000-template.md`, one subject per ADR).
- **State is never tracked:** anything the user or the agent writes goes under `pikkumies/local/` or the lessons directory; Hermes is a vanilla upstream checkout in `pikkumies/hermes/`, installed by `pikkumies install` and updated only by its own updater (`pikkumies run update`, [ADR-0033](docs/adr/0033-vanilla-hermes-git-checkout-updated-separately.md)); generated files (`tools/INDEX.md`) are gitignored. Runtime code never writes tracked files; updates fast-forward only ([ADR-0001](docs/adr/0001-layout-install-and-update.md), [ADR-0015](docs/adr/0015-tool-units-one-skill-per-tool.md)).
- **Provider-agnostic.** Never name which model provider a deployment uses: no vendor, hostname, model id or product name in code, docs, tests or commit messages. Provider and keys live only in the deployer's config. Naming an API format ("OpenAI-compatible") is fine.
- **Privilege comes from the OS, never from the agent's own config.** Root is detected ([ADR-0004](docs/adr/0004-provider-key-and-root-detection.md)). Anything that must be enforced is enforced by the OS or by the server the agent talks to, never by a setting the agent's user can edit. Hooks are safety prompts, not security.
- **Privacy.** The agent works on its user's data at that user's request. It never sends anything off the machine that the user did not ask it to. Detection reads only the user's own processes. Performance telemetry is a user setting (`privacy.telemetry`, default on per the operator's decision of 2026-10-16), shown at first run and changeable with the `pikkumies-configure` skill or `install --telemetry=off`; no telemetry sender exists, so nothing is sent as telemetry, and Hermes' own telemetry stays off ([ADR-0032](docs/adr/0032-settings-configure-skill-first-run-and-install-flags.md)). The one thing Pikkumies sends to Pulsed Media is `/feedback`: one payload the user asked for, sealed to Pulsed Media's key, sent after Hermes' approval prompt asks the user. That prompt catches the agent's own and casual sends; an agent steered by text it reads could still run the sender some other way, as it could run curl, so it is a safety prompt, not a security boundary ([ADR-0056](docs/adr/0056-feedback-what-pikkumies-sends-to-pulsed-media.md)).
- **Keep it small.** Each feature has to earn its lines. If the OS, Hermes or an existing tool already does it, leave it there.
- **Tool units, hooks, documentation sources:** follow `pikkumies/doctrine/SOP/make-a-skill-tool-hook-or-pack.md`.
- **Documentation:** a feature is documented in a `docs/` page or in its unit README. The root README gets at most one line and a link; its first code block stays the one-line install ([ADR-0036](docs/adr/0036-documentation-layout.md)).
- **Tests:** `php tests/run.php` must pass; CI runs it on PHP 7.3, 8.2 and 8.4.
