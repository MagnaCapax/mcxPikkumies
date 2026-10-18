---
name: low-balance
event: pre_llm_call
description: Once per session, when the credits line's cache shows credits are low, tells the agent to prefer the cheapest steps and to tell the user once.
failClosed: false
timeout: 2
---
Reads `local/state/credits/line.json` only when it is under 10 minutes old and for this endpoint, key and model; low is `CreditsFormat::isLow` (under 10% of the newest grant, or under 200k tokens at the highest rate). No network, no model call. Marker per session under `local/state/low-balance/`. Off with `lowBalance.disabled` (ADR-0050).
