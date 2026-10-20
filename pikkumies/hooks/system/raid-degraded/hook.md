---
name: raid-degraded
event: pre_llm_call
description: When root is available and /proc/mdstat shows a software RAID array degraded, rebuilding or inactive, tells the agent once per session per change to run mdraid status first.
failClosed: false
timeout: 2
---
Reads `/proc/mdstat` only (no program, no network, no model call), and only when `local/host.json` says root is available and `root.disabled` is not set. One marker per session under `local/state/raid-degraded/` holds the last state told; a new note comes only when that state changes. Fails open: an error only means no note ([ADR-0095](../../../../docs/adr/0095-md-raid-build-recover-and-tune-through-one-tool.md)).
