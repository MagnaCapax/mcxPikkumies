---
name: usage-count
event: post_tool_call
description: Counts tool, skill and Pikkumies-unit-verb invocations into the local telemetry store, so "how many times was X used" can be answered without Hermes' insights database.
failClosed: false
timeout: 2
---
One line per tool call to `usage-tool` (field = the Hermes tool name), plus `usage-skill` for a `skill_view` call and `usage-u-<tool>` for a `terminal` call that runs one of this install's own tool units, each 1 (succeeded) or 0 (failed) ([ADR-0087](../../../../docs/adr/0087-tool-skill-and-verb-usage-counts-kept-on-this-machine.md)).
