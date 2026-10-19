# ADR-0048: A fileops check: bulk permission, owner and rename commands are re-checked, and their targets listed first

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Adds the hook unit `guard/fileops-check` ([ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md)) next to the delete guard ([ADR-0020](0020-deletes-need-the-users-approval.md)), which is unchanged.

## Context
The operator's request of 2026-10-18, verbatim: "Do we have for all shell command safety check which flags to the agent for double confirm whenever potentially desctrutive commands detected? if not ... quite light weight and modularized gate. Before fileops we should store a gzipped useraccount file list with permissions, extended attributes etc. just in case we fsck something up, so we can double check. for example permission changes, renames etc. but lightweight, just a quick text list."

What was found:
- `guard-destructive` (fail closed) blocks or asks the user about some 65 patterns, but nothing asks the agent itself to look again, and a plain `chmod -R 700`, `chown -R`, `chgrp -R` or an `mv` over an existing file passes silently: `tests/HookGuardEdgeTest.php` expects `chmod -R 700 ~/private` to get no answer.
- A `pre_tool_call` block's message reaches the agent as the tool's result (`model_tools.py` line 787, Hermes v2026.9.24), so a block is a way to make the agent re-check; an `approve` asks the user through Hermes' approval gate, remembered by `rule_key` (ADR-0020).
- The payload carries `session_id` at the top level and `tool_call_id` under `extra` (`agent/shell_hooks.py` `_payload_fields`, `model_tools.py` `_CallIds`). The tool call id is chosen by the model and not cleaned (`agent/message_sanitization.py` lines 522-525), so it can hold `../` and must not name a file as it is.
- The `terminal` tool takes a per-command `workdir` (`tools/terminal_tool.py`); the hook payload's `cwd` is Hermes' own directory, not the shell's.
- A PMSS host has `lsattr` but no `getfattr` or `getfacl`, and PHP has no extended-attribute reader.

## Options Considered
- **More rules inside guard-destructive.** One file, but it fails closed: a slow directory walk that times out would block the command, and a list writer does not belong in the delete guard. Rejected.
- **A second module system for "gates".** The hook units already are the module system (ADR-0030): one directory per hook, its own event, timeout and failure mode. Rejected.
- **A new fail-open hook unit with a block-once tier, an ask tier and a file list (chosen).**

## Decision
- New unit `hooks/guard/fileops-check/` (`pre_tool_call`, fail open, 5 s), with `lib/FileopsClassify.php` (reads the command into shell words without running it) and `lib/FileopsList.php` (glob expansion, walk, list, retention). It reads `terminal` commands and `execute_code` code only.
- **Block once** (fixed message): recursive `chmod`/`chown`/`chgrp`, the same run by `xargs`, `find -exec`/`-execdir` of `chmod`/`chown`/`chgrp`/`mv`, `chattr`, `rename`, `setfacl`. The message gives the entry count (or "over 50000"), which of the home itself, `~/.ssh` and the pikkumies directory the targets are or hold, and the list file, and asks the agent to tell the user in one line what the command changes, or change it. The identical command in the same session within 10 minutes passes: marker `local/state/fileops/<sha1(session_id NUL command)>`, by mtime. A session id must match `^[A-Za-z0-9._-]{1,128}$`; without one, or when the marker cannot be written, the user is asked instead.
- **Ask the user** (`approve`, `rule_key` `pikkumies-fileops:` + the first 16 hex of sha1 of the command, as guard-destructive derives its keys): `mv` onto an existing non-directory under the home, or several sources landing on one name; a recursive permission, owner, ACL or attribute change whose target is or holds the home, `~/.ssh` or the pikkumies directory; a walk that hits the entry cap. This tier never reads the marker. A command over 4000 characters is blocked instead, as in guard-destructive.
- **Silent:** plain `chmod`/`chown`/`chgrp` of files, `mv` to a new name or into a directory without a clash, `mv -n`, everything else.
- **The list**, written before any answer, also before a pass: `local/logs/fileops/<sha1(tool_call_id)>.jsonl.gz` (dir 0700, file 0600, temporary name + rename). First line `{"ts","session_id","tool_call_id","command","targets","truncated"}`, then one line per entry `{"p","t","m","u","g","s","mt"}` from `lstat` (symlinks never followed), plus `"a"` with the `lsattr -d` flags (one `lsattr` run, 2 s) when the command is `chattr` or there are at most 200 entries. Relative targets resolve against `tool_input.workdir`; with none, nothing is listed and the message asks for absolute paths. Globs are expanded per path component with `opendir` and `fnmatch`, never `glob()`. Only paths under `realpath($HOME)` are listed. The walk and the glob share one 2 second deadline and one 50000-entry counter. A command with a pipe, `;`, `&&`, a variable, a substitution or `xargs`, and any `execute_code` code, is classified but its targets are recorded as `not captured`.
- **Retention:** writing a list removes lists older than `sessions.retentionDays` (0 = keep), then the oldest until the directory holds at most 50 MiB.
- `fileopsCheck.disabled` in `local/config.php` turns the unit off. The orchestrator's `pre_tool_call` timeout becomes 27 s (10 + 10 + 5 + 2, ADR-0030).
- `guard-destructive/hook.md` now says what that unit actually reads: terminal, execute_code, process_manage write/submit, the file tools for the stored-password and AI-key rules, and scheduled script jobs.

## Consequences
- The agent gets one forced pause before a bulk permission, owner, attribute or rename change, and a plain-text record of what the targets looked like, readable with `zcat` and `jq`. The user is asked only where a mistake is hard to see or undo.
- Advisory only: the block-once tier is passed by repeating the command, and the agent's account can write the marker and the lists. A safety prompt, not a security boundary (AGENTS.md).
- Extended attributes are limited to `lsattr` flags; ACLs and user xattrs are not recorded (no `getfattr` on PMSS). Paths that are not valid UTF-8 are stored with replacement characters.
- Units run in name order, so `fileops-check` answers before `guard-destructive`. A block still beats any approve (ADR-0030), so every guard-destructive block holds; when both ask, the user sees this unit's question, which carries the whole command. An `mv` chained with other commands is not checked for an existing target, because a `cd` or variable makes its paths unreliable.
- One more PHP process per tool call (it returns at once for other tools); a matched command costs a walk of at most 2 s plus `lsattr` at most 2 s, inside the 5 s timeout. A timeout lets the command run.
- Tests: `tests/FileopsCheckTest.php` (block once and the 10-minute pass, the ask tier ignoring a marker, silent cases, the list name from a hostile tool call id, lstat and the home boundary, the walk cap, the glob cap, unwritable marker, disabled, relative paths), mutation checked.

## References
- ADR-0020 (delete guard, approve and `rule_key`), ADR-0030 (hook units, orchestrator, merge order), [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) (`sessions.retentionDays`), [ADR-0044](0044-every-shell-command-is-logged.md) (command log)
- Hermes v2026.9.24: `model_tools.py`, `agent/shell_hooks.py`, `agent/message_sanitization.py`, `tools/terminal_tool.py`
