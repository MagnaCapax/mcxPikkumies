---
name: save-reminder
event: pre_llm_call
description: After a memory search that found no lesson, reminds the agent once to save what it learned, with the exact save command.
failClosed: false
timeout: 5
---
Fires once per search, on a later turn, when the newest search had no tier-1 hit, nothing in memory changed after it, and it is under 2 hours old. No model call, no network (ADR-0041).
