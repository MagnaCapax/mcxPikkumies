# session-context

Runs after each model request (`post_api_request`) and keeps, for the tmux pane the agent runs in, the context that request used (`usage.prompt_tokens`) and the model's window (`context_length`). The `pikkumies` tmux session shows them on its status line as `Ctx: 48.2k/1M 4%` ([ADR-0061](../../../../docs/adr/0061-status-line-memories-context-tool-calls-code-lines.md)).

- **Where:** the same file as [session-counters](../session-counters/README.md), `local/state/session-counters/pane<N>.json`.
- **Which requests:** the main agent's only. A delegated agent's request (`platform` "subagent") and a request whose provider reported no usage leave the figure as it was.
- **When nothing is written:** outside tmux, and in a pane where no interactive `pikkumies` started.

Hermes ignores what a `post_api_request` hook prints; this one prints nothing and fails open.
