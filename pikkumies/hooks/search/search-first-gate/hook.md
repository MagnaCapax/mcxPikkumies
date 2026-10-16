---
name: search-first-gate
event: pre_tool_call
description: Blocks acting tool calls until the agent has searched lessons and documentation for the current question.
failClosed: false
timeout: 10
---
Partner of `search-first`. State is one marker file per session under `local/state/search-first/`. Off with `searchFirst.disabled` (ADR-0011, ADR-0028).
