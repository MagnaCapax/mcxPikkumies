# ADR-0097: A session rating at exit, opt-in, sent only on a keypress

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Proposed. Amends [ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md) (its first amendment's rejection of an exit prompt, for a rating only; the `feedback.ask` wording; the cooldowns) and AGENTS.md's privacy line. Reads the run counter of `lib/SessionCounters.php` ([ADR-0061](0061-status-line-memories-context-tool-calls-code-lines.md)'s file, extended with a run key).

## Context
The operator's request of 2026-10-20, verbatim: "We should sometimes show the user request to submit feedback to us so we can better learn, imitate the feedback how well it is doing thing from claude code, not very frequently and mionimum session duration ahs to be i think 45mins and 500k tokens (total cross compacts etc.) perhaps?". The privacy rule stays the operator's: "honor our privacy policy and privacy first, fully opt in only".

Facts this rests on:
- `hooks/system/feedback-ask` (ADR-0056 amendment 2026-10-19) already asks now and then, opt-in, but invites the transcript `/feedback` and has no rating. Its gates were 4 user messages, a 20% draw by session id and 7 days.
- Hermes draws a new session id at every compaction (`agent/conversation_compression.py`, the hand-off to a child session). A per-session gate re-rolls there, and the history a message count reads shrinks.
- Hermes' terminal UI takes no widget from outside without patching it ([ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)). A rating asked mid-session goes through the model: the person's "3" is a model request that re-sends the whole context on their credits, and the model could answer it itself.
- After Hermes exits, `bin/pikkumies` still owns the terminal, and the run's totals are known.

## Options Considered
- **Rate mid-session through the agent.** A full-context turn per answer, and model-answerable. Rejected.
- **Patch Hermes' UI.** Against ADR-0033. Rejected.
- **A new setting `feedback.survey`.** A second toggle for the same consent. Rejected: `feedback.ask` covers both.
- **Gate on time alone, or tokens alone.** Time alone counts a terminal left open; tokens alone fire within minutes on one heavy task. Rejected: both.
- **Keep a random draw for the rating.** With opt-in, the thresholds and a cooldown, a draw adds only unpredictability. Rejected for the rating; the mid-session line keeps its draw so most eligible runs get the cheaper rating.
- **An exit-time line from `bin/pikkumies` behind `feedback.ask`, a run counter keyed by `PIKKUMIES_RUN`, and one ask budget (chosen).**

## Decision
- **Run.** `bin/pikkumies` sets `PIKKUMIES_RUN` (16 hex digits) at an interactive start and zeroes its counter file; the post_api_request hook adds every request's total tokens to it, delegated agents included (the counter is lib/SessionCounters.php's, not this ADR's). It survives compaction because the process and its environment do.
- **Eligible run** (`FeedbackAsk::eligible`): `feedback.ask` on; at least 45 minutes since the start AND at least 500,000 tokens; depth 1 or less (`HERMES_YOLO_MODE` makes no difference: `--yolo` is the default run mode, [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) amendment 2026-10-20); the run's one ask unused; the budget open. The exit line also needs stdin and stdout on a terminal, no launcher flag that switches the safety layers off or changes the config (`NestedAgent::argvFlag`, with `--yolo` left out), a Pulsed Media `vsk-` key and PHP sodium; without them it is never shown.
- **Budget.** The mtime of `local/state/feedback-ask/next`, shared with the mid-session line, claimed under a lock before anything is shown (for 30 days at first, so a killed exit counts as a dismissal). After a number: 14 days. After 0, Enter or 20 seconds of silence: 30 days. `n`: `feedback.ask` off. The mid-session line, when given, also 14 days.
- **The line** (`FeedbackSurvey::run`, called once from `bin/pikkumies` after Hermes returns, in the run case only, and never in an Eternal window, which runs unattended and must not wait on a prompt):
  ```
  How is Pikkumies doing this session?  1 bad  2 fine  3 good  0 dismiss  n never ask
  A number sends Pulsed Media only that number, the session's length and token count (rounded), the model and versions, linked to your Pulsed Media account. No conversation.
  ```
  The answer is a line (Enter after the key; `\r` or `\n` ends it). Before the line is shown, `FeedbackTty::prepare` saves the terminal mode (`stty -g`), drops everything already typed (a stray `1⏎` meant for Hermes must never answer a line nobody read; a half-typed line too, read in non-canonical mode first), and sets canonical mode with echo and signals (`stty sane`), because Hermes can leave the terminal raw after a crash; the saved mode is put back after the read. The read goes byte by byte against one 20-second deadline, so a raw Enter, a half answer or silence never holds exit past it. Without stty the drain and the deadline still hold. After 1: a pointer to `/feedback` next session. After 2 or 3: "Thanks, sent." On failure: "Not sent: <reason>. Nothing is retried."
- **Payload.** `{"v":1,"kind":"rating","rating":1|2|3,"minutes":<rounded to 5>,"tokens_k":<rounded to 100k, in thousands>,"model":"<a name>","versions":{name: at most 64 printable characters}}`. The model is sent as a name, never a path: a value starting with `/` or `~`, holding a backslash, `.`/`..` segments, `/home/`, `/root/` or the user's home is `"other"`; a `family/name` id is cut to its last part; what is left must be 1-128 of `[A-Za-z0-9._:@+-]` or it is `"other"` (`FeedbackSurvey::modelName`). Version strings (`FeedbackSend::versions()`, read partly from user-owned files) are cut to 64 printable ASCII characters each. It goes `FeedbackSend::send` with a 5-second timeout: the same sealed box, key, endpoint and Bearer key as `/feedback`. No `created_at`: the server already keeps the time it received it (ADR-0056), and the line names everything that goes. No session id, conversation, path or usage count.
- **Local copy.** With `privacy.perfLogging` on, `{rating, minutes, tokens_k}` also goes to the local telemetry store as source `feedback-rating` when that store is part of the install ([ADR-0086](0086-local-only-telemetry-history-for-diagnostics-and-health-runs.md)); local only, sent or not.
- **The agent cannot run it.** No verb exposes it, and the feedback-send guard blocks agent text that calls `FeedbackSurvey::` (ADR-0056 amendment 2026-10-20).

## Consequences
- The rating costs no tokens and no model can answer it.
- Ratings come only from long sessions of opted-in users with a Pulsed Media key: a skewed sample. Read them as long-session satisfaction, not overall satisfaction; the payload's rounded minutes and tokens show the skew. The thresholds are named constants (`FeedbackAsk::MIN_MINUTES`, `MIN_TOKENS`), starting values with no interactive-session data behind them.
- Users who never close Pikkumies never see the line; the mid-session line still reaches them.
- Exit can wait up to 20 seconds plus a 5-second send, only for an eligible run.
- The Pulsed Media privacy policy must name rating payloads before the server accepts them (ADR-0056's rule), and Pulsed Media's side that opens sealed payloads must handle `kind: "rating"`. Ratings count against the per-key daily limit.
- Not run live: `PIKKUMIES_RUN` reaching the hooks and the run file summing across a real compaction.

## References
- ADR-0056 (feedback), ADR-0061 (session counters), ADR-0033 (vanilla Hermes), ADR-0086 (local-only telemetry)
- Code: `pikkumies/tools/system/feedback/FeedbackSurvey.php`, `pikkumies/tools/system/feedback/FeedbackTty.php`, `pikkumies/tools/system/feedback/FeedbackAsk.php`, `pikkumies/tools/system/feedback/FeedbackSend.php`, `pikkumies/hooks/system/feedback-ask/`, `pikkumies/hooks/guard/feedback-send/`, `pikkumies/bin/pikkumies`
- Tests: `tests/FeedbackSurveyTest.php`, `tests/FeedbackAskTest.php`
