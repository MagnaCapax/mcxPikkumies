# ADR-0122: A one-shot whose connection ended mid-turn reports what is known and is never run again

## Metadata
- Author: Väinämöinen, the orchestrating agent. This is the agent's decision, not an operator ruling.
- Date: 2026-11-23
- Status: Accepted. Extends [ADR-0113](0113-one-shot-waits-for-a-starting-gateway.md); keeps [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) (Hermes unpatched); follows [ADR-0109](0109-one-shot-progress-and-stats.md) (errors always print) and narrows the bound of [ADR-0047](0047-halt-guard.md).

## Context
GH #19: a one-shot question can end with `Gateway disconnected; turn outcome is unknown` after it made tool calls and spent tokens. The person gets no answer and no information, asks again, and pays again.

Facts, read in Hermes v0.22.5 source and the job ledger (nothing here was run against a live gateway):
- The client reader (`hermes_cli/gateway_client.py`, `GatewayClient._read`) ends with that one text for a closed socket, an OS error, a bad frame and a timeout alike. It does not say which side ended the connection, and the same text is printed by a client that was stopped by a signal.
- Hermes' own text says to resume the session and not to resend the work. The gateway may still be running the turn detached, or may have ended with it.
- The gateway writes `ws closed peer=... reason=...` when a client connection ends (`tui_gateway/ws.py`). That logger writes to `agent.log`, not `gateway.log` (`hermes_logging.py`). The line has no session tag, so it cannot be tied to one run by identity.
- A prompt sent into a lost session depends on who owns the session: it queues behind the running turn (a second paid turn), is refused, or interrupts the turn. Every branch that sends a prompt is another paid turn, and none returns the lost turn's outcome as such. This has not been measured.
- Before this change `HaltRun::run` judged every run that had a session ID and a snapshot, and a `continue` or wrap-up verdict is a paid `chat --resume` into that session.
- Since the hold of ADR-0113 re-runs only a run that did nothing, a lost turn after work already passed through unchanged. That stays.
- `StreamProgress` reads a stream only until the run's stdout goes live on the caller's own `--format stream-json` path (no renderer), so on that path the final `result` event of a run that did work was never read.

## Options Considered
- **Resume the session and return its outcome (the issue's first acceptance branch).** Rejected for now: every prompt-sending resume is a second paid turn into a session whose turn may still run, the outcome may not exist if the gateway ended, and the behaviour is unmeasured.
- **Run the question again.** Rejected: it repeats work that may be finished.
- **Match the close line to the run by time.** Rejected: the gateway's log timestamps are in the gateway process's local time, and a concurrent client's close would be attributed to this run.
- **Say what is known, run nothing more.** Chosen.

## Decision
- A run whose result carries an error that begins `Gateway disconnected; turn outcome is unknown` (`GatewayWait::lostTurn`), on the renderer path and on the caller's own `--format stream-json` path, is a lost turn. It is not held, not re-run and not judged: `HaltRun::run` ends it after every Hermes run it makes, including the continue and wrap-up resumes, with Hermes' exit code. The skipped judge is logged as the `lost-turn` verdict in the halt-guard log.
- The person gets these stderr lines, always, also with `--no-progress`: the connection ended and Hermes does not say which side ended it; the question was not asked again; the session ID and `pikkumies --resume <id>` with the instruction to send no new text; whether a gateway answers now (answering with its state, a process or lock without an answer, or none); and the gateway's `ws closed` line from `agent.log`.
- The close line is printed only when exactly one such line was written since the run started. The window is the byte offset of `agent.log` taken before the run, so no clock or time zone is involved. With none, the report says none was written yet (a killed gateway writes none); with two or more it gives the count and says this run's is not determined; a log that is missing, unreadable or rotated during the run is not determined. The report never claims a cause.
- Both gateway checks are read-only and the report fails to "not determined", never to a guess.
- `GatewayWait::triggered` is not changed here: the zero-work hold stays as ADR-0113 set it.

## Consequences
- A lost turn costs no second paid turn from the launcher, and the person has the session ID and the state at once.
- The record accumulates: the lost-turn verdict and the printed close line say, over time, which side ended these connections. The cause hunt and the resume-to-collect path stay open on #19 until a testing machine with a real gateway measures them.
- The match is on Hermes' error text. A Hermes release that rewords it, or that moves the `tui_gateway` logger into `gateway.log`, silently returns the report to "not determined"; the test with the exact text is the tripwire on a pin bump.
- On a plain-text passthrough, or with no `HERMES_HOME`, no stream is read and the report is not printed; Hermes' own text still prints.
- Tests: `tests/GatewayWaitTest.php` (the text match, the report's wording, the close-line rule with none, one, two, an earlier line, a rotated log, an over-long line and non-UTF-8 bytes), `tests/HaltRunLostTurnTest.php` (a run that did work and lost its connection runs once and is not judged; a continue that loses its turn is not resumed again; the support job's own stream-json gets the report; `--no-progress` still reports; other errors and clean runs print no report).

## References
- GH MagnaCapax/mcxPikkumies#19.
- Hermes v0.22.5 source: `hermes_cli/gateway_client.py`, `tui_gateway/ws.py`, `hermes_logging.py`.
