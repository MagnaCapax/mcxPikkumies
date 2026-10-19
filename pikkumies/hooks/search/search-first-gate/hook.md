---
name: search-first-gate
event: pre_tool_call
description: Blocks acting tool calls until the agent has searched lessons and documentation for the current question.
failClosed: false
timeout: 10
---
Partner of `search-first`. State is one marker file per session under `local/state/search-first/`. Off with `searchFirst.disabled` ([ADR-0011](../../../../docs/adr/0011-documentation-sources-and-search-first.md), [ADR-0028](../../../../docs/adr/0028-search-first-every-turn-and-a-light-model-for-hooks.md)).
