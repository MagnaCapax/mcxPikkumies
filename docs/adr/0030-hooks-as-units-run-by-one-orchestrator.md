# ADR-0030: Hooks are units like tools, run by one orchestrator per event

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-16
- Status: Accepted. Supersedes the hook parts of [ADR-0003](0003-hermes-integration.md) (`hooks/<event>/<name>.php`, one Hermes entry per script) and of [ADR-0015](0015-tool-units-one-skill-per-tool.md) (units cover tools only).

## Context
The operator's request of 2026-10-16: "then we do hooks/*/ which follows the exact same pattern as tools/ and hooks/orchestrator.php to trigger the hooks for each event ... make it as identical to tools as possible and you can."

Until now each hook was a script `hooks/<event>/<name>.php`, and `HermesConfig::hookEntries()` wrote one Hermes shell-hook entry per script (ADR-0003). Every entry got a 10 second timeout. Only the delete guard failed closed, and that was decided by a file-name check in `HermesConfig` ([ADR-0020](0020-deletes-need-the-users-approval.md)). A hook had no description or README of its own, and the agent had no index of the hooks.

Facts this rests on, checked 2026-10-16 against Hermes v2026.9.24:
- A shell hook's stdout is read by `agent/shell_hooks.py` `_evaluate_result` and `_parse_response`. A spawn error or timeout lets the call run unless the entry has `fail_closed`. Exit code 2 on `pre_tool_call` blocks, with the stdout block or the stderr text (at most 400 characters) or "Blocked by shell hook." as the message. Other non-zero exits still have their stdout read. A `fail_closed` entry blocks on stdout that is not a JSON object. A block without a message gets the default message, so it still blocks. An approve's `message` and `rule_key` are trimmed.
- `fail_closed` applies only to `pre_tool_call` (`_BLOCKING_EVENTS`), the timeout is clamped to 300 seconds (`MAX_TIMEOUT_SECONDS`), and one shell hook answers with one directive.
- Hermes merges the hooks of `pre_tool_call` in `hermes_cli/plugins.py` `_get_pre_tool_call_directive_details`. A valid block wins over any approve whatever the order, and a block without a message is skipped. Among approves the first valid one wins. Modify args are shallow-merged in order. Anything else is ignored.
- `pre_llm_call` contexts are joined with a blank line (`agent/turn_context.py` `_collect_pre_llm_call_context`). A context over 10000 characters is spilled to a file (`tools/hook_output_spill.py`).

## Options Considered
- **Keep one Hermes entry per script.** No new code, but the fail-closed rule stays a file-name special case, and a hook stays one bare script, unlike a tool.
- **Units, and still one Hermes entry per unit.** Hermes does the merging. But the units would be read twice, once by Pikkumies for the index and once as Hermes config, and the per-hook settings would live in two places.
- **Units, and one entry per event running an orchestrator (chosen).** This is the operator's design. The unit's own `hook.md` decides its event, timeout and failure mode, and the orchestrator applies them. The cost is that the orchestrator must merge answers exactly as Hermes would.

## Decision
- **Layout.** Each hook lives in `pikkumies/hooks/<category>/<hook>/`, the same way as `tools/<category>/<tool>/`, and holds:
  - `hook.md`, with frontmatter of exactly `name` (equal to the directory), `event` (`pre_tool_call` or `pre_llm_call`), `description`, `failClosed` (`true`|`false`; `true` only for `pre_tool_call`) and `timeout` (1 to 60 seconds);
  - `README.md` for people;
  - `<hook>.php`, the hook.
  `lib/HookUnits.php` finds and validates the units, like `lib/Units.php` does for tools.
- **The shipped hooks move unchanged.** They are now `guard/guard-destructive` (fail closed), `search/search-first-gate` and `search/search-first` (fail open). Every timeout stays at 10 seconds. Only their `lib/` paths change.
- **One Hermes entry per event.** The command is `php -d display_errors=stdout -d error_reporting=4437 <root>/hooks/orchestrator.php <event>`, for each event that has units, so an orchestrator that cannot even compile prints text instead of nothing. The `pre_tool_call` entry always has `fail_closed` (amended 2026-10-16: it used to follow the units' `hook.md`, so a broken `hook.md` could turn it off), and the timeout is the sum of the units' timeouts plus 2 seconds, at most 300. `hooks_auto_accept` stays. The file-name special case for the delete guard is gone.
- **The orchestrator** reads stdin once and runs the units of the event in name order, each as its own `php` process with the same stdin and its own timeout:
  - Each unit's output is read as `_evaluate_result` reads a shell hook.
  - One addition: a fail-closed unit that exits non-zero with no answer also blocks. With PHP's `display_errors` off, a fatal error leaves stdout empty and exits 255, which Hermes would let through.
  - Every `hooks/<category>/<hook>/` directory is a unit, even with a missing or unreadable `hook.md` (amended 2026-10-16; amended 2026-10-18, [ADR-0055](0055-recursive-discovery.md): a directory there with subdirectories but no `hook.md` and no `<hook>.php` is a nested category, see the amendment below). For `pre_tool_call` any broken unit blocks the call, whatever event or `failClosed` it claims; for `pre_llm_call` a broken unit is skipped.
  - All units run even after a block, as Hermes runs all hooks, so their side effects (the search-first markers) stay as before.
  - The orchestrator itself blocks on a fatal error of its own for `pre_tool_call`: its crash handler is registered before it loads `lib/`, and the requires are wrapped so a broken library file blocks too.
- **Merging** follows Hermes:
  - `pre_tool_call` uses the plugins.py rules above.
  - Because one shell hook gives one directive, an approve together with a modify is answered as the approve. Asking the user is the safety part. No shipped hook answers modify.
  - `pre_llm_call` joins contexts with a blank line and cuts the total at 9000 characters, under Hermes' 10000-character spill.
- **Log.** One JSON line per unit run goes to `local/logs/hooks/YYYYMMDD.jsonl` (UTC): `ts`, `event`, `unit`, `ms` and `outcome`. The payload is never logged. When a new day's file is created, all but the newest 7 files are removed.
- **Index.** `generate` writes `hooks/INDEX.md` (gitignored, like `tools/INDEX.md`): one line per hook with its event, failure mode, timeout and description. Hook units with problems are reported with the tool units.

## Consequences
- A hook is added by adding a directory. Its failure mode and timeout are in its own `hook.md`, not in `HermesConfig`.
- Hermes sees two entries instead of three. A failing or slow unit can be found in the hook log.
- One more PHP process start per event (the orchestrator) on top of one per unit; a unit run took 20 to 30 ms in testing.
- Hooks run one after another, so an event's worst case is the sum of its units' timeouts. The 60 second cap per unit and the 300 second Hermes cap bound it.
- Hermes' consent allowlist now holds the orchestrator command per event, not each script. A changed unit needs no new consent, which is acceptable only because `hooks_auto_accept` already trusts shipped code (ADR-0003). Hooks are still safety prompts, not a security boundary (AGENTS.md).
- A timed-out unit is killed, but not any processes it started (Hermes kills the hook's process group); the shipped hooks start none.

## References
- ADR-0003, [ADR-0011](0011-documentation-sources-and-search-first.md), ADR-0015, ADR-0020, [ADR-0028](0028-search-first-every-turn-and-a-light-model-for-hooks.md)
- Hermes v2026.9.24: `agent/shell_hooks.py`, `hermes_cli/plugins.py`, `agent/turn_context.py`, `tools/hook_output_spill.py`

## Amendment 2026-10-18: hook units at any depth (ADR-0055)

- A hook unit may sit below a nested category, such as `hooks/tools/<tool>/<hook>/`. `hooks/<category>/` itself is always a category, so a category holding only a README is never a unit.
- Below that, a directory is a unit when it holds `hook.md` or `<hook>.php`, or has no subdirectory. A unit whose `hook.md` is missing is still a broken unit, also when it has subdirectories such as `fixtures/`, and it still blocks every `pre_tool_call`. The walk never goes into a unit.
- A symlinked directory, a directory that cannot be read, or a category deeper than 8 levels that still has subdirectories, is a broken unit. A `lib/` directory is skipped.
- A contract test checks that every shipped hook unit has no errors, so a shipped broken unit cannot block every call by accident.

## Amendment 2026-10-18: tool hooks, local hooks and merged questions ([ADR-0054](0054-tool-hooks-and-local-hooks.md))

- A unit in `hooks/tools/<tool>/<hook>/` runs only for calls of `<tool>` (`pre_tool_call` or `post_tool_call`); every other unit still runs on every call of its event. A broken one still blocks every `pre_tool_call`.
- The user's own units in `local/hooks/` run after all shipped units, in the same name order, only as they were recorded at the last start, and always fail open: a local unit's crash, timeout or broken `hook.md` skips it and is named to the agent, never blocks. Their `pre_llm_call` context is labelled with the unit's name.
- `modify` is honoured only from shipped units outside `hooks/tools/`.
- Merging `pre_tool_call` no longer keeps only the first approve: two or more become one question showing every unit's question, keyed `pikkumies-multi:`, or a block naming the units when together they pass 350 characters. One approve alone is unchanged. Reading and merging answers moved to `lib/HookAnswers.php`.
- The Hermes entry's timeout counts local units, and is passed as the command's last argument (`orchestrator.php <event> <timeout>`), so a local unit is started only while it still fits. An event with only local units gets an entry.
- A `post_tool_call` unit's context is kept as a hook note and added to the next `pre_llm_call`, marked as untrusted text from that hook.

## Amendment 2026-10-19: interval units ([ADR-0067](0067-interval-hook-units.md))

- `hook.md` may say `event: transcript`, a unit-only event, with one more field, `every` (60 to 3600 seconds), and optionally `wake`. Such a unit never gets a Hermes entry and no unit of a real event runs it: the orchestrator records each turn for it and starts it in the background at most once per interval (`hooks/worker.php`). Its `timeout` may be up to 110 seconds and `failClosed` must be false. A broken unit that declares it still blocks every `pre_tool_call`.
- While one is on, the orchestrator also runs on `post_llm_call` (an entry of 5 seconds) and, at `pre_tool_call`, may hold one call for an interval unit's notice when no unit had an opinion. The unit runner and the hook log moved to `lib/HookSpawn.php` and `lib/HookLog.php`, unchanged.
