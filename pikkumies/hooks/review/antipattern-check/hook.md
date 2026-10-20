---
name: antipattern-check
event: transcript
every: 300
wake: reply, tool
description: Every few minutes, asks the light model whether the agent's recent replies show a listed common mistake; adds the matching fixed lines to the next turn.
failClosed: false
timeout: 30
---
An interval unit ([ADR-0067](../../../../docs/adr/0067-interval-hook-units.md), [ADR-0088](../../../../docs/adr/0088-common-mistakes-list-and-check.md)): reads the session's reply texts and tool calls since its last run, asks the light model whether they clearly show one of the entries in [doctrine/antipatterns.md](../../../doctrine/antipatterns.md), and keeps only an answer whose exact words are found in that same text. What reaches the agent is always the entry's own fixed text from the file, never the model's words. Shares its stamp, interval and shown-ids state with the end-of-turn checks in `lib/HaltRun.php` and `hooks/halt/halt-guard/halt-guard.php` (`lib/Antipatterns.php`), so only one caller runs it per interval. Log: `local/logs/antipatterns/`. Interval `hooks.every.antipattern-check`; both callers are also turned off together by `antipatterns.disabled`.
