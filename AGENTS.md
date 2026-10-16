# mcxPikkumies: notes for contributors and coding agents

## What this is

A lightweight helper agent that runs on the server it manages: as the user by default, with root as an optional capability layer. The runtime is `pikkumies/`, every capability is a tool unit under `pikkumies/tools/<category>/<tool>/`, decisions are in `docs/adr/`.

## Rules

- **License:** Apache-2.0. New files carry no per-file headers; LICENSE and NOTICE cover the project.
- **Language:** PHP, compatible with PHP 7.3 (no arrow functions, `match`, typed properties, `str_contains` or other 7.4+/8.x-only features). No Composer dependencies. Shell only as thin glue. No Python in this repository; Hermes hooks are PHP shell hooks (ADR-0003).
- **Naming:** camelCase for variables, functions and classes; domain first, action second.
- **ADRs:** a significant behaviour, interface, security or workflow change ships with an ADR in `docs/adr/` (template `0000-template.md`, one subject per ADR).
- **State is never tracked:** anything the user or the agent writes goes under `pikkumies/local/`, the lessons directory, or `pikkumies/include/hermes/`; generated files (`tools/INDEX.md`) are gitignored. Runtime code never writes tracked files; updates fast-forward only (ADR-0001, ADR-0015).
- **Provider-agnostic.** Never name which model provider a deployment uses: no vendor, hostname, model id or product name in code, docs, tests or commit messages. Provider and keys live only in the deployer's config. Naming an API format ("OpenAI-compatible") is fine.
- **Privilege comes from the OS, never from the agent's own config.** Root is detected (ADR-0004). Anything that must be enforced is enforced by the OS or by the server the agent talks to, never by a setting the agent's user can edit. Hooks are safety prompts, not security.
- **Privacy.** The agent works on its user's data at that user's request. It never sends anything off the machine that the user did not ask it to. Detection reads only the user's own processes. Performance telemetry is a user setting (`privacy.telemetry`, default on per the operator's decision of 2026-10-16), shown at first run and changeable with the `pikkumies-configure` skill or `install --telemetry=off`; nothing is sent until a sender exists, and Hermes' own telemetry stays off (ADR-0032).
- **Keep it small.** Each feature has to earn its lines. If the OS, Hermes or an existing tool already does it, leave it there.
- **Tool units, hooks, documentation sources:** follow `pikkumies/doctrine/SOP/make-a-skill-tool-hook-or-pack.md`.
- **Tests:** `php tests/run.php` must pass; CI runs it on PHP 7.3, 8.2 and 8.4.
