# halt-snapshot

Runs on `pre_llm_call`, once per turn, before `halt-guard` (`pre_verify`) can run at the end of it ([ADR-0047](../../../../docs/adr/0047-halt-guard.md)): captures what the turn started with, so the guard has something to judge against once the turn is over.

- **Where:** `local/state/halt/<sha1 of session_id>.json`, one file per session, directory `0700`, file `0600`, written through a temporary name and a rename.
- **What it holds:** `user_message` (the latest user text), `tail` (the conversation so far, cut to the last 25% by characters — at least 12 000 when that much exists, at most 40 000, never splitting a message in the middle), `turn_started` (when this turn began) and reset counters for the new turn (`continues`, `wrapup_sent`, `last_judged_turn`).
- **Who reads it:** `hooks/halt/halt-guard/` (`pre_verify`, inside the same Hermes process when the turn edited a file) and `lib/HaltRun.php` (the one-shot wrapper, after Hermes has exited, for a turn that never edited a file and so never triggered `pre_verify` at all).
- **Multimodal messages:** a message that is a list of content parts has its text parts joined; anything else is skipped.

Off with `halt.disabled` in `local/config.php`. Fails open: a write it cannot make simply means the guard has nothing to judge, so the turn ends normally (ADR-0047 is defense-in-depth, never a gate that can wedge a session).
