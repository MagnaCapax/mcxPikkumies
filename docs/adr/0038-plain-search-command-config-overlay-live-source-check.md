# ADR-0038: Only a plain search command opens the search-first gate; one overlay forces settings per run; a live check proves every documentation source

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends [ADR-0011](0011-documentation-sources-and-search-first.md) and [ADR-0031](0031-search-tiers.md) (how the gate recognises a search) and adds a setting surface beside `local/config.php`. Amended 2026-10-19: one output suffix after the search counts (see the last addendum).

## Context
The request of 2026-10-18: "that memory search and then the search from known sources is being enforced and it is fully functional, test each provider sources, ask questions etc." and "you need to [plan] the testing so that provider and other configs can be forced, both sometimes automation might get it wrong but also for testing suite."

What was found:
- The search-first gate opened when the search command appeared anywhere in a terminal command. `echo tools/memory/pikkumies/pikkumies.php search x; rm -rf /tmp/x` opened it without searching, and every later call in that session went through. The read allowances (the memory unit's `list` and `sources`, the wiki and kb units' `search` and `list`) had the same hole: `kb.php search x; <anything>` passed as a read.
- Only the endpoint and the key could be forced for one run (`PIKKUMIES_ENDPOINT`, `PIKKUMIES_KEY`). The model, the hooks' light model and the host family came only from `local/config.php` or from automatic choices (the first id in the provider's model list, the detected host family), and both can be wrong.
- No test downloads a real documentation source; every documentation test uses a stub fetcher. Whether the shipped sources still answer was untested, and `refresh` only accepts sources in scope for this host, so another provider's source cannot be checked from one machine.

## Options Considered
- **Gate:** have the search write the gate marker itself instead of the hook recognising the command. The search does not know the Hermes session id, so it cannot write that session's marker. Rejected.
- **Gate:** recognise only one plain call of the tool. Chosen.
- **Forcing:** one environment variable per setting (`PIKKUMIES_MODEL`, `PIKKUMIES_LIGHT_MODEL`, …). Each new setting would need a new variable and code. Rejected.
- **Forcing:** command-line flags on `pikkumies`. Free text after `pikkumies` is a prompt ([ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)), so flags would collide with prompts, and hooks and tools run without them. Rejected.
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

## Addendum (2026-10-18, adversarial review of the first version)
A review of the first version found two command shapes that still opened the gate while running another command: a backslash-escaped quote (`… search \' ; <command> ; echo \'`, where the shell sees no quotes and runs the middle part) and a command substitution inside the `cd` prefix, which was removed before the substitution check ran. The matcher now reads the command left to right the way the shell quotes it: single-quoted text is literal; outside it any backslash or substitution, the `cd` argument included, is no match; quoted text is blanked before the chain check. The script path may start only with `./`, `~/pikkumies/`, `$HOME/pikkumies/`, `/home/<name>/pikkumies/` or this install's own directory, so a script of the same name in another directory no longer passes as a read. Both bypasses and the foreign-directory case are in `tests/HookSearchTest.php` (the old matcher fails them).

The same review changed the overlay and the live check:
- **Overlay:** the file is loaded as PHP, so it is used only when it is owned by the account and not writable by group or others. A file in a shared directory such as `/tmp` could otherwise be planted by another user. `pikkumies doctor` says when the variable is set but the file is not used, and the documented example keeps the file under `~/pikkumies/local/`. Starting `pikkumies` with an overlay writes the forced settings into the generated agent configuration until the next start without it; this is documented, and the question suite runs in a throwaway home.
- **Live check:** it reads the whole source list (`--limit 0`) and stops when the list is empty, cut short or miscounted. It reports a source that `refresh` printed nothing for, fetches every `link` source once (pass below HTTP 400), prints switched-off sources as SKIP, and copies only the tracked files, so `local/` with its keys is never copied.

## Addendum (2026-10-19): an output suffix counts, and an uncounted search is named
In question-suite run 3 an agent that adds `2>&1` to its commands out of habit spent most of a 600 s question on blocked calls; the block message only said "search first", never that its search had not counted. Blocked calls are not recorded, so `2>&1` on its searches is the likely cause, not a proven one. The gate had also been closed again by the halt guard's own continue message, which the search-first hook took for a new question; that is fixed with the halt guard ([ADR-0047](0047-halt-guard.md), amendment of 2026-10-19). Under the original rule `… search x 2>&1` and `… search x | head -50` were no match (Consequences, last line), though neither runs anything but the search.

- **Counted:** a plain call followed by `2>&1` or `2>/dev/null`, then or instead one pipe into `head` or `tail`, bare, with `-n N` or with `-N` (N at least 1, since 0 shows nothing), and nothing else (`… search x 2>&1 | head -50` counts). The suffix changes what is shown, never what runs, so the reason for the plain-call rule (nothing else may run) still holds. The same rule applies to the read allowances, as before.
- **Still no match:** anything else after the call: another command (`;`, `&&`, `||`, a newline), a pipe into anything but `head` or `tail` (`| sh`, `| tee`), a second pipe, a redirect into a file (`> out`, `2> err`), and other `head` or `tail` options (`-c`, `-f`). Only spaces and tabs may separate the suffix, and it must end the command. A terminal command longer than 4096 bytes is never a plain call: the suffix match is not anchored at the start, and a command padded with tens of thousands of spaces made one gate run take over 10 s, past the hook's timeout, where it fails open.
- **Message:** when the blocked command contains the memory search, the block starts with "This memory search was not counted. Run it plain, on its own, with the path below: nothing may follow it but 2>&1 or 2>/dev/null first, then one | head or | tail (bare, -n N or -N)." The note needs `search` as the verb (after the script and its flags), so a `save` whose title holds the word does not get it. A search in another path form (for example under a different install directory) gets the same note.
- Tests: `tests/HookSearchSuffixTest.php`, both the suffixes that count and the shapes above that stay blocked.
