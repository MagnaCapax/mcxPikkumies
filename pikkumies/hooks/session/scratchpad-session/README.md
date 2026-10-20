# scratchpad-session

Runs on `pre_llm_call`, every turn: writes the live session id to `local/state/scratchpad/current` (dir `0700`, file `0600`, atomic), together with the last *different* session id it replaced and both writes' own times.

Tools carry no session id of their own; only hooks get one (`lib/HaltRun.php`, `lib/HookFeed.php`, `hooks/orchestrator.php`). This is the one place a session id crosses that gap: `pikkumies/tools/session/scratchpad/` reads `current` back when the agent did not pass `--session`, and refuses with a hint instead of guessing when two different sessions wrote it within the last 10 minutes — two chats open on the same account at once ([ADR-0090](../../../../docs/adr/0090-per-session-scratchpad.md)).

Fails open: a write it cannot make simply means `--session <id>` must be passed explicitly to the tool next time, not that anything is lost.
