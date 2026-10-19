# ADR-0061: Memories, context, tool calls and code lines on the `pikkumies tmux` status line

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Adds to the tmux status line of [ADR-0040](0040-credits-line.md); adds the `post_api_request` event to the hook units of [ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md).

## Context
The request of 2026-10-19: the status line should also show how many memories there are ("Memories: "), the context size, code +/- and other fun stuff, and maybe a tool calls counter.

What was found:
- The only live line Pikkumies owns is the `status-right` of the `pikkumies` tmux session (ADR-0040). Hermes' own status bar takes no field from outside without Python or a patch (ADR-0040, [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)). That bar already shows the context used out of the window, with a bar and a percent.
- Memories: the files `pikkumies doctor` counts (`MemoryMetrics::memoryFiles`). A save is named `YYYYMMDD-<slug>.md` with the UTC date (`Lessons::save`), so the files saved today are known from their names.
- Tool calls and code lines: `post_tool_call` fires after every tool call, delegated agents' included, with the result under `extra` (Hermes v0.22.2 `model_tools.py`; [ADR-0044](0044-every-shell-command-is-logged.md)). A `patch` result carries a unified `diff` (`tools/file_operations_common.py` `PatchResult`); a `write_file` call carries its `content`.
- Context: `post_api_request` fires after every model request. For a shell hook its fields are under `extra` (`agent/shell_hooks.py` `_payload_fields`): `usage.prompt_tokens`, `context_length` and `platform`, which is "subagent" for a delegated agent (`agent/turn_response_intake.py`, `tools/delegate_tool.py`). Shell hooks may use it (`hermes_cli/plugins.py` `VALID_HOOKS`).
- Which session to count for: a tmux status line has no Hermes session id, and delegated agents have their own. Each tmux pane has `TMUX_PANE` in its environment, and tmux 3.5a expands `#{pane_id}` inside a status-right `#()` to the active pane's id before the command runs (checked on a private tmux server: `%0`, then `%1` after selecting the second pane). Hermes passes its own environment to shell hooks unchanged in a single-profile run (`build_subprocess_env(scrub_secrets=is_multiplex_active())`, nothing strips `TMUX_PANE`); read in the source, not seen in a running Hermes.
- tmux cuts `status-right` from the left when the terminal is too narrow (checked: a 60-column client kept the end of the line and the clock).

## Options Considered
- **Hermes' state database for tool calls and tokens.** A schema Pikkumies does not own (the reason [ADR-0044](0044-every-shell-command-is-logged.md) logs commands itself), and `pdo_sqlite` is not on every host. Rejected.
- **Keying by Hermes session id.** The status line cannot know it, and delegated agents have their own ids. Rejected for the tmux pane.
- **Leaving the context to Hermes' own bar.** Narrows what was asked, while `post_api_request` gives it for one small process per model request. Rejected.
- **More counters (duration, disk, torrents).** Hermes' bar shows the duration; disk and torrent figures need process runs every minute; the credits line already covers spending. Not added.
- **Two observer hook units writing per-pane counters, a `status-line` subcommand reading them, ahead of the credits line in the existing status-right.** Chosen.

## Decision
- **Counters** (`lib/SessionCounters.php`): one file per pane, `local/state/session-counters/pane<N>.json` (directory 0700, file 0600), integers only: `since`, `tools`, `added`, `removed`, `ctx`, `ctxMax`. No paths, commands, content or session ids. A pane id is accepted only as tmux writes it (`%` and 1 to 6 digits, `\z`-anchored). Writes are a read-modify-write under `flock`, so parallel tool calls lose nothing.
- **Only a pane where an interactive `pikkumies` started is counted.** That start (a terminal session, the same place the credits banner is printed) zeroes its own pane's file and removes other panes' files untouched for 7 days. A hook in any other pane, or outside tmux, writes nothing. The counts mean "since `pikkumies` started in this pane"; `/new` inside Hermes does not reset them.
- **`hooks/trace/session-counters`** (`post_tool_call`, fails open, 2 s): one tool call, plus the `+`/`-` lines of a `patch` diff counted inside its hunks by the hunk header lengths (a file header never counts; a removed line starting with `--` does), or the lines of a `write_file`'s content as added. A failed call or a result that is not JSON (cut to size) counts the call only.
- **`hooks/trace/session-context`** (`post_api_request`, fails open, 2 s): `ctx` = `usage.prompt_tokens`, `ctxMax` = `context_length` of the main agent's last request; a delegated agent's request or one without usage changes nothing. `post_api_request` joins `HookUnits::EVENTS`; Hermes ignores what it prints, and the orchestrator prints nothing for it.
- **`pikkumies status-line --pane=<id>`**, an internal subcommand: `Memories: 42 +3 today · Ctx: 48.2k/1M 4% · Tools: 17 · Code: +120 -8`. The pane part only when the pane has counters, `Ctx` only once a request reported usage; numbers through `CreditsFormat::short` (rounded down), ` | ` instead of ` · ` without a UTF-8 locale. Plain text, no colours. Any failure prints nothing and exits 0, as the credits line does. The pane is given as `--pane=<id>`, one word starting with `-`: a bare `%3` after `status-line` would make the words a prompt under [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)'s rule and start a paid session every minute.
- **tmux:** `status-right` is `#(<php> <bin> status-line --pane=#{pane_id}) #(<credits line --tmux>) %H:%M`, `status-right-length` 150 (was 90), on the `pikkumies` session only, never `-g`. `%` and `#` of the paths are doubled as before; `#{pane_id}` is added after the doubling so tmux fills it in. tmux cuts from the left, so on a narrow terminal the new segment goes first and the credits line and the clock stay.
- **Plain sessions:** the start prints `Memories: N +M today` once, after the credits banner.

## Consequences
- The `pikkumies` tmux session shows, for the pane in front, what the agent there has done, and the memories count for the account.
- Cost, measured on one host with PHP 8.4: about 25 ms more per tool call (one more unit process, paid outside tmux too, where it exits at once), a new orchestrator run and unit per model request (tens of ms against requests of seconds), and one launcher run of about 25 ms per minute for the status line. No network and no model call. Not measured on PHP 7.3 hosts.
- Tool calls and lines include delegated agents; the context is the main agent's only. Lines written by shell commands (`sed -i`, `cat >`) are not counted, and a `write_file` that replaced a file counts all its lines as added.
- `TMUX_PANE` reaching the hooks through Hermes is read in Hermes' source; the first real `pikkumies tmux` session confirms it. If a later Hermes scrubs it, the hooks write nothing and the counters stay at zero.
- The counters are display state on this machine, zeroed at every start and never sent anywhere, so they do not follow `privacy.perfLogging` (which keeps search words and save history, [ADR-0041](0041-memory-metrics-save-reminder-and-wide-search.md)).
- A local hook unit may now use `post_api_request` too ([ADR-0054](0054-tool-hooks-and-local-hooks.md)).
- Tests: `tests/SessionCountersTest.php` (pane ids, reset before counting, adds and sets, 40 parallel writers, permissions, prune, the rendered line), `tests/SessionCounterHooksTest.php` (diff lines by hand, payloads, `extra` fields, subagent requests, both units through the orchestrator with and without `TMUX_PANE`), `tests/StatusLineTest.php` (the tmux plan string, routing of `status-line`, bin's output in a throwaway tree, the reset before Hermes starts), `tests/CoreTest.php` (the generated hook entries).

## References
- ADR-0040 (credits line and the tmux status line), ADR-0030 (hook units), ADR-0044 (post_tool_call observer), ADR-0022 (routing), ADR-0033 (vanilla Hermes)
- Hermes v0.22.2: `agent/shell_hooks.py`, `agent/turn_response_intake.py`, `agent/api_request_hooks.py`, `tools/delegate_tool.py`, `tools/environments/local.py`, `tools/file_operations_common.py`
