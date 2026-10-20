---
name: goal-review
event: transcript
every: 120
wake: reply
description: Checks in the background that the agent still works on what the user asked: first after 5 minutes and 75k tokens, then at most every 30 minutes after 600k more; asks the cheapest light model for 0-1000 scores and leaves fixed reminders for the next turn.
failClosed: false
timeout: 100
---
An interval unit ([ADR-0067](../../../../docs/adr/0067-interval-hook-units.md)) polled after a finished answer: it answers skip while not due, so the next answer looks again; after a run the next look waits `hooks.every.goal-review` seconds (120; 0 turns it off). It reviews only when the run is due by time and tokens: first after `hooks.goalReview.firstAfter` seconds (300) and `firstTokens` tokens (75000), then after `thenEvery` seconds (1800) and `thenTokens` more tokens (600000); a call that gave no usable answer waits 600, 1200, then 1800 seconds, and after three it waits for the next regular review; two calls of the account are at least 600 seconds apart. One call per review, at most 30 000 estimated tokens in, counted in a daily ledger (`hooks.dailyCalls.goal-review`, default 24). The model only gives fixed names, numbers and offered ids; what reaches the agent is fixed text with the numbers, at most 4000 characters, plus requests found word for word in the conversation. Never a hold or a block ([ADR-0096](../../../../docs/adr/0096-goal-review-v2-drift-check-by-time-and-tokens.md), [ADR-0071](../../../../docs/adr/0071-goal-review.md)).
