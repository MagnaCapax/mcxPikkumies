---
name: mutation-check
event: transcript
every: 60
wake: change
description: At most once a minute, in the background, reads the session's last tool calls; when some changed data, holds the next data-changing call once so the agent checks it is what the user asked for, that it searched first, and that the change can be undone.
failClosed: false
timeout: 10
---
An interval unit ([ADR-0067](../../../../docs/adr/0067-interval-hook-units.md)): started after a tool call that changed data, at most once per interval per session. Reads only the tool records since its last run (`change` from `lib/ChangeClassify.php`). With at least one definite change it leaves a notice with fixed text, two of the calls (one line each, labelled untrusted) and a count; the orchestrator holds the next call that may change data with it, once. No model call. Log: `local/logs/mutation-check/`. Interval `hooks.every.mutation-check`, holds per turn `hooks.mutationCheck.holdsPerTurn` ([ADR-0070](../../../../docs/adr/0070-mutation-check.md)).
