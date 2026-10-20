# Make a tool unit, a hook or a documentation source

One page for contributors and for the agent itself. Checked against Hermes Agent v0.21.5, tag v2026.9.24 ([ADR-0005](../../../docs/adr/0005-hermes-wiring-verified-against-source.md) to [ADR-0009](../../../docs/adr/0009-hermes-from-a-pinned-release-tarball.md), [ADR-0015](../../../docs/adr/0015-tool-units-one-skill-per-tool.md) to [ADR-0021](../../../docs/adr/0021-web-access-off-unless-configured.md)); Hermes now follows upstream releases ([ADR-0033](../../../docs/adr/0033-vanilla-hermes-git-checkout-updated-separately.md)), so re-check names against a new release. `php tests/run.php` enforces the unit rules.

## Where things go

| You want | Make | Lives in |
|---|---|---|
| A command the agent runs, with the procedure for using it | a tool unit | `pikkumies/tools/<category>/<tool>/` |
| A procedure without its own command | a tool unit without a script | `pikkumies/tools/<category>/<name>/` |
| A check on every tool call | a hook unit | `pikkumies/hooks/<category>/<hook>/` |
| A check on one tool's calls | a tool hook | `pikkumies/hooks/tools/<tool>/<hook>/` (shipped) or `~/pikkumies/local/hooks/tools/<tool>/<hook>/` (one machine) |
| A check of your own, on one machine | a local hook unit | `~/pikkumies/local/hooks/<category>/<hook>/` |
| A rule of the user's for one tool or category, on one machine | a local note | `pikkumies-configure note-add <tool or category>` writes `~/pikkumies/local/tools/<path>/DOCTRINE.md` ([ADR-0076](../../../docs/adr/0076-local-notes-for-a-tool-or-category.md)) |
| How a problem got solved | a lesson, not any of the above | the memory skill (`pikkumies save`) |
| A wiki or knowledge base the agent should search | a documentation source | a section in `pikkumies/config/documentationSources.md` (shipped) or `~/pikkumies/local/documentationSources.md` (one machine) |

On a running box the agent never writes tracked files: `pikkumies update` stops if any is edited. Skills the agent makes for itself go to `~/pikkumies/local/hermes/skills/` (Hermes `skill_manage`). Shipped hooks and changes to shipped units come only from the repository, after review. Hooks for one machine go to `~/pikkumies/local/hooks/`, in the shipped format, written on the user's request (the guard asks before each write there, [ADR-0054](../../../docs/adr/0054-tool-hooks-and-local-hooks.md)). A rule for one tool or category on one machine is a local note: `pikkumies-configure note-add`, which adds it after the shipped text and keeps it across updates; copying a skill under a new name (below) replaces the skill instead, and the copy no longer gets the shipped skill's updates.

## Tool unit

Every capability is one directory (ADR-0015). Install, update and every run turn `skill.md` into the Hermes skill and list the unit in `tools/INDEX.md`; both are generated, never tracked.

```text
pikkumies/tools/torrent-clients/rtorrent/
  skill.md       for Hermes: when to use the tool and how
  USAGE.md       the verbs table (the one list of verbs) and examples
  README.md      for people: what it controls and how it finds it on each host
  rtorrent.php   the tool: php rtorrent.php <verb> [arguments] [--human]
```

**Categories can nest** ([ADR-0055](../../../docs/adr/0055-recursive-discovery.md)). A category may be split into subcategories, such as `tools/email/imap/<tool>/`; any directory below `tools/<category>/` that has no `skill.md` is a category, named by its path (`email/imap`). A category's `README.md` and `DOCTRINE.md` (rules for the agent, loaded into SOUL.md under that path while one of its units is on) sit in its own directory. `lib/` holds shared code and is never read for units, and a unit never holds another unit. The skill name is still unique across the whole tree.

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

**USAGE.md**: the first table with the header `| verb | arguments | changes | what it does |` lists every verb. The contract test checks that it matches the verbs the tool answers to, in both directions. Write a literal `|` inside a cell as `\|`. In `changes` write `no` for read-only verbs, `yes` for changes, `deletes` for verbs that remove data, `loads` for verbs that put load on the shared server (the guard asks first), and `sends` for verbs whose data leaves the machine (a hook asks first: feedback-send for /feedback, [ADR-0056](../../../docs/adr/0056-feedback-what-pikkumies-sends-to-pulsed-media.md); ticket-send for /ticket, [ADR-0089](../../../docs/adr/0089-support-tickets-with-the-ai-key.md); the mail guard for email, [ADR-0053](../../../docs/adr/0053-email-category.md)). Examples follow the table.

**The tool**: one PHP script (PHP 7.3, no Composer, no Python) that ends with `Cli::run($argv, ['verb' => function ($args, $opt) { ... }], $usage)`.

- Output is JSON Lines by default, the last line `{"ok":true}` or `{"ok":false,"error":...,"hint":...}`, with raw units ([ADR-0016](../../../docs/adr/0016-jsonl-is-the-default-output.md)); `--human` prints the same records for people ([ADR-0017](../../../docs/adr/0017-human-output-option.md)). Return records; never print secrets (Cli drops secret-named fields anyway).
- Throw `CliError($what, $hint)` for a failure the user can act on; that refusal line also carries `stop` ([ADR-0065](../../../docs/adr/0065-a-tool-refusal-carries-a-stop-field.md)).
- HTTP goes through `lib/Http.php`, which refuses a 127.0.0.1 port another account owns ([ADR-0019](../../../docs/adr/0019-hosts-and-credentials.md)). Read credentials from the user's own app config; never weaken an app's login.
- A verb that deletes data takes `--with-data`, or is named `prune`, `forget` or `purge`, so the delete guard sees it and the call waits for the user's yes unless they asked for it ([ADR-0020](../../../docs/adr/0020-deletes-need-the-users-approval.md)).
- Write verbs read the state back and report `"verified"` in the status line.
- Tools of one category use the same verb names and record fields, listed in `tools/<category>/README.md`.

**Changing a shipped skill**: copy the generated `~/pikkumies/local/generated/skills/<category>/<tool>/SKILL.md` to `~/pikkumies/local/hermes/skills/<new-name>/SKILL.md`, set `name: <new-name>` in the copy, edit it, and hide the shipped one with `pikkumies-configure skills disable <shipped-name>`. Never reuse the shipped name. A whole category can be left out with `tools.disabled` (`pikkumies-configure set tools.disabled ...`, which asks the user first) (category paths: `email` also leaves out `email/imap`, and `media` does not touch `media-servers`).

## Hook

Every hook is one directory, laid out like a tool unit ([ADR-0030](../../../docs/adr/0030-hooks-as-units-run-by-one-orchestrator.md)). Hermes runs one entry per event, `hooks/orchestrator.php <event>`; it runs the units of that event in name order, each with its own timeout, and merges their answers the way Hermes merges hooks. Install, update and every run list the units in `hooks/INDEX.md` (generated, never tracked).

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

- `event` is `pre_tool_call` (before each tool call; can block it or ask the user), `pre_llm_call` (before each turn; can add text to the user's message) `post_tool_call` (after each tool call, with its result in `extra`; observe only, a printed context waits for the next turn) or `post_api_request` (after each model request, with `usage`, `context_length` and `platform` in `extra`; observe only, its output is ignored, [ADR-0061](../../../docs/adr/0061-status-line-memories-context-tool-calls-code-lines.md)).
- `failClosed: true` (only for `pre_tool_call`): if the hook crashes, times out, exits non-zero without an answer or prints something that is not a JSON object, the call is blocked. Use it only for a hook whose silence would let real damage through, like the delete guard; `false` lets the call run when the hook fails.
- `timeout` is whole seconds, 1 to 60. The Hermes entry of an event gets the sum of its units' timeouts plus 2 seconds (at most 300), so keep hooks fast.

**The hook**:

- Input on stdin is JSON: `hook_event_name`, `tool_name`, `tool_input` (the tool's arguments), `session_id`, `cwd`, and `extra` with the event's own fields (for `pre_llm_call`: `user_message`, `is_first_turn`).
- To add text to the turn from `pre_llm_call`, print `{"context":"..."}`; keep it short, it goes to the model every time.
- To stop a tool call, print `{"action":"block","message":"..."}`. To stop it unless the user wants it, print `{"action":"approve","message":"...","rule_key":"..."}` with the exact command in the message, and `"kind":"delete"` (or `send`, `mail`, `exposure`) when it destroys or sends something that has no copy. Hermes never shows an approval prompt (approvals are off): the orchestrator's ask policy lets the call run, or blocks it with a fixed message until the user says yes in the chat, which a light-model review of the user's own words then finds; with no evidence of the user's words only those kinds stay blocked ([ADR-0073](../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)).
- Anything else means no opinion and the call runs. With several hooks on one event, a block wins over any approve, and the ask policy reviews every approve of the call together; `pre_llm_call` texts are joined (at most 9000 characters in total) (ADR-0054). Hooks are safety prompts for the agent's own mistakes, not security; limits that must hold come from the OS.
- Filter on `tool_name` inside the script and exit quietly otherwise (see `hooks/guard/guard-destructive/guard-destructive.php`).
- Each run is logged to `local/logs/hooks/YYYYMMDD.jsonl` (unit, milliseconds, outcome; never the payload; 7 days kept): look there when a hook is slow or failing.
- Shipped hooks are registered without a consent prompt (`hooks_auto_accept`), which is why only reviewed repository code may add one.
- `post_tool_call` output is ignored by Hermes; a `{"context":"..."}` printed there becomes a hook note the agent reads at the start of the user's next turn (not right after the call).

**Tool hooks** (ADR-0054): a unit in `hooks/tools/<tool>/<hook>/` runs only for calls of `<tool>`, on `pre_tool_call` or `post_tool_call`. `<tool>` is a Pikkumies tool unit (`rtorrent`) or a Hermes tool (`terminal`, `write_file`; the list is `HookUnits::HERMES_TOOLS`). A Hermes-tool hook runs when `tool_name` is that tool. A unit hook runs when the call names `<tool>.php` or `tools/<category>/<tool>`, when Hermes runs inside that unit, or when the call runs `php` on a variable (`php $R ...`); it still checks the verb itself. A script path built in code, an alias, or a `cd` from an earlier call is out of its sight. A tool hook cannot change a call (`modify` is ignored). An unknown tool, another event or a deeper directory makes it a broken unit, which blocks every tool call, so `php tests/run.php` fails first.

**Local hooks** (ADR-0054): the same units under `~/pikkumies/local/hooks/` (also `local/hooks/tools/<tool>/<hook>/`) run after every shipped hook, on this machine only, and an update never touches them.

- They are read only while `~/pikkumies/local` is yours and closed to group and others (Pikkumies keeps it 0700). Directory names on a unit's path use only `A-Z a-z 0-9 . _ -`; nothing in a unit, or in a `lib/` on its path, may be a symlink or another account's; and together those files stay under 1 MiB (keep data elsewhere). This keeps out other accounts, not the agent.
- A new or edited local hook, or edited shared code in a `lib/` on its path (`local/hooks/lib/`, `local/hooks/<category>/lib/`), runs from the next start you make yourself: that start prints what changed to your terminal before Hermes opens (and puts it first in SOUL.md); until then the agent is told it is waiting. A start from inside a session (`pikkumies generate` run by the agent) records nothing.
- A local hook that answers `approve` is treated like a send: the call is blocked until the user's own yes in the chat clears it, and blocked outright where no yes can be checked (ADR-0073). Use it only for calls no shipped check covers, or block instead.
- A local hook runs with Hermes' environment, credentials included (the provider key from `local/hermes/.env`), like every hook: treat one like any program you run, and write it only on the user's request.
- A local hook never blocks by failing: `failClosed` is ignored, and a crash, a timeout or a bad `hook.md` only skips it. Its `modify` is ignored. Skipped hooks are named to the agent on the next turn and by `pikkumies doctor`.
- The Hermes entry's timeout includes local hooks from the next start; a local hook whose timeout no longer fits in the time left is not started.
- A local hook's `pre_llm_call` text reaches the agent as `Local hook <id> (written on this machine): ...`.

**Interval units** ([ADR-0067](../../../docs/adr/0067-interval-hook-units.md)): a check that looks back over a stretch of the session, every few minutes, in the background. Shipped only; a local one is refused.

- `hook.md` says `event: transcript` and adds `every` (default seconds between runs, 60 to 3600) and, optionally, `wake` (the record kinds that make a run worth starting, a comma list of `user`, `assistant`, `reply`, `tool`, `change`; default any). `failClosed: false`; `timeout` 1 to 110; `every` more than `timeout` + 15.
- It runs at most once per interval per session, started after a `post_tool_call` or `post_llm_call` answer, never inside a turn's wait. `hooks.every.<unit name>` (seconds, 0 = off) and `hooks.async.disabled` turn it down or off.
- Input on stdin is one JSON object: `unit`, `session` (sha1 of the session id), `interval`, `now`, `first_run`, `scheduled` (a Hermes cron session), `state` (what it returned last time), `dropped` (when the size cap removed some of its records) and `records`, oldest first, each with `seq`, `ts`, `turn_id`, `kind` and: `user` (`text` at most 4000, `first_turn`, `synthetic` for a row Hermes added), `assistant` (`text` at most 1500), `reply` (`text` at most 3000, `compacted` when the turn's start was lost), `tool` (`tool`, `args` as JSON at most 2000, `status`, `error_type`, `error`, `exit_code` for terminal, `change` true, false or "unknown"). Texts are redacted; a tool's output is never there.
- Output is one JSON object, every key optional: `context` (at most 4000 characters, shown at the next turn), `notice` (at most 900; holds the next call that may change data once, `noticeOn` may only be `change`), `holdsPerTurn` (1 to 3, default 1), `state` (an object of at most 4096 bytes, given back next time) and `skip` (true: keep these records for the next run). It is advisory: it can hold one call once or add text, never approve or change a call. Flatten any text you pass on from the records to one line and label it as untrusted data; the orchestrator cannot tell it from your own words.

## Before you commit

- `php tests/run.php` passes.
- A significant change in behaviour, interface, security or workflow ships with an ADR in `docs/adr/`.
- Link the first mention of a local ADR in each markdown file, relative to that file: `[ADR-0020](../../../docs/adr/0020-deletes-need-the-users-approval.md)`. The link test fails on an unlinked mention and prints the exact link; another project's ADR is linked to that project, an example goes in backticks, and the four core doctrine files keep plain text because they are copied into SOUL.md ([ADR-0060](../../../docs/adr/0060-links-and-the-link-graph.md)).
