---
name: halt-snapshot
event: pre_llm_call
description: Writes the latest user message and a cut-to-size transcript tail per session, so halt-guard can judge the turn once it ends.
failClosed: false
timeout: 5
---
Writes `local/state/halt/<sha1 of session_id>.json` (dir 0700, file 0600, atomic): `user_message`, `tail` (last 25% of the conversation, at least 12 000 characters when available, at most 40 000, cut at message boundaries), `asked` (the user's last messages and the agent reply they answered, as separate entries, in a person's own session; in a gateway turn only from a sender on the gateway's explicit allowlist with nobody else let in, and then only the current message and the reply it answers (`lib/GatewayAccess.php`); in a scheduled run the words recorded when the job was made (`lib/CronAuth.php`); never the halt guard's own continue or wrap-up text; the ask review's evidence, [ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md), [ADR-0101](../../../../docs/adr/0101-refuse-and-report-never-ask.md)), `turn_started`, and fresh `continues`/`wrapup_sent`/`last_judged_turn` for the new turn. Prints nothing. Off with `halt.disabled` ([ADR-0047](../../../../docs/adr/0047-halt-guard.md)).
