# ADR-0036: Documentation layout: a landing README, one-line install, pages in docs/

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-17
- Status: Accepted

## Context
The request of 2026-10-17, verbatim: "the README.md needs up high the single command copy & paste install method like PMSS Etc. and so many things up high. then also split the README.md to multiple docs/ things".

Facts read for this decision:
- The README had grown to 120 lines and 11,311 bytes over 16 commits. Its first screen held a status line and a two-command install. There was no single copy-and-paste line.
- `docs/` held only `docs/adr/`. Every tool category already has a README for people under `pikkumies/tools/<category>/`, at 5-14 KB each.
- The PMSS README gives a one-line install near the top and links its `docs/` pages.
- `tests/ArchitectureTest.php` checks that the README's relative links resolve to files. `tests/ContractTest.php` checks that the README and AGENTS.md do not mention removed paths. Neither looked at any other page.
- The same day, cutting content down to fit an invented size cap was rejected as a fault. A byte limit on the README would repeat that fault.

## Options Considered
- **Trim in place.** Move a few sections out and add no rule. Nothing stops the README from growing back.
- **A size cap on the README, enforced by a test.** This invents a number, and content gets cut to fit it instead of moving to its proper place.
- **A landing README, docs/ pages, a placement rule and a pin test** (chosen). Every feature has a home outside the README. The test checks only what the request names.

## Decision
- **README** holds, in this order: what Pikkumies is, the one-line install, `pikkumies doctor` and `pikkumies`, the key file, the experimental warning, a list of what it can do with each line linking its category README, an index of the documentation, attribution and license.
- **One-line install.** `install.sh` at the repository root clones the repository into `~/.mcxPikkumies` or updates it, then runs `pikkumies install` with the flags it was given. The README line downloads that script and then runs it with bash. It does not pipe the download into a shell, so a cut-off download is never run half-way. A wget form is given for machines without curl.
- **docs/ pages**: `install.md` covers installing, updating, key files and a scripted install. `commands.md` covers commands and tmux. `design.md` covers what Pikkumies is meant to be and the layout. `name.md` covers the name. The text moved from the README unchanged. Each page links back to the README.
- **Placement rule** (AGENTS.md): feature documentation goes in a docs/ page or the unit's README. The README gets at most one line and a link.
- **Tests**:
  - The README's first code block is the install.sh one-liner.
  - The relative links in the README and in every `docs/*.md` page resolve to files.
  - The removed-path check covers the docs pages.
  - There is no size limit.

## Consequences
- A new user sees one line to paste. Detail is one link away.
- Older ADRs that say "the README" mean these pages: the tmux aliases (ADR-0032) are now in docs/commands.md, and the Kalevala episodes (ADR-0010) are now in docs/name.md.
- install.sh is a second way in next to the PMSS skel installer (`~/bin/install-pikkumies.sh`, PMSS ADR 0090). That installer adds the platform key file and updates itself from PMSS. install.sh is for any Linux account.
- The placement rule is checked in review only. The tests catch a broken link or a moved install line, not a feature section added back to the README.

## References
- PMSS README (one-line install, docs/ links); PMSS ADR 0090 (the skel installer).
- ADR-0010, ADR-0032 (sections that moved).
