# ADR-0038: Only a plain search command opens the search-first gate; one overlay forces settings per run; a live check proves every documentation source

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends ADR-0011 and ADR-0031 (how the gate recognises a search) and adds a setting surface beside `local/config.php`.

## Context
The request of 2026-10-18: "that memory search and then the search from known sources is being enforced and it is fully functional, test each provider sources, ask questions etc." and "you need to /vplan the testing so that provider and other configs can be forced, both sometimes automation might get it wrong but also for testing suite."

What was found:
- The search-first gate opened when the search command appeared anywhere in a terminal command. `echo tools/memory/pikkumies/pikkumies.php search x; rm -rf /tmp/x` opened it without searching, and every later call in that session went through. The read allowances (the memory unit's `list` and `sources`, the wiki and kb units' `search` and `list`) had the same hole: `kb.php search x; <anything>` passed as a read.
- Only the endpoint and the key could be forced for one run (`PIKKUMIES_ENDPOINT`, `PIKKUMIES_KEY`). The model, the hooks' light model and the host family came only from `local/config.php` or from automatic choices (the first id in the provider's model list, the detected host family), and both can be wrong.
- No test downloads a real documentation source; every documentation test uses a stub fetcher. Whether the shipped sources still answer was untested, and `refresh` only accepts sources in scope for this host, so another provider's source cannot be checked from one machine.

## Options Considered
- **Gate:** have the search write the gate marker itself instead of the hook recognising the command. The search does not know the Hermes session id, so it cannot write that session's marker. Rejected.
- **Gate:** recognise only one plain call of the tool. Chosen.
- **Forcing:** one environment variable per setting (`PIKKUMIES_MODEL`, `PIKKUMIES_LIGHT_MODEL`, …). Each new setting would need a new variable and code. Rejected.
- **Forcing:** command-line flags on `pikkumies`. Free text after `pikkumies` is a prompt (ADR-0022), so flags would collide with prompts, and hooks and tools run without them. Rejected.
- **Forcing:** one overlay file named by `PIKKUMIES_CONFIG`, merged over `local/config.php` by `Config::load`. Every reader of the settings (Hermes config generation, hooks, tools, host detection) already goes through `Config::load`, so one change covers all of them. Chosen.
- **Live check:** a new production command that bypasses the scope. The overlay can already force the host family, after which the existing `refresh` downloads that family's sources. Rejected in favour of a test script.

## Decision
- **Plain call.** A terminal command opens the gate only when it is one call: an optional `cd <dir> &&` in front, optional `php`, the memory script, flags, then `search`. Anything after it that the shell would run (`;`, `&&`, `||`, `|`, a redirect, a newline) or a command substitution (`$(…)`, backticks, `${…}`, also inside double quotes) means no match. Single-quoted words are literal to the shell and may contain any character, so a search for `'a > b'` still counts. The same rule applies to the read allowances. Tests: `tests/HookSearchTest.php`.
- **Hermes' memory tool** stays on the gate's read-only list. It can write, but only Hermes' own notes, and the gate is a prompt for the agent's habits, not a security boundary (ADR-0011).
- **Overlay.** `PIKKUMIES_CONFIG=<file>` names a PHP file returning a settings array; `Config::load` merges it last. A missing or unreadable file changes nothing. It carries the same trust as `local/config.php`: only the account's own environment can name it, and anything it can set, `local/config.php` can set. `pikkumies doctor` prints the overlay path when one is in use. Documented in `docs/commands.md`. Tests: `tests/ConfigTest.php`.
- **Live source check.** `tests/testing/sources/check-sources.php` (outside the CI test glob) copies the checkout into a temporary directory, forces each host family through an overlay, runs `refresh` for that family's sources (each source once) and prints one line per source, `OK` or `FAIL` with the reason. Results go to a directory outside the repository. It needs network access and is run by hand.

## Consequences
- A model that mentions the search command without running it, or chains another command after it, no longer gets past the gate.
- Automation and the question suite force any setting with one file and one variable; nothing in the repository names a provider or a model.
- Whether the shipped documentation sources work is now checkable on demand, one line per source.
- A search command that pipes its own output (`… search x | head`) no longer opens the gate; the plain command does.
