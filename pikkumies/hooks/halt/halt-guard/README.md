# halt-guard

Runs on `pre_verify`, the one Hermes hook that can keep a turn going after the model stops with a text answer (`agent/turn_stop_gates.py`, Hermes v2026.9.24). It fires only inside a turn that edited a file through `write_file`/`patch`; a turn with only terminal commands, or a plain chat turn, never reaches it — `lib/HaltRun.php` covers that case for a one-shot run, and nothing covers it for an interactive turn with no file edit (Hermes offers no hook there; `/goal` is the documented way to get a judged loop on a plain chat/investigation turn, see [docs/halt-guard.md](../../../../docs/halt-guard.md)).

- **Evidence:** `halt-snapshot`'s saved `user_message`/`tail` for this session, this turn's lines from the command log (`local/logs/commands/`, ADR-0044, filtered to this `session_id` since `turn_started`) and `changed_paths` from the `pre_verify` payload.
- **Decision:** `HaltJudge::evaluate()` — a light-model yes/no battery, then a deterministic PHP verdict (chat, waiting on the user, continue with a fixed sentence, one wrap-up, or allow). The model's own words never reach the agent; see `lib/HaltJudge.php`.
- **Local bounds:** refuses (prints nothing) once the payload's `attempt` reaches 3, matching Hermes' own `agent.max_verify_nudges` cap, and once a wrap-up has been sent for this turn (`wrapup_sent` in the session state), always allows afterwards.
- **State:** the same per-session file `halt-snapshot` wrote, `local/state/halt/<sha1 of session_id>.json`; this unit stamps `last_judged_turn` on every run (so `lib/HaltRun.php` never re-judges a turn it already looked at), and tracks `continues`/`wrapup_sent`.
- **Log:** `local/logs/halt-guard/YYYYMMDD.jsonl` (0700/0600): time, session, source (`hook`), attempt, verdict and the yes/no answers — never the transcript or the final answer.
- **Session id:** must match `^[A-Za-z0-9._-]{1,128}$`, else this unit does nothing.

Fails open: fails closed is for `pre_tool_call` only (ADR-0030); a halt guard that cannot judge must never trap a session, so any unsure, unparsable, empty or timed-out model answer allows the turn to end. Off with `halt.disabled` in `local/config.php`.
