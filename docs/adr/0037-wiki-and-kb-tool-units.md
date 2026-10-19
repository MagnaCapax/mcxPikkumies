# ADR-0037: The wiki and the knowledge base are tool units of their own

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-17
- Status: Accepted. Amends [ADR-0018](0018-memory-tool-unit.md): the memory unit keeps its search over everything, and two documentation units stand beside it.

## Context
The request of 2026-10-17: "we are missing the wiki & KB searches stuff from tools in pikkumies i think i cannot see them!! ... you need to build them there like everything else too".

The search existed: since ADR-0018 the memory unit (`tools/memory/pikkumies`) searches the lessons and every downloaded documentation source together, in tiers ([ADR-0031](0031-search-tiers.md)), and its `list` prints a source's pages. But nothing named it. `pikkumies/tools/` had no wiki or knowledge-base tool, the memory skill's description spoke of "provider docs and app manuals", and the README listed it under memory. ADR-0018 had folded the former `docs.php` into memory after the 2026-10-14 request to "combine merge the other memory things", whose words were about the memory skills.

Other providers' wikis and knowledge bases are documentation sites (`type: site`), so the source type alone cannot say which source is a wiki.

## Options Considered
- **Rename or re-describe the memory unit only.** Cheapest, but the operator asked for tools "like everything else": a directory per tool, visible in `tools/` and the skills index.
- **Move documentation out of the memory unit into new units.** The memory search would stop covering the wiki and knowledge base, against the 2026-10-14 request that memory search stay "the first step" and "not less powerful".
- **Two documentation units beside an unchanged memory unit, sharing its code.** Visible tools; the memory search keeps covering everything; no logic copied.

## Decision
- **Units.** `tools/documentation/wiki/` and `tools/documentation/kb/`, each with `skill.md`, `USAGE.md`, `README.md` and a script that calls `tools/documentation/lib/DocsUnit.php`. The category README lists their shared verbs; the contract test holds both to it ([ADR-0015](0015-tool-units-one-skill-per-tool.md)).
- **Verbs.** `search` ranks only this host's sources of the unit's kind (10 hits by default, `--source` for one, the best paragraph of the top hit, a retry with any of the words) and `list` prints their pages; `list --search <text>` keeps the titles that contain the text, because the output comes in pages and grepping it misses every page after the first (the seedbox-apps skill did exactly that with the memory `list`, and now uses `wiki.php list --search`). No `read`: `search` prints the path and Hermes' `read_file` opens it (as ADR-0018 decided for memory). No `refresh` or `sources`: the memory unit keeps them for all documentation, and a search downloads a stale copy first.
- **Kind.** A source has an optional `kind`: `wiki`, `kb`, `manual` or `link`. Without one, `mediawiki` is a wiki, `kb-json` a kb, `site` a manual and `link` a link. The shipped provider wikis and documentation sites are marked `wiki`, the provider knowledge bases and help centers `kb`; app manuals stay `manual` and are searched by the memory unit only. The memory unit's `sources` shows each source's kind.
- **Shared code.** The page list, the source search and the excerpt live in `pikkumies/lib/` (`DocSources::listPages`, `DocSources::searchSources`, `TextSearch::excerpt`); the memory unit uses the same functions.
- **Search first.** The memory search is still the first step and still the only search that opens the search-first gate, because it is the one that covers the lessons ([ADR-0011](0011-documentation-sources-and-search-first.md), ADR-0031). The wiki and kb units' `search` and `list` are allowed through the gate, like the memory unit's `list` and `sources`, since they only read.

## Consequences
- The wiki and the knowledge base are visible: two directories in `tools/`, two skills in the index, a line in the README.
- Two skills now search documentation. The memory skill's description names the wiki and kb skills, and theirs say to run the memory search first.
- The skills index grows by two short descriptions.

## References
- ADR-0011, ADR-0015, ADR-0018, ADR-0031

- Amended by [ADR-0043](0043-lessons-before-documentation.md) (2026-10-18): the wiki and kb units' search and list now wait for the memory search, like reads of the downloaded copies.
