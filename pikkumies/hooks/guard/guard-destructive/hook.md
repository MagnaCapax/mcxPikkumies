---
name: guard-destructive
event: pre_tool_call
description: Blocks obviously destructive shell commands and reads of stored passwords, and sends data deletes, exposure, container-daemon stops, load tests, scheduled scripts and outgoing mail the user may want to Hermes' human-approval prompt.
failClosed: true
timeout: 10
---
Reads `tool_name` and `tool_input`. Checks `terminal` commands, `execute_code` code and the text `process_manage` writes or submits to a background process against every rule; the path and search pattern of the file tools (`read_file`, `write_file`, `patch`, `search_files`) only against the stored-password and AI-key rules, which block; and a `cronjob_manage` call that sets a script, a monitor or no-agent mode, which it asks about. Fails closed: if it crashes, times out or prints something unreadable, the call is blocked (ADR-0020, ADR-0030).

Each question ends by naming its kind: a delete, exposure beyond this account, stopping every container, or a load test on the shared server (`rule_key` `pikkumies-load:`, else `pikkumies-delete:`; a scheduled script `pikkumies-schedule:`). Reads of other accounts' resource files and connections are blocked with one generic message. A safety prompt, not security (AGENTS.md): a path or command name built in a variable or in code is out of its sight.
