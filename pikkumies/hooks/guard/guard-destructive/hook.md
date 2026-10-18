---
name: guard-destructive
event: pre_tool_call
description: Blocks obviously destructive shell commands and reads of stored passwords, and sends data deletes, exposure, container-daemon stops, load tests, scheduled scripts and outgoing mail the user may want to Hermes' human-approval prompt.
failClosed: true
timeout: 10
---
Reads `tool_name` and `tool_input`. Checks `terminal` commands, `execute_code` code and the text `process_manage` writes or submits to a background process against every rule; the path and search pattern of the file tools (`read_file`, `write_file`, `patch`, `search_files`), and every file a V4A patch names in its `Update`/`Add`/`Delete File:` and `Move File: a -> b` headers (both sides, read as Hermes reads them, `lib/PatchPaths.php`), only against the stored-password and AI-key rules, which block, as is a regex engine error while reading those headers; and a `cronjob_manage` call that sets a script, a monitor or no-agent mode, which it asks about. Fails closed: if it crashes, times out or prints something unreadable, the call is blocked (ADR-0020, ADR-0030).

Each question ends by naming its kind: a delete, exposure beyond this account, stopping every container, or a load test on the shared server (`rule_key` `pikkumies-load:`, else `pikkumies-delete:`; a scheduled script `pikkumies-schedule:`). Reads of other accounts' resource files and connections are blocked with one generic message. Starting another agent with approvals, hooks or rules off or with another config, and switching approvals off from inside a session (`config set approvals.…`, `/yolo`, `/approvals off|smart`; a `sed` or `printf` edit of `config.yaml` is not caught), is blocked, and in an agent started by an agent (`PIKKUMIES_DEPTH` 2 or more) every question becomes a block, yolo or not (ADR-0020 amendment 2026-10-18). A safety prompt, not security (AGENTS.md): a path or command name built in a variable or in code is out of its sight.
