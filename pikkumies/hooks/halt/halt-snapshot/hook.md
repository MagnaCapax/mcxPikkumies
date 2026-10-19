---
name: halt-snapshot
event: pre_llm_call
description: Writes the latest user message and a cut-to-size transcript tail per session, so halt-guard can judge the turn once it ends.
failClosed: false
timeout: 5
---
Writes `local/state/halt/<sha1 of session_id>.json` (dir 0700, file 0600, atomic): `user_message`, `tail` (last 25% of the conversation, at least 12 000 characters when available, at most 40 000, cut at message boundaries), `turn_started`, and fresh `continues`/`wrapup_sent`/`last_judged_turn` for the new turn. Prints nothing. Off with `halt.disabled` ([ADR-0047](../../../../docs/adr/0047-halt-guard.md)).
