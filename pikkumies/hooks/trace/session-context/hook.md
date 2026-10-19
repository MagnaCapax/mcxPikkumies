---
name: session-context
event: post_api_request
description: Keeps the context the last model request used, and the model's window, for each tmux pane where pikkumies runs, for the pikkumies tmux status line.
failClosed: false
timeout: 2
---
Sets `ctx` (the request's `usage.prompt_tokens`) and `ctxMax` (`context_length`) in `local/state/session-counters/pane<N>.json` for the pane in `TMUX_PANE` (`lib/SessionCounters.php`). A delegated agent's request (`platform` "subagent") and a request without usage leave them alone. Outside tmux, or in a pane where no interactive `pikkumies` started, it writes nothing. Prints nothing ([ADR-0061](../../../../docs/adr/0061-status-line-memories-context-tool-calls-code-lines.md)).
