---
name: goal-review
event: transcript
every: 600
wake: reply
description: At most every 10 minutes, in the background, asks the light model ten fixed questions about the session's recent requests and answers; adds the matching fixed reminders, offered tools and pages, and the quoted requests to the next turn.
failClosed: false
timeout: 100
---
An interval unit ([ADR-0067](../../../../docs/adr/0067-interval-hook-units.md)) woken by a finished answer. Skips the first turn (keeping it for the next run), chat, Pikkumies' own resume texts, scheduled sessions and `hooks.llm.disabled`. One light-model call per run (`hooks.goalReview.rounds` 10: ten), counted in a daily ledger (`hooks.dailyCalls.goal-review`, default 24). The model only picks fixed answers; what reaches the agent is fixed text, at most 4000 characters, plus requests found word for word in the conversation. Interval `hooks.every.goal-review` ([ADR-0071](../../../../docs/adr/0071-goal-review.md)).
