# ADR-0012: One search command; PHP curl required

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-08
- Status: Accepted

## Context
A simplification review of the whole runtime after ADR-0011 found two places where the code did one job two ways:
- Lessons could be searched with `tools/memory/memory.php search` (lessons only) and with `tools/docs/docs.php search` (lessons and documentation, same ranker). The lessons skill named the first one, but the search-first gate opens only on the second, so an agent that followed the lessons skill was still blocked.
- `DocSources::get` had a curl transport and a PHP stream fallback. `Provider` already needs curl to list models, and without a model Hermes cannot run, so the fallback served no working install.

## Options Considered
- **Let `memory.php search` open the gate too.** Keeps two commands over the same lessons, and two places to describe them.
- **One search command, `docs.php search`;** `memory.php` only saves. The lessons skill points to the one search.
- **Keep both HTTP transports.** Two code paths to keep right for a case where the agent cannot run anyway.

## Decision
- `tools/docs/docs.php search` is the only search; it covers the lessons and the documentation. `memory.php` keeps `save`; `memory.php search` exits with code 2 and prints the new command. This changes the ADR-0007 note that `memory.php` commands keep working.
- Downloads use PHP curl only; without it `docs.php` reports "the PHP curl extension is missing". CI already installs it.

## Consequences
- One search to learn, and the gate, the skills and the tool agree.
- A lessons search can now download a stale documentation copy first, as any `docs.php search` does; `--offline` avoids that.
- A PHP without curl cannot download documentation (it could not reach the provider either).

## References
- ADR-0007 (customising shipped skills), ADR-0011 (documentation sources and search first)
