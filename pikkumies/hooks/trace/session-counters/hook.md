---
name: session-counters
event: post_tool_call
description: Counts the tool calls and the lines of code added and removed in each tmux pane where pikkumies runs, for the pikkumies tmux status line.
failClosed: false
timeout: 2
---
Adds one tool call, and the `+`/`-` lines of a `patch` diff or the lines a `write_file` wrote, to `local/state/session-counters/pane<N>.json` for the pane in `TMUX_PANE` (`lib/SessionCounters.php`). Integers only. Outside tmux, or in a pane where no interactive `pikkumies` started, it writes nothing. Prints nothing ([ADR-0061](../../../../docs/adr/0061-status-line-memories-context-tool-calls-code-lines.md)).
