# ADR-0016: JSON Lines is the default output of every tool

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. A tool's own refusal adds a `stop` key to the failure line ([ADR-0065](0065-a-tool-refusal-carries-a-stop-field.md)).

## Context
The request of 2026-10-14: every tool "should have --human option to output pretty human output ... and default is --jsonl for the agent (also an ADR about that)". The agent reads tool output as text in its context, and every byte costs tokens on every turn that keeps it. The tools published so far print a mix: `status.php` prints pretty JSON, `docs.php` prints plain lines, and `memory.php` prints a path. There is no shared rule, so the agent cannot tell success from failure without reading prose.

## Options Considered
- **One JSON document per call.** The output is only parseable once it is complete, and a long list has to be held in memory before it is printed.
- **Plain text tuned for the model.** It costs fewer tokens on small answers, but every tool invents its own layout, and field names drift.
- **JSON Lines: one JSON object per line, with a final status line.** It streams, it is valid line by line, one record can be dropped or counted with `head` or `wc -l`, and the same fields hold for every client in a category.

## Decision
- Every tool writes JSON Lines to stdout unless `--human` is given. `--jsonl` is accepted and is the default.
- Each record is one line: a JSON object without pretty-printing, with slashes and Unicode unescaped.
- The last line is always a status line. Success is `{"ok":true,...}`, which may add a count or `"truncated":true`. Failure is `{"ok":false,"error":"<what>","hint":"<what to do next>"}`.
- Values carry raw units: bytes, bytes per second, Unix seconds, and fractions from 0 to 1. Converting units is the human view's job.
- Exit code 0 means ok, 1 an error, and 2 a usage error. The status line and the exit code always agree: a usage error also ends in `{"ok":false,...}` whose hint lists the verbs, and a bug in a tool ends in `{"ok":false,"error":"internal error (...)"}` with exit 1, never a stack trace. A verb cannot override `ok` or `count`.
- At most `--limit` records (default 20) and 16 KiB of records are printed per call, always whole records, well under Hermes' 50,000-byte terminal output cap, which would cut lines in the middle. The status line says when more exist (`"truncated":true`, `"total"`, `"next_offset"`), and `--offset N` prints the next page. A single record over the budget is replaced by a stub that says so and suggests `--fields`.
- Diagnostics go to stderr, never stdout.
- Text that came from outside (torrent names, titles, page text) stays a JSON string value and is never turned into instructions. Skills say that it is data.

## Consequences
- The agent decides success from one line, and skills can rely on the same field names across a category.
- A person needs `--human` to read output comfortably ([ADR-0017](0017-human-output-option.md)).
- Existing tools change their output when they move into tool units; their skills and tests change in the same commit.

## References
- ADR-0017 (`--human` output), [ADR-0015](0015-tool-units-one-skill-per-tool.md) (tool units)

## Addendum 2026-10-14: what `total` counts
- When the app reports its own total (Plex totalSize, Servarr totalRecords, Bazarr total, autobrr count), the status line `total` is that number, never lower than the records read; when the app reports none, `total` is the number read, or `null` when more may exist but the count is unknown. `--human` prints "N shown of M" or "N shown, more exist". Found in a live run: Plex `recent --limit 1` reported `total:2` while the server held 3.

## Addendum 2026-10-17: error text is not cut to fit a guess
- Error and log text inside a record was cut to 300 or 400 characters at twelve places, with no stated reason, so a long backup, docker or app error reached the agent without its end. One constant now bounds one free-text field: `Cli::TEXT_MAX` = 4000 bytes, the value the docker log lines already used, a quarter of the 16 KiB record budget so a record with an error still fits. Hermes' own terminal cap is 50 000 bytes (`tools/tool_output_limits.py`).
- system-status keeps 300 per matched log line: it puts up to 50 lines in one record, and 50 x 300 stays under the record budget.
- Before cutting text to fit any size check, find what the check protects and measure it ([ADR-0029](0029-tool-doctrine-loaded-into-soul.md) amendment).
