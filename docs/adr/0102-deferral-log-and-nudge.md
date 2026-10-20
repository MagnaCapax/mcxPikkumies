# ADR-0102: A log of every deferral the halt guard catches, and a nudge toward investigating instead of asking

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Accepted. Extends [ADR-0047](0047-halt-guard.md) (the decision log and the continue message), [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) (the clarify block) and [ADR-0088](0088-common-mistakes-list-and-check.md) (a new entry); follows [ADR-0101](0101-refuse-and-report-never-ask.md).

## Context
The operator, 2026-10-20: "detect everytime pikkumies defers work to ask the operator, or doesn't complete it's mission without questions to the operator, document and log tose (max 75 words, not specifics, just enough to classify the deferral and antipattern) and since you can detect it, nudge towards investigate, adversarial review, iteration loop rather than annoy the opeator/huimnan/user".

The halt guard already detects these cases: its questions Q3 (work not shown carried out), Q4 (asked to approve, choose or confirm a step of the requested work, or promised it for later), Q5 (a failed command left unaddressed), Q6 (listed its own next steps) and Q9 (offered or handed back instead of doing) each continue the turn with a fixed sentence. The clarify tool hook blocks every attempt to ask the user to decide. But the decision log kept only the verdict and the yes/no answers, nothing said which kind of deferral it was, and the continue message told the agent what was missing but not how to get unstuck without asking.

## Options Considered
- **Log the model's reason or a quote of the answer.** Against the operator's "not specifics", and it would put conversation text in a log. Rejected: fixed PHP text only.
- **A separate deferral log file and hook.** One more unit for what the decision log already records per turn. Rejected: two fields on the existing line.
- **A model-written nudge per case.** A fixed method sentence says the same every time and cannot carry injected text. Chosen.

## Decision
- `HaltJudge::DEFERRAL_CLASSES` maps each continuing question to a fixed class, the matching `doctrine/antipatterns.md` id and a note of at most 75 words: Q3 `work-not-done` (`done-without-receipts`), Q4 `asked-permission-for-the-task` (the new entry 57), Q5 `failure-unaddressed` (`exit-status-lost`), Q6 `listed-own-next-steps` (`plan-instead-of-doing`), Q9 `offered-or-handed-back` (`answered-not-done`). `HaltJudge::deferrals()` returns the entries for a continue verdict, from its answers alone.
- `HaltState::logDecision` writes `deferral` (that list, or a caller's own fixed entries) and `final_attempt` (true on the last continue the guard may send: attempt 2 of the hook's 3, or the wrapper's last continue). No transcript, final answer or model text, as before.
- The clarify block is logged with source `clarify` and the one entry `asked-user-to-decide` (`asking-what-you-can-check`), never the question or the choices, and only when `halt.disabled` is off.
- `HaltJudge::METHOD`, "Instead of asking: investigate first (the tool's own docs and usage, memory, a read-only check), then attack your plan (what could go wrong, how it is undone), iterate until it holds, and do it.", is part of `CONTINUE_RULE`, so it ends every continue, and it ends the clarify block.
- `doctrine/antipatterns.md` gets entry 57, `asked-permission-for-the-task`, so the common-mistakes check hunts it too; `doctrine/safety.md` gets one sentence; `docs/halt-guard.md` gets a Deferral log section.

## Consequences
- The deferrals the guard catches can be counted by class from `local/logs/halt-guard/` on the machine; nothing is sent, and retention follows `sessions.retentionDays`.
- A deferral the guard does not catch (a turn it never judges, an unsure answer that fails open) is not logged.
- Tests: `tests/DeferralLogTest.php`; `tests/NoQuestionsGuardTest.php` and `tests/AntipatternsTest.php` still hold.

## References
- ADR-0047, ADR-0073, ADR-0088, ADR-0101; `lib/HaltJudge.php`, `lib/HaltState.php`, `hooks/halt/halt-guard/`, `hooks/tools/clarify/decide-yourself/`
