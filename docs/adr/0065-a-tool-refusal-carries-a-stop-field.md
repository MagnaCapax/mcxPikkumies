# ADR-0065: A tool's own refusal carries a `stop` field

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0016](0016-jsonl-is-the-default-output.md) (one more key on the failure status line).

## Context
When a tool refuses (a `CliError`: "not restarted: ...", "no qBittorrent password is stored", "port belongs to another account"), the error and its hint are the answer: what is wrong and what the user does next. In one question-suite run the rTorrent restart refused, and instead of telling the user the agent went through the tool's own restart, client and locate code over many rounds, looking for a way around the refusal; that one question cost about 2.6 million tokens (the refusal itself came from the test sandbox lacking the platform's restart files, but any real refusal reads the same). The torrent-clients doctrine already said "When `restart` refuses, tell the user why and stop", and it did not stop that run. Doctrine is read once per session, far from the moment of the refusal; the status line is read at that moment.

Every refusal of every tool already leaves through one place: the `CliError` branch of `Cli::run`, which prints `{"ok":false,"error":...,"hint":...}`.

## Options Considered
- **Per-verb text** ("do not read the source") in each error message: many copies that drift, and new verbs forget it.
- **A hook that blocks reading the tools' own source.** The source is public and reading it is cost, not harm; a hook is a larger change and would also block legitimate reading. Measure the smaller change first.
- **One fixed field on the refusal line, added in `Cli::run` (chosen).**

## Decision
- `Cli::fail()` takes a fourth argument; the `CliError` branch of `Cli::run` passes it, and the JSON failure line then carries `"stop": Cli::STOP`, a fixed sentence: the refusal is the answer; tell the user the error and the hint, or do what the hint says; do not read the tool's code or work around it.
- Not on usage errors (unknown verb or option, exit 2): there the agent should fix its command. Not on internal errors (a bug): the hint already says so. Not in `--human` output, which is for people.
- It is a habit prompt for the agent, not a control (AGENTS.md: hooks and prompts are safety prompts, not security). Whether it changes behaviour is measured, not assumed: the question suite gains `rtorrent-restart-refused` (a refused restart, `max_calls` and `not_calls` on reading the tool's code).

## Consequences
- Every tool, old and new, gets the field from one line of code. A consumer that parses the failure line by known keys is unaffected; nothing in this repository compares the key set exactly.
- About 40 more tokens on each refusal line.
- If the suite shows no change, the field is removed again rather than strengthened by more text; the hook option above is the next candidate.

## References
- [ADR-0016](0016-jsonl-is-the-default-output.md), [ADR-0039](0039-question-suite.md), [ADR-0064](0064-rtorrent-restart-proves-the-old-process-is-gone.md)
- `pikkumies/tools/torrent-clients/DOCTRINE.md`; tests: `tests/CliTest.php`
