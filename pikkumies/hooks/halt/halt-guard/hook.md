---
name: halt-guard
event: pre_verify
description: Asks a light model whether a stopped turn actually finished the user's request; continues it with a fixed instruction when not, or asks for a lesson and an idea when it did.
failClosed: false
timeout: 30
---
Reads the snapshot `halt-snapshot` wrote, this turn's command-log lines and changed files as evidence, and the final answer; calls `HaltJudge::evaluate` (light model, 20 s). Continue and wrap-up messages are built from fixed sentences only, never the model's free text. Refuses once Hermes' own attempt reaches 3, and after a wrap-up was sent once this turn, always allows. Off with `halt.disabled` (ADR-0047).
