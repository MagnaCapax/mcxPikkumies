# ADR-0060: Links and the link graph: one resolver, relative links, a graph read on demand

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends [ADR-0018](0018-memory-tool-unit.md) (save and append write relative links; a `links` verb) and [ADR-0036](0036-documentation-layout.md) (the link test covers all tracked markdown).

## Context
The requests of 2026-10-18, verbatim: "memory/ADR/doctrine/prompt files Obsidian-style with markdown cross links, streamlined" and "link graph for Pikkumies (like ours), super simple". "Like ours" is the operator's own knowledge base: outbound and inbound links, a list of broken links, and a search boost for linked files.

Facts read for this decision:
- Two pieces of code read markdown links and disagreed. `TextSearch::rank` followed a link relative to its file or absolute; `Lessons::memoryLinks` (the save check) also accepted a link relative to the memory root or to the current directory, with a different pattern. Absolute links passing save but missing the search boost came from that gap, and memory-root-relative links still did.
- Save kept links as the agent wrote them, usually the absolute path a search prints (USAGE.md). Absolute paths work only on that machine and in no markdown viewer.
- Tracked markdown: 284 files, 53 relative links (48 to `.md` files), all relative to their own file. The link test covered only the README and `docs/*.md`.
- The tracked markdown mentioned ADRs as `ADR-NNNN` 732 times; 2 of those mentions were links. ADR numbers are not unique across projects: [ADR-0034](0034-containers-category.md) cites PMSS ADRs 0026 and 0027, whose numbers are also local ADRs, and the review unit's skill used `ADR-0020` as an example for any project.
- SOUL.md is built from the four core doctrine files and the tool categories' DOCTRINE.md, and each SKILL.md from a unit's skill.md and its USAGE.md verb table; both are written under `local/`, where a link relative to the source file points nowhere.
- Reading every tracked markdown file and the memory files and resolving their links takes about 40 ms here. A memory search already reads every memory file on every call.

## Options Considered
- **Wikilinks (`[[name]]`).** Obsidian reads them; GitHub and `read_file` do not, and grep cannot resolve them.
- **Links relative to the repository or memory root.** GitHub reads a path without a leading slash as relative to the file, so these break there; the existing links are all relative to the file.
- **A stored index (`links.json`) rebuilt by save and append.** Its only reader would be the `links` verb, since search needs the file text anyway. It would need a lock ([ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)) and per-file staleness checks to stay right when files change outside save, all to save tens of milliseconds on a verb that is rarely called.
- **One resolver in `lib/Links.php`, standard relative links, the graph read from the files when asked** (chosen).

## Decision
- **One link reader.** `lib/Links.php` parses `[text](target)` and `[text](target "title")`, drops the `#fragment`, and skips web addresses, anchor-only links, and anything in YAML front matter, fenced code blocks or code spans (those are examples, not links). Search's boost, save, append, the `links` verb and the link test all use it.
- **Which bases each corpus allows.** A link resolves as an absolute path, then relative to its own file, then relative to each base the caller names:
  - tracked markdown: no base, as GitHub and any viewer read it;
  - memory (search boost, `links`): the memory root, which save has always accepted;
  - save: the memory root and the current directory, so every link it accepted before this ADR still counts (a link with a fragment or a title now counts too). Nothing else resolves a link against the current directory.
- **Save and append write relative links.** A link in the new text that names a memory file is rewritten relative to the file being written, keeping its fragment and title. Other links stay as written. Bytes already in a memory file are never changed (ADR-0018). Save still reports the real paths it linked to.
- **The graph is read when asked.** The memory unit's `links <path>` lists what a markdown file links to, what links to it, and its broken links; `links --broken` lists every broken link. It reads the memory files and the tracked markdown (`git ls-files`; the memory alone where git cannot list them) on every call. There is no index file, no cron and no lock. `pikkumies doctor` adds the number of broken memory links to its memory line when there are any.
- **The link test covers every tracked `.md` file** (ADR-0036): every link must resolve to a file or directory relative to its own file, non-markdown targets included; an absolute link fails, because it works only on one machine.
- **ADR mentions are links.** The first mention of a local ADR in each tracked markdown file links to that ADR, relative to the file; the existing mentions were converted once by a script. The same test fails on a file that mentions a local ADR without linking it, and prints the link to add. Not checked: the ADR's own file, code, front matter, a mention inside a link, and numbers with no local file. Another project's ADR is a link to that project's file, so its number never counts as a local mention; an example of an ADR reference goes in backticks.
- **The core doctrine keeps plain text.** `doctrine/{soul,voice,doctrine,safety}.md` have no ADR links, because they are copied into SOUL.md. SOUL.md and every generated SKILL.md turn a relative `[ADR-NNNN](...)` back into `ADR-NNNN` on the composed text, which loses nothing (a web link to another project's ADR stays as it is); a test fails when any other relative `.md` link reaches them, so a new one is noticed instead of being dropped.

## Consequences
- One place decides what a link is; search, save and the test can no longer disagree.
- Lessons saved from now on read correctly in Obsidian, on GitHub and in `read_file`. Older lessons keep their absolute links, which still resolve on the machine that wrote them; memory is append-only, so a broken one is repaired with `append`.
- A link inside a code span or a fenced block no longer counts toward save's "link a related memory file" rule; it never rendered as a link. A `---` block at the start of a lesson body is text, not front matter (the body comes after the title in the file), so its links count.
- The ADR records, the SOPs and the unit docs now link the decisions they cite (425 links in 132 files at conversion). A range such as `ADR-0016/0017` links only its first number. A change that adds an ADR mention fails the test until the printed link is added.
- A `links` call costs about one memory search. If memory grows to thousands of files, measure before adding a cache; the cache would be a copy of the same resolver's output.

## References
- ADR-0018 (memory unit), ADR-0022 (shared state needs a lock), [ADR-0031](0031-search-tiers.md) (search tiers), ADR-0036 (documentation layout and its link test)
