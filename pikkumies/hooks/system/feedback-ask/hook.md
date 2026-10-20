---
name: feedback-ask
event: pre_llm_call
description: Only when the person turned on feedback.ask, and now and then, tells the agent to end its answer with one line asking whether they want to send Pulsed Media feedback.
failClosed: false
timeout: 2
---
Off unless `feedback.ask` is on (default off). Asks only in a person's own session (Hermes platform `cli` or `tui`, `PIKKUMIES_DEPTH` 1 or less; `HERMES_YOLO_MODE`, set by `--yolo`, the default run mode, makes no difference) in a run (`PIKKUMIES_RUN`, set by an interactive `pikkumies`) that is at least 45 minutes old and has used at least 500,000 tokens, never twice in a run, in about 1 run in 5 (the run id's sha256), and only when the shared budget is open (the mtime of `local/state/feedback-ask/next`, set 14 days out under a lock before the sentence is printed; no budget claimed, no sentence; the 2026-10-19 marker `asked` still holds for 14 days). The exit rating shares the budget (`FeedbackSurvey`, [ADR-0097](../../../../docs/adr/0097-session-rating-at-exit.md)). No network, no model call, nothing sent: the question routes to `/feedback`, whose send runs only when the person's own request covers it (the orchestrator's ask policy, [ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md), [ADR-0101](../../../../docs/adr/0101-refuse-and-report-never-ask.md)).
