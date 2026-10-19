---
tags: task, settings
contains_any: -m | --model
not_calls: pikkumies-configure.*set
judge: Describes the override as a per-run or per-session choice (the -m launcher flag, or run chat -m), not a permanent setting change.
source: docs/adr/0052-launcher-model-flag-and-configure.md (per-run -m/--model override)
---
Use the strongest/most expensive model you've got for this one task, cost doesn't matter today.
