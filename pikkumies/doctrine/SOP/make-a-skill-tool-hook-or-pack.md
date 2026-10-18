# Make a tool unit, a hook or a documentation source

One page for contributors and for the agent itself. Checked against Hermes Agent v0.21.5, tag v2026.9.24 (ADR-0005 to ADR-0009, ADR-0015 to ADR-0021); Hermes now follows upstream releases (ADR-0033), so re-check names against a new release. `php tests/run.php` enforces the unit rules.

## Where things go

| You want | Make | Lives in |
|---|---|---|
| A command the agent runs, with the procedure for using it | a tool unit | `pikkumies/tools/<category>/<tool>/` |
| A procedure without its own command | a tool unit without a script | `pikkumies/tools/<category>/<name>/` |
| A check on every tool call | a hook unit | `pikkumies/hooks/<category>/<hook>/` |
| How a problem got solved | a lesson, not any of the above | the memory skill (`pikkumies save`) |
| A wiki or knowledge base the agent should search | a documentation source | a section in `pikkumies/config/documentationSources.md` (shipped) or `~/pikkumies/local/documentationSources.md` (one machine) |

On a running box the agent never writes tracked files: `pikkumies update` stops if any is edited. Skills the agent makes for itself go to `~/pikkumies/local/hermes/skills/` (Hermes `skill_manage`). Hooks and changes to shipped units come only from the repository, after review.

## Tool unit

Every capability is one directory (ADR-0015). Install, update and every run turn `skill.md` into the Hermes skill and list the unit in `tools/INDEX.md`; both are generated, never tracked.

```text
pikkumies/tools/torrent-clients/rtorrent/
  skill.md       for Hermes: when to use the tool and how
  USAGE.md       the verbs table (the one list of verbs) and examples
  README.md      for people: what it controls and how it finds it on each host
  rtorrent.php   the tool: php rtorrent.php <verb> [arguments] [--human]
```

**Categories can nest** (ADR-0055). A category may be split into subcategories, such as `tools/email/imap/<tool>/`; any directory below `tools/<category>/` that has no `skill.md` is a category, named by its path (`email/imap`). A category's `README.md` and `DOCTRINE.md` (rules for the agent, loaded into SOUL.md under that path while one of its units is on) sit in its own directory. `lib/` holds shared code and is never read for units, and a unit never holds another unit. The skill name is still unique across the whole tree.

**skill.md**: frontmatter with exactly `name` (equal to the directory) and `description`, then plain steps.

```markdown
---
name: rtorrent
description: Control rTorrent: list, inspect, add, remove, pause torrents, stats, restart.
---
```

- The name must be unique, and must not be a name Hermes already uses for a tool, toolset, command or bundled skill (for example `memory`, `terminal`, `update`); Hermes refuses a name that exists twice.
- The skills index shows only the first 60 characters of the description, on every turn: start with what the tool controls. Quote the description if it contains `: ` or ` #`. Hermes cuts it at 1024 characters.
- The generated skill adds the verbs table, the command line and the path of USAGE.md; do not repeat them in the body.
- Name exact steps: what to read before changing anything, which verb to use, how to verify it worked.

**USAGE.md**: the first table with the header `| verb | arguments | changes | what it does |` lists every verb. The contract test checks that it matches the verbs the tool answers to, in both directions. Write a literal `|` inside a cell as `\|`. In `changes` write `no` for read-only verbs, `yes` for changes, `deletes` for verbs that remove data, `loads` for verbs that put load on the shared server (the guard asks first), and `sends` for verbs whose data leaves the machine (a hook asks first: feedback-send for /feedback, ADR-0056; the mail guard for email, ADR-0053). Examples follow the table.

**The tool**: one PHP script (PHP 7.3, no Composer, no Python) that ends with `Cli::run($argv, ['verb' => function ($args, $opt) { ... }], $usage)`.

- Output is JSON Lines by default, the last line `{"ok":true}` or `{"ok":false,"error":...,"hint":...}`, with raw units (ADR-0016); `--human` prints the same records for people (ADR-0017). Return records; never print secrets (Cli drops secret-named fields anyway).
- Throw `CliError($what, $hint)` for a failure the user can act on.
- HTTP goes through `lib/Http.php`, which refuses a 127.0.0.1 port another account owns (ADR-0019). Read credentials from the user's own app config; never weaken an app's login.
- A verb that deletes data takes `--with-data`, or is named `prune`, `forget` or `purge`, so the delete guard asks the user first (ADR-0020).
- Write verbs read the state back and report `"verified"` in the status line.
- Tools of one category use the same verb names and record fields, listed in `tools/<category>/README.md`.

**Changing a shipped skill**: copy the generated `~/pikkumies/local/generated/skills/<category>/<tool>/SKILL.md` to `~/pikkumies/local/hermes/skills/<new-name>/SKILL.md`, set `name: <new-name>` in the copy, edit it, and add the shipped name to `skills.disabled` in `~/pikkumies/local/config.php`. Never reuse the shipped name. A whole category can be left out with `tools.disabled` (category paths: `email` also leaves out `email/imap`, and `media` does not touch `media-servers`).

## Hook

Every hook is one directory, laid out like a tool unit (ADR-0030). Hermes runs one entry per event, `hooks/orchestrator.php <event>`; it runs the units of that event in name order, each with its own timeout, and merges their answers the way Hermes merges hooks. Install, update and every run list the units in `hooks/INDEX.md` (generated, never tracked).

```text
pikkumies/hooks/guard/guard-destructive/
  hook.md                for the orchestrator: event, failure mode, timeout
  README.md              for people: what it checks and what it answers
  guard-destructive.php  the hook: JSON on stdin, one JSON answer on stdout
```

Hooks nest the same way (`hooks/<category>/<sub>/<hook>/`). `hooks/<category>/` is always a category. Below it, a directory with `hook.md` or `<hook>.php`, or with no subdirectory, is a unit: a unit that lost its `hook.md` shows up as broken and blocks every tool call, so the mistake is seen at once.

**hook.md**: frontmatter with exactly `name` (equal to the directory), `event`, `description`, `failClosed` and `timeout`, then a short note.

```markdown
---
name: guard-destructive
event: pre_tool_call
description: Blocks obviously destructive terminal commands and asks the user about data deletes.
failClosed: true
timeout: 10
---
```

- `event` is `pre_tool_call` (before each tool call; can block it or ask the user), `pre_llm_call` (before each turn; can add text to the user's message) or `post_tool_call` (after each tool call, with its result in `extra`; observe only, its output is ignored).
- `failClosed: true` (only for `pre_tool_call`): if the hook crashes, times out, exits non-zero without an answer or prints something that is not a JSON object, the call is blocked. Use it only for a hook whose silence would let real damage through, like the delete guard; `false` lets the call run when the hook fails.
- `timeout` is whole seconds, 1 to 60. The Hermes entry of an event gets the sum of its units' timeouts plus 2 seconds (at most 300), so keep hooks fast.

**The hook**:

- Input on stdin is JSON: `hook_event_name`, `tool_name`, `tool_input` (the tool's arguments), `session_id`, `cwd`, and `extra` with the event's own fields (for `pre_llm_call`: `user_message`, `is_first_turn`).
- To add text to the turn from `pre_llm_call`, print `{"context":"..."}`; keep it short, it goes to the model every time.
- To stop a tool call, print `{"action":"block","message":"..."}`. To make Hermes ask the user, print `{"action":"approve","message":"...","rule_key":"..."}`: put the exact command in the message (the prompt shows only the message) and make `rule_key` specific to that command, or one "always" answer approves every later call of the tool.
- Anything else means no opinion and the call runs. With several hooks on one event, a block wins over any approve, the first approve (in name order) wins over later ones, and `pre_llm_call` texts are joined (at most 9000 characters in total). Hooks are safety prompts for the agent's own mistakes, not security; limits that must hold come from the OS.
- Filter on `tool_name` inside the script and exit quietly otherwise (see `hooks/guard/guard-destructive/guard-destructive.php`).
- Each run is logged to `local/logs/hooks/YYYYMMDD.jsonl` (unit, milliseconds, outcome; never the payload; 7 days kept): look there when a hook is slow or failing.
- Shipped hooks are registered without a consent prompt (`hooks_auto_accept`), which is why only reviewed repository code may add one.

## Before you commit

- `php tests/run.php` passes.
- A significant change in behaviour, interface, security or workflow ships with an ADR in `docs/adr/`.
