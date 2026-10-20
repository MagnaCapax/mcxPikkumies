---
name: scratchpad-session
event: pre_llm_call
description: Records the live session id so the scratchpad tool (which carries none of its own) knows which checkpoint file to use.
failClosed: false
timeout: 2
---
Writes `local/state/scratchpad/current` (dir 0700, file 0600, atomic): the live session id, the one it replaces, and both their times, so the scratchpad tool can tell two sessions apart ([ADR-0090](../../../../docs/adr/0090-per-session-scratchpad.md)). Prints nothing.
