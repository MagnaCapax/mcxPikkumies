---
name: command-log
event: post_tool_call
description: Logs every shell command the agent ran, with its exit code, to local/logs/commands/ (one file per UTC day), so a session can be traced later.
failClosed: false
timeout: 5
---
One JSON line per `terminal` call after it ran: time, session, tool call id, directory, the command with secrets removed, exit code, status, duration and output size, never the output itself (ADR-0044).
