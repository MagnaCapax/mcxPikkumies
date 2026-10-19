# ADR-0017: `--human` prints the same result for people

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted

## Context
The request of 2026-10-14: "all of the tools should have --human option to output pretty human output (all tools needs this, make pikkumies ADR about that)". The default output is JSON Lines for the agent ([ADR-0016](0016-jsonl-is-the-default-output.md)). Users run the same tools from their shell, and the agent sometimes shows a result to the user.

## Options Considered
- **A separate human tool per verb.** Twice the commands to keep in step.
- **Let each tool format its own human view.** Each would look different, and terminal safety would be solved again in every tool.
- **One shared renderer applies to the records a tool already produces.** A list renders as an aligned table, a single record as `key: value` lines, and the status line as one closing line.

## Decision
- Every tool accepts `--human`. The tool produces the same records as in JSON Lines mode; the shared renderer in `pikkumies/lib/` prints them for people.
- The renderer converts units (bytes to KiB/MiB/GiB, bytes per second to rates, Unix seconds to local date and time, fractions to percent), using the field-name conventions of ADR-0016.
- It strips control characters (C0 and C1, including ANSI escapes) from every value before printing, so a hostile torrent name cannot rewrite the terminal.
- Errors print as `error: <what>` and `hint: <what to do next>` on stderr, with the same exit codes as JSON Lines mode.
- Lines are cut to the terminal width (`COLUMNS`, else 120). Cut values end in `…`.

## Consequences
- One renderer, one place to keep terminals safe, and every tool gets a readable view at no cost.
- Human output is for reading, not parsing; scripts and the agent use the default.
- A tool that needs a special view, such as a progress bar, has to justify it against the shared renderer.

## References
- ADR-0016 (JSON Lines default), [ADR-0015](0015-tool-units-one-skill-per-tool.md) (tool units)
