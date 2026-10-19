# ADR-0081: Tool-linked memories and an agent-facing soft confirm

## Metadata
- Author: Aleksi Ursin / MCX
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0018](0018-memory-tool-unit.md) (save takes `--tool`, a memory's frontmatter may carry `tools`). Adds the hook unit `search/memory-check` ([ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md)). Applies [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md): nothing here asks the user.

## Context
The operator's requests of 2026-10-18, in short: a memory carries a link to a specific file, written when it is saved; when a tool uses that file, the linked memories (file name and a short synopsis) come back for a soft confirm. And: outbound and state-changing tools get that soft confirm on by default; the agent justifies the call, and the confirm returns the linked memories and asks the agent to search memory. Under ADR-0073 (2026-10-19) the user is not asked to confirm work, so the soft confirm is for the agent only.

What was found:
- A memory file's frontmatter has `date`, `topic` and `sources` (`Lessons::save`); memory files are only appended to (ADR-0018), so frontmatter is written once.
- Every tool unit's USAGE.md verbs table has a `changes` cell per verb (`Units::verbs`). Across the shipped units, by the cell's first word: `no` 155 (2 of them `no (contacts the trackers)`), `yes` 96, `deletes` 15, `sends` 4, `loads` 1. It is the one place that says which verbs change, send, delete or load.
- A `pre_tool_call` block's message reaches the agent as the tool's result (ADR-0048); fileops-check already blocks once and lets the identical command through on the next run, with a marker per session.
- The orchestrator runs units in name order and the first block wins (`HookAnswers::mergePreToolCall`).
- search-first-gate touches its search marker in its own process before the answers are merged, so a plain memory search held by another unit would count as searched without having run.
- Hermes' manage tools take an `action`: `cronjob_manage` create, list, update, pause, resume, remove, run; `process_manage` list, poll, log, wait, kill, write, submit, close, handoff (Hermes v0.22.2, `tools/cronjob_tools.py`, `tools/process_registry.py`).

## Options Considered
- **One tool hook per unit (`hooks/tools/<unit>/memory-check/`).** Forty copies, and no place for a link to a file that is not a unit. Rejected.
- **A link index written by save.** ADR-0060 rejected a stored index for the link graph for the same reasons (staleness, a lock); the memory frontmatter is small to read. Rejected.
- **A post_tool_call note only.** The note arrives on the next turn, after the call ran, and holds nothing. Rejected.
- **Linked memories in search-first-gate's block.** That gate opens after one search per question, so it cannot hold a call once per target. Rejected.
- **Make the re-run carry a justification flag.** Every tool unit's arguments would change. Rejected: the agent says why in its reply, and the identical re-run proceeds.
- **Pikkumies verbs only.** Leaves out Hermes tools that change files, schedules and skills, which the request covers. Rejected.
- **One hook unit that reads the memory frontmatter on each call and holds once per (session, tool, target) (chosen).**

## Decision
- **The link.** `save --tool "<file; file>"` resolves each path, refuses one that is not an existing file, and writes `tools: [<real paths>]` into the new memory's frontmatter after `sources`. Without `--tool` nothing changes. An older memory gets a link through a new short memory that links to it and carries `--tool`.
- **The hook** `hooks/search/memory-check/` (`pre_tool_call`, fail open, 3 s) finds the targets of a call (`MemoryCheckTargets.php`):
  - a file a memory links to, when the call names its file name as a word in the texts `HookPayload::read` gives (command, code, path), for any tool, reading included;
  - a Pikkumies tool unit verb run as `<unit>.php [options] <verb>` whose `changes` cell does not start with `no`; `X=<path>.php` in the same text is read into `$X`;
  - a Hermes tool, keyed by its `action` and the first of `path`, `job_id`, `name`, `session_id` it names (a V4A `patch` by the files in its headers, `PatchPaths::v4a`). Not held as such: tools that read, plan or show (`read_file`, `search_files`, `skill_view`, `skills_list`, `session_search`, `read_terminal`, `read_window_below`, `todo`, `todo_list`, `clarify`, `vision_analyze`, `video_analyze`, `show_tip`), an `action` that only reads (`list`, `poll`, `log`, `wait`, `view`, `read`, `get`, `status`, `show`, `search`), `terminal` and `execute_code` (they name no target of their own; their Pikkumies verbs and linked files are the targets), and web research (`web_search`, `web_extract`, `x_search`), which search-first-gate holds until tiers 1-3 were searched ([ADR-0031](0031-search-tiers.md)) and which ADR-0073 counts as the work itself.
- **Held once per session, tool and target.** The first call with a new target is blocked; the message names the new targets (at most 5), lists the memories linked to them (at most 5, path and title, then "and N more"; or "No memory is linked to it"), and asks the agent to search memory if it has not, read what applies, say in one line why the call is right and run it again. Each new target gets a marker, `local/state/memory-check/<sha1 of session id NUL tool NUL target>` (directory 0700, removed after 7 days), so running the call again proceeds.
- **Never held:** a call naming the memory unit (`pikkumies.php`), so searching and saving are never delayed and search-first-gate never counts a search that did not run; a call without a session id matching `^[A-Za-z0-9._-]{1,128}$`; a call whose markers cannot be written (holding it would hold every retry). The user is never asked.
- `memoryCheck.disabled` in `local/config.php` turns it off; like the other safety layers, the configure tool turns it on freely and leaves turning it off to the person ([ADR-0059](0059-settings-tiers-free-asked-and-the-persons-own.md)).

## Consequences
- The agent sees what its own memory says about a file or a tool before it first uses it in a session, and every first change, send or delete through a tool unit, and every first write to a file, schedule or skill, costs one extra call in which the agent says why.
- Friction: one extra call per new target per session. A task that writes ten files pays ten. Terminal commands that are not Pikkumies verbs are not held.
- It is a habit prompt: the agent's account can write the markers and edit the memory files (AGENTS.md: hooks are safety prompts, not security).
- Matching a linked file by its name over-matches: two files named `config.php` hold both, once each, and the message shows the linked path. A path built in code, by command substitution or behind a variable other than `X=<path>.php` in the same text is out of sight.
- memory-check runs after the guard units and before search-first-gate (name order). When a guard blocks the same first call, the agent sees the guard's block and this hold is spent for that target. On the first acting call of a new question the agent may meet this hold and then search-first-gate's; the memory search the first asks for opens the second.
- The `pre_tool_call` entry's timeout grows by 3 seconds, so `config.yaml` changes once at the next start.
- Every call reads the head (8 KiB) of each memory file; the units' USAGE.md files are read only when the call names a `.php` file.
- Tests: `tests/MemoryCheckTest.php` (link written and refused, holds per target kind, the re-run, reading actions and quiet tools, the memory unit, no session, unwritable markers, disabled, the caps, `X=` variables, the script through its own entry).

## References
- ADR-0018, ADR-0030, ADR-0031, [ADR-0048](0048-fileops-check-recheck-and-file-list.md) (block once and a marker), [ADR-0054](0054-tool-hooks-and-local-hooks.md) (what a call says, `HookPayload`), ADR-0059, [ADR-0060](0060-links-and-the-link-graph.md) (no index), ADR-0073
- Code: `pikkumies/hooks/search/memory-check/`, `pikkumies/tools/memory/pikkumies/Lessons.php`, `pikkumies/lib/Config.php`, `pikkumies/lib/ConfigureTiers.php`
- Hermes Agent v0.22.2 (d923a5eb): `tools/cronjob_tools.py`, `tools/process_registry.py`, `tools/file_tools.py`
