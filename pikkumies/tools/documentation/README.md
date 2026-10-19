# documentation

The documentation Pikkumies downloads from the sources in `pikkumies/config/documentationSources.md`, one tool per kind of source ([ADR-0015](../../../docs/adr/0015-tool-units-one-skill-per-tool.md), [ADR-0037](../../../docs/adr/0037-wiki-and-kb-tool-units.md)):

- `wiki`: the provider's wiki (on Pulsed Media, wiki.pulsedmedia.com; on other providers, their wiki or docs site);
- `kb`: the provider's knowledge base (on Pulsed Media, the client area knowledge base).

Both search only the downloaded copies on this machine; search words never leave it. A source's `kind` line says which tool it belongs to; without one, a MediaWiki source is a wiki, a knowledge-base export is a kb, and a documentation site is a manual (searched by the memory tool only).

The memory search (`tools/memory/pikkumies`) is still the first step on every task: it covers the lessons and every source together, in tiers ([ADR-0031](../../../docs/adr/0031-search-tiers.md)). Use these tools when you want the wiki or the knowledge base itself: more hits from one place, or the list of every page. The memory tool keeps `refresh` and `sources` for all documentation.

## Verbs (the same in every unit)

| verb | arguments | changes | what it does |
|---|---|---|---|
| search | `<words...> [--source <name>] [--limit N] [--offline]` | no | Ranks the pages of this host's sources of this kind (default 10 hits; `--source` picks one). Each hit: title, source, trust level, path and address; the top hit also carries its best paragraph (`excerpt`). With no hit it retries with any of the words. A stale copy is downloaded first unless `--offline`. |
| list | `[<source>...] [--search <text>]` | no | Every downloaded page of these sources by title and address: the quick list of what they cover; `--search` keeps the titles that contain the text. The output comes in pages (`next_offset`), so find a page with `--search`, not by grepping the output. |
