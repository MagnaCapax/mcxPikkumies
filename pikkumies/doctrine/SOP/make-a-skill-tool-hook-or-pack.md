# Make a skill, tool, hook or pack

One page for contributors and for the agent itself. Checked against Hermes Agent v0.21.5, tag v2026.9.24 (ADR-0005 to ADR-0009). `php tests/run.php` enforces the skill and pack rules.

## Where things go

| You want | Make | Lives in |
|---|---|---|
| A procedure the agent follows when a situation comes up | a skill | `pikkumies/skills/<name>/` (every machine) or `packs/<pack>/skills/<name>/` |
| A command a skill runs | a tool | `pikkumies/tools/<target>/` or `packs/<pack>/tools/<target>/` |
| A check on every tool call | a hook | `pikkumies/hooks/<event>/<name>.php` or `packs/<pack>/hooks/<event>/` |
| A bundle for one kind of system | a pack | `packs/<name>/` |
| How a problem got solved | a lesson, not any of the above | the lessons skill |

On a running box the agent never writes tracked files: `pikkumies update` stops if any is edited. Skills the agent makes for itself go to `~/pikkumies/local/hermes/skills/` (Hermes `skill_manage`). Hooks and changes to shipped skills come only from the repository, after review.

## Skill

`SKILL.md` with frontmatter, then plain steps:

```markdown
---
name: seedbox-apps
description: Check and restart the user's seedbox apps on the PMSS platform. Use when an app is down or its web UI does not load.
---
```

- `name` equals the directory name. It must be unique across core and all packs, and must not be a name Hermes already uses for a tool, toolset or built-in command (for example `memory`, `terminal`, `update`). Hermes refuses to load a skill name that exists twice.
- `description` is one line, at most 1024 characters (Hermes cuts longer ones silently). Put it in double quotes if it contains `: ` or ` #`; otherwise Hermes fails to parse it and lists the skill with an empty description. The description is the only part always in the prompt, so say what the skill does and when to use it.
- Every pack loads on every machine, so a pack skill first checks that it applies (root, PMSS, the app) and stops if not. The top of the agent's instructions says what this machine allows.
- Steps name exact commands with full paths (`php ~/pikkumies/tools/...`), say what to read before changing anything, and how to verify.

**Changing a shipped skill:** copy its directory to `~/pikkumies/local/hermes/skills/<new-name>/`, set `name: <new-name>` in the copy's `SKILL.md` (Hermes goes by that field, not the directory), edit the copy, and add the shipped name to `skills.disabled` in `~/pikkumies/local/config.php`. Never reuse the shipped name.

## Tool

- A PHP command-line script (PHP 7.3, no Composer, no Python), next to a `USAGE.md` that shows each command and says whether it changes anything.
- Read-only unless its job is a change. It writes only under `pikkumies/local/` or the lessons directory, never tracked files.
- Short, plain output (JSON when a skill parses it). Nothing leaves the machine unless the user asked for it.
- A skill calls it; tools add no tool schema to the prompt.

## Hook

`hooks/<event>/<name>.php` runs on every matching event; `pre_tool_call` is the one used today.

- Input on stdin is JSON: `hook_event_name`, `tool_name`, `tool_input` (the tool's arguments), `session_id`, `cwd`.
- To stop a tool call, print `{"action":"block","message":"..."}`. Anything else (no output, other actions, invalid JSON) means no opinion and the call runs.
- If the hook errors or takes longer than its 10-second timeout, the call runs (Hermes default; Pikkumies leaves `fail_closed` off). Hooks are safety prompts for the agent's own mistakes, not security; limits that must hold come from the OS.
- Filter on `tool_name` inside the script and exit quietly otherwise (see `hooks/pre_tool_call/guard-destructive.php`).
- Shipped hooks are registered without a consent prompt (`hooks_auto_accept`), which is why only reviewed repository code may add one.

## Pack

- `packs/<name>/pack.json`: `{"name": "<name>", "description": "..."}`, with `name` equal to the directory.
- Optional: `skills/`, `tools/<target>/`, `hooks/<event>/`, read-only lessons in `memory/`, `README.md`.
- Every pack is always loaded; users opt out with `packs.disabled` (ADR-0006).
- After adding or changing a pack, run `pikkumies packs index` and commit `packs/INDEX.md`.

## Before you commit

- `php tests/run.php` passes.
- A significant change in behaviour, interface, security or workflow ships with an ADR in `docs/adr/`.
