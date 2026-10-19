# The halt guard

A small, deliberately weak check on whether a turn actually finished the user's request, or is only chat and needs no wrap-up (ADR-0047). It never blocks on uncertainty, it never has the power to do anything but say one more sentence, and it runs the model's answer through a fixed deterministic decision in PHP — the model's free text is never shown to the agent.

## What it does

When a turn stops, the guard shows a light model the evidence (this turn's commands and their exit codes, changed files), the final answer, and a cut-to-size tail of the conversation, and asks nine fixed yes/no questions: is this just chat (a question about how something works counts), did the user ask for work (a polite request counts), does the evidence show it happened, did the answer defer a step of that work it could do now, or ask the user to approve or choose it (a change the work needs counts as asked for), is there an unaddressed failed command, did it list next steps of that work instead of taking them, is it waiting on something only the user can give (a password or login they type, a fact only they have, a step a Pikkumies safety check refused or asked them about, a step they declined, or a yes to a change they did not ask for that cannot be undone; a choice the agent could make itself, or permission for a step of the requested work, does not count), did it already offer an idea, and did it offer to do or check something, or hand the user a step, instead of doing it, or say it should work without a check ([ADR-0073](adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)). From the answers alone — never the model's own wording — it decides:

- **Chat, or waiting on the user:** let the turn end as it would anyway.
- **Not actually finished:** one more turn, with a fixed sentence naming what is missing (a failed command, a deferred step, listed-not-taken next steps, an offer or hand-back instead of the work, or evidence that the work was not carried out) plus "do only what is still missing from what the user asked for", which also tells the agent not to change the user's config, services or data beyond the request. A how-to question about this account counts as the request when the change can be undone, so an answer that only offers to make it is continued; an offered change that cannot be undone is waiting on the user (ADR-0073).
- **Finished:** one wrap-up turn asking the agent to save a lesson if it learned something reusable, and to close with a short idea for the user or a light topic, as a plain suggestion rather than a question — unless the evidence already shows a lesson save and the answer already offered something.
- **Unsure, unparsable, empty, timed out, or the hooks' model disabled:** allow. Uncertainty is never a reason to keep going.

## Where it applies, and where it does not

Hermes only offers one hook that can keep a turn going after a text answer, `pre_verify`, and only after the turn edited a file with `write_file`/`patch` (`agent/turn_stop_gates.py`, Hermes v2026.9.24). Measured against 41 real question-and-answer transcripts, only 3 turns out of 441 calls used a file-editing tool; the rest were plain terminal commands or chat. So:

- **A session turn that edited a file:** `hooks/halt/halt-guard/` runs live, inside that turn, through `pre_verify`.
- **A one-shot run** (`pikkumies <text>` or `pikkumies run chat --query=...` without a terminal on stdin or stdout — Hermes' own answer-and-exit behaviour): `lib/HaltRun.php` runs the same judge itself once Hermes has exited, using the snapshot `hooks/halt/halt-snapshot/` wrote and the command log as evidence, whether or not the turn edited a file. It skips judging a turn the live hook already judged.
- **An interactive session turn with no file edit** (a terminal-only or pure chat turn): nothing here covers it — Hermes offers no hook for that case. Ask with `/goal <what you want>` instead: it is Hermes' own judged keep-going loop (`hermes_cli/goals.py`), checked by an auxiliary model after every turn until the goal is met or `goals.max_turns` (Hermes' default; unchanged here) is reached.

If Pikkumies already writes a Hermes `auxiliary.*` setting for this provider (it does, `auxiliary.background_review`), `/goal`'s own judge (`auxiliary.goal_judge`) is pointed at the same light model the hooks use, so it costs no extra model selection; `goals.max_turns` is left at Hermes' default, since nothing here measured a better number.

## Safety

- Both hooks are fail-open: `failClosed: false` ([ADR-0030](adr/0030-hooks-as-units-run-by-one-orchestrator.md) fails closed only for `pre_tool_call`, where the delete guard lives). An unreadable snapshot, a dead model endpoint or a timeout always lets the turn end.
- The continue and wrap-up messages are built only from fixed PHP sentences (`lib/HaltJudge.php`); the model's reply contributes only its nine yes/no/unsure answers. A transcript or tool output containing "ignore previous instructions" can at most flip one answer, never inject text into what the agent is told next.
- Hermes' own `agent.max_verify_nudges` (default 3) bounds the live hook; it is also checked locally against the `pre_verify` payload's `attempt`, and once a wrap-up has been sent for a turn it is never sent twice. The one-shot wrapper bounds itself to the same 3 continues plus one wrap-up.
- `session_id` must match `^[A-Za-z0-9._-]{1,128}$` everywhere it is used to name a file, or nothing happens.
- The snapshot and decision log never hold the full transcript or the final answer: the snapshot keeps a size-capped tail (`local/state/halt/`, 0700/0600) for judging only, and the decision log (`local/logs/halt-guard/`, 0700/0600) keeps only the yes/no answers.
- `halt.disabled` in `local/config.php` turns off both hooks and the one-shot wrapper.

## See also

- `hooks/halt/halt-snapshot/README.md`, `hooks/halt/halt-guard/README.md`: what each hook unit does.
- `lib/HaltJudge.php`: the one decision function, used by the hook and the wrapper.
- [docs/adr/0047-halt-guard.md](adr/0047-halt-guard.md): the full decision record.

[Back to the README](../README.md)
