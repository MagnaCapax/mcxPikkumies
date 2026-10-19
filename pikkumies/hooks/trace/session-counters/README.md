# session-counters

Runs after each tool call (`post_tool_call`) and counts, for the tmux pane the agent runs in, the tool calls and the lines of code added and removed since `pikkumies` started in that pane. The `pikkumies` tmux session shows them on its status line ([ADR-0061](../../../../docs/adr/0061-status-line-memories-context-tool-calls-code-lines.md)).

- **Where:** `local/state/session-counters/pane<N>.json`, `N` from `TMUX_PANE`; the directory is 0700 and the files 0600. Integers only: no paths, commands, content or session ids.
- **What counts:** every tool call, delegated agents' included. Lines: the `+` and `-` lines of a `patch` result's diff, counted inside its hunks, and every line a `write_file` wrote (as added; what it replaced is not known). A failed call, or a result too long to be read as JSON, counts as a call with no lines.
- **When nothing is written:** outside tmux, and in a pane where no interactive `pikkumies` started (that start zeroes the pane's file, and drops other panes' files untouched for a week).

It fails open: a file that cannot be written never stops a tool call.
