---
name: feedback-ask
event: pre_llm_call
description: Only when the person turned on feedback.ask, and now and then, tells the agent to end its answer with one line asking whether they want to send Pulsed Media feedback.
failClosed: false
timeout: 2
---
Off unless `feedback.ask` is on (default off). Asks only in a person's own session (Hermes platform `cli` or `tui`, `PIKKUMIES_DEPTH` 1 or less, `HERMES_YOLO_MODE` not set) with at least 4 user messages, in about 1 session in 5 (the session id's sha256), and at most once in 7 days (the mtime of `local/state/feedback-ask/asked`, written before the sentence is printed; no marker written, no sentence). No network, no model call, nothing sent: the question routes to `/feedback`, whose send still needs the person's answer in Hermes' approval prompt ([ADR-0056](../../../../docs/adr/0056-feedback-what-pikkumies-sends-to-pulsed-media.md)).
