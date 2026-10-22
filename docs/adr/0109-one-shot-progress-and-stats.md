# ADR-0109: Progress lines and run figures for a one-shot question

- Status: Accepted (2026-10-22)
- Request (2026-10-22): "we need the same method into pikkumies cli aquestions when just pikkumies "question here" done so user sees progress going on, but that might be a bit expensive using the full on same model .... maybe then make the responses more scarce or perhaps only tail less lines. scarce is bad because then the updates are not as fast. ofc --argument to disable them, also for väinämöinen use there should be --argument to display tokens, turns etc. wall time and other diagnostics after the fact"

## Context

- `pikkumies <question>` without a terminal on stdin or stdout is a one-shot run: Hermes answers and exits ([ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)). On a terminal it is Hermes' interactive session, which shows its own progress.
- In plain one-shot mode Hermes prints its own tool lines into stdout, mixed with the answer, in its own format, and nothing about tokens.
- Hermes v0.22.2 `chat --format stream-json` writes one flushed JSON event per line: `system/init` (model, session id), `text` deltas, `tool_use` (name, input), `tool_result` (is_error) and one `result` per run (final text, tokens, error; `hermes_cli/stream_json.py`). It carries no count of model requests. Hermes keeps that count per session in its store (`state.db`, `sessions.api_call_count`; a compressed or resumed session has a parent, a helper agent is a child session).
- The progress lines the request points at narrate each step with a model call. A Pikkumies question costs about 18,000 credits on the default model, and with the default settings the "light" model Pikkumies uses for its own checks is the same model as the agent.

## Decision

- **Where.** Only a question on the prompt path (`pikkumies [flags] <question>` or `pikkumies [flags] --prompt-file F`) without a terminal on stdin or stdout and without a `--format` of the caller's own. Pikkumies then adds `--format=stream-json` and renders the stream (`lib/StreamProgress.php`, through `lib/HaltRun.php`). `pikkumies run chat ...` stays the untouched passthrough ([ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)), and so does any call that passes its own `--format`.
- **stdout** carries the answer: the answer of each answering run (the first and each halt-guard continue), a blank line between them. Pikkumies' own setup notices print where they always did.
- **stderr** carries one line per step as it happens: `[12:01:03] ran df -h — checking disk usage`, the time, a plain verb, what the step acted on and the agent's own last sentence before it. What a step shows is chosen per tool: the command, the path, the search pattern, the URL, the helper's goal; any other tool shows its name only; file content, patch text and code never. Every string the model wrote is capped, passed through `Redact::secrets()` (which also removes control, bidi and zero-width characters) and made one line before it is cut, so steered text cannot drive the terminal or fake a line; text that cannot be checked shows as "(hidden)". A failed step gets `❌ <verb> failed`.
- **Narration, part of the progress lines.** After a read of Hermes' output that printed a step, one light-model call (`HookLlm`, 10 s, 40 tokens) gets the last 6 step lines as data and its one line prints as `↳ ...`. Steps that arrive while it runs are not narrated separately, so it never falls behind, and it costs a small fixed prompt instead of the whole run (the request's "tail less lines"). It is a HookLedger unit ([ADR-0071](0071-goal-review.md)): `narrate`, 300 calls a day by default (`hooks.dailyCalls.narrate`), at least 6 s apart for the whole account; when the day's calls are used up it says so once. It runs on the user's own endpoint and credits.
- **Off switch.** `--no-progress` turns off the step lines and the narration. They are also off when an agent started Pikkumies (`PIKKUMIES_DEPTH` at 1 or more on entry), where the lines would only cost the parent agent tokens.
- **Errors always print**, progress or not: the run's own error (`❌ Hermes: ...`, which stream-json would otherwise keep out of sight), and a run that ended without a result (`❌ Hermes ended without finishing (exit N)`, with the text so far as the answer). The exit code is Hermes'.
- **`--stats`** prints on stderr after the run: wall time, model, model requests (turns: the positive differences of `api_call_count` over the final session's parents and descendants, read after Hermes has exited; "unknown" when PHP has no SQLite3), tool calls (and failed ones), Hermes runs (answer plus halt-guard continues), and tokens in, out, cache read, cache write and total from the result events. It says that the halt guard's and the narration's own calls are not counted, and it gives no money figure. `--stats` without a question is an error.
- The two switches are launcher flags under [ADR-0052](0052-launcher-model-flag-and-configure.md): only at the start of the command line, never passed to Hermes. On a terminal a given switch prints one line and the session starts.
- The prompt-path one-shot now prints a session id (stream-json) and is judged by the halt guard, as ADR-0047's Consequences always said: see the [ADR-0047 amendment of 2026-10-22](0047-halt-guard.md).

## Alternatives not taken

- **Keep Hermes' plain output.** Its tool lines are mixed into stdout with the answer, in a format Pikkumies does not control, with no figures.
- **Narration only behind a flag.** Narration is what was asked for; its cost is bounded by the small fixed prompt, the gap and the daily cap instead.
- **A narration process of its own** (asynchronous). A second loop and process for a line that can wait a few seconds.
- **Progress on only when stderr is a terminal.** Hides it where one-shots run (pipes, scripts, SSH), and needs a third flag to force it back.
- **Model requests from the run counter** (`PIKKUMIES_RUN`, [ADR-0096](0096-goal-review-v2-drift-check-by-time-and-tokens.md)). Setting it for a one-shot changes the goal review's spool and the feedback question's eligibility; a diagnostics switch must not change what it measures.
- **The figures as JSON.** A caller that wants machine-readable events already has `pikkumies run chat --format stream-json`.

## Consequences

- A piped or SSH question shows what the agent does as it does it, for no extra model call beyond the capped narration, and the answer alone on stdout.
- A script that reads a prompt-path one-shot's stdout now gets only the answer, and one answer per answering run when the halt guard continues the work.
- Each prompt-path one-shot is judged: one light-model call per judged turn, up to 3 continues and one wrap-up, on the user's own credits, as for every other judged turn.
- Hermes' own `session_id:` line still prints on stderr after each run.
- Tests: `tests/StreamProgressTest.php` (lines, what may not show, escapes, a line over 1 MiB, errors, several runs, narration batching, figures), `tests/HermesUsageTest.php`, `tests/LaunchSwitchesTest.php`, `tests/HaltRunRenderTest.php` (stdout answer only, markers, wrap-up on stderr, end without a result); the fake Hermes refuses the two switches.
