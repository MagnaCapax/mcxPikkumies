---
name: guard-destructive
event: pre_tool_call
description: Blocks obviously destructive terminal commands and sends data deletes, exposure, container-daemon stops and load tests the user may want to Hermes' human-approval prompt.
failClosed: true
timeout: 10
---
Reads `tool_name` and `tool_input` and looks only at `terminal` commands and `execute_code` code. Fails closed: if it crashes, times out or prints something unreadable, the call is blocked (ADR-0020, ADR-0030).

Each question ends by naming its kind: a delete, exposure beyond this account, stopping every container, or a load test on the shared server (`rule_key` `pikkumies-load:`, else `pikkumies-delete:`). Reads of other accounts' resource files and connections are blocked with one generic message. A safety prompt, not security (AGENTS.md): a path or command name built in a variable or in code is out of its sight.
