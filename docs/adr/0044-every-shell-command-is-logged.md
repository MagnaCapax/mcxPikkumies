# ADR-0044: Every shell command the agent runs is logged, one file per day

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Adds the hook event `post_tool_call` to the hook units ([ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md)). Amended 2026-10-18 by [ADR-0054](0054-tool-hooks-and-local-hooks.md) (a post_tool_call context becomes a hook note).

## Context
The request of 2026-10-18, verbatim: "make sure pikkumies logs every shell command it runs, on the sharded log directories, in case something needs to be traced".

What was found:
- Pikkumies logged no commands. Its hook log (`local/logs/hooks/YYYYMMDD.jsonl`, ADR-0030) records each hook unit run (event, unit, milliseconds, outcome) and never the payload. The memory metrics ([ADR-0041](0041-memory-metrics-save-reminder-and-wide-search.md)) record searches and saves.
- Hermes keeps each session's messages, tool calls included, in its session database (`messages.tool_calls`, `hermes_state_common.py` schema, Hermes v2026.9.24). That is a store to resume conversations from, not a log to trace with: one place per session, kept by the session history setting, and not readable line by line with standard tools.
- Hermes fires `post_tool_call` after every tool call (`model_tools.py` `_emit_post_tool_call_hook`, Hermes v2026.9.24) with the tool's arguments, its result, the session and tool call ids, the duration and a status. Shell hooks may run on it (`agent/shell_hooks.py` `_TOOL_EVENTS`), and its answer is ignored. The `terminal` tool's result is JSON with `output`, `exit_code` and `error` (`tools/terminal_tool.py`).
- `HookUnits::EVENTS` accepted only `pre_tool_call` and `pre_llm_call`, so a unit on `post_tool_call` was reported broken.
- "Sharded" log directories, as Pulsed Media lays out logs: one directory per producer and one JSON Lines file per UTC day, the layout the hook log already uses.

## Options Considered
- **Log in the `pre_tool_call` guard.** The guard would know the command but not whether it ran or how it ended, and logging is not its job. Rejected.
- **Read the commands out of Hermes' session database when needed.** That is not a trace: it depends on Hermes' schema and on the session still being kept. Rejected.
- **Log every tool call.** Most tool calls are reads. The request is shell commands. Rejected.
- **A `post_tool_call` hook unit that writes one line per `terminal` call to `local/logs/commands/YYYYMMDD.jsonl` (chosen).**

## Decision
- `HookUnits::EVENTS` gains `post_tool_call`. The orchestrator runs its units like the other events and prints no answer when none gives one; `failClosed: true` stays limited to `pre_tool_call`.
- New unit `hooks/trace/command-log/` (`post_tool_call`, fail open, 5 s). For each `terminal` call it appends one JSON line to `local/logs/commands/YYYYMMDD.jsonl` (UTC day), with `ts`, `session_id`, `tool_call_id`, `cwd`, `tool`, `command`, `background`, `exit_code`, `status`, `duration_ms` and `output_bytes`, plus `error` or `truncated` when they apply. The output itself is never logged.
- Secrets: the command goes through `Cli::cleanString` (URL passwords, tokens in URLs) and loses the value of password, token, key and secret options, `Authorization`/`Cookie`/`X-Api-Key` headers, `*KEY=`/`*TOKEN=`/`*SECRET=`/`*PASSWORD=`-style variables and `mysql -p<password>`. Commands longer than 4000 bytes are cut.
- Files: directory 0700, files 0600 (umask 0077 while writing), `LOCK_EX` appends.
- Kept as long as the session history: `sessions.retentionDays` (0 = forever, the default). A new day's file removes files older than that. The commands are already in the session history for that long, so the log adds no new kind of data and no longer life.
- Always on: tracing is the point, and a switch would be off exactly when something needs tracing. It stays on this machine and is never sent.

## Consequences
- What the agent ran, where, when and with what result can be read with `grep` and `jq` from one file per day, and one session's commands are found by its `session_id`.
- A secret in a shape the patterns do not know can land in a line. The file is readable only by the account that also holds the session history with the same command.
- One more PHP process after each tool call (a few milliseconds). The unit returns at once for tools other than `terminal`.
- Tests: `tests/CommandLogTest.php` (fields, redaction, never the output, 0700/0600, other tools ignored, plain-text results, orchestrator run with no answer, retention), mutation checked.

## References
- ADR-0030 (hook units, the hook log), [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) (settings, session retention), ADR-0041 (memory metrics)

## Amendment 2026-10-18: a post_tool_call context is used (ADR-0054)

- Hermes still ignores a `post_tool_call` answer. The orchestrator now keeps a unit's `{"context": "..."}` from that event as a hook note in `local/state/hook-notes/`, and the next `pre_llm_call` adds it to the turn as untrusted text written by that hook. `command-log` prints nothing, so its behaviour is unchanged.
