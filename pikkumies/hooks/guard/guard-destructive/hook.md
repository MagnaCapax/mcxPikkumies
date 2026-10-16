---
name: guard-destructive
event: pre_tool_call
description: Blocks obviously destructive terminal commands and sends data deletes the user may want to Hermes' human-approval prompt.
failClosed: true
timeout: 10
---
Reads `tool_name` and `tool_input` and looks only at `terminal` commands and `execute_code` code. Fails closed: if it crashes, times out or prints something unreadable, the call is blocked (ADR-0020, ADR-0030).
