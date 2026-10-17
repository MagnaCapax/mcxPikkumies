# ADR-0018: Memory is one tool unit: save, append, search, with search first

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. Supersedes ADR-0012 (the one search lives in `docs.php`). Amends ADR-0011: the search command the hook and gate name changes; the hook still injects titles, trust levels and paths only.

## Context
The request of 2026-10-14: "a skill for memorySave, memoryAppend, memorySearch a bit like ours but lightened, but not less powerful, so memorySearch is just the first step and the last big step if not found -- combine merge the other memory things, and make sure the memory doctrine is there", with "Minimal frontmatter, but full on hierarchy for the memory, autofetches and a hook similar to here to enforce memory searches. cross linking enforced", and the location `tools/memory/pikkumies/ {save,search,append}`, so later memory sources can sit beside it.

Today lessons are saved by `tools/memory/memory.php save` and searched, together with the downloaded documentation, by `tools/docs/docs.php search` (ADR-0012). The `lessons` and `documentation` skills describe them, and the search-first hook and gate are keyed to `docs.php search` (ADR-0011).

## Options Considered
- **Keep two tools and add verbs.** Memory and documentation stay in two places that the agent must both remember.
- **Use the Hermes built-in memory.** It holds about 2,200 characters, goes into every turn and cannot be searched (ADR-0007).
- **One memory unit that owns the lessons and the documentation copies.** One skill and one search, and a place for later memory sources (`tools/memory/<source>/`).

## Decision
- **The unit.** `tools/memory/pikkumies/pikkumies.php` with the verbs `search`, `save`, `append`, `list`, `refresh` and `sources`. The last three are the former `docs.php` verbs, unchanged; `read` is dropped because Hermes' own `read_file` opens any path the search prints. `tools/docs/`, `memory.php` and the `lessons` and `documentation` skills are removed. The skill is named `pikkumies` after its directory, as the operator named it; its description starts with "Memory:" so the skills index says what it is.
- **Where memory lives.** Under `pikkumies/memory/`: `lessons/<topic>/` for solved problems, `reference/` for facts and data, `research/` for longer findings. The whole tree is gitignored except its README.
- **Trust.** Memory sits inside the ADR-0011 ladder (live, official, lessons, upstream, community, recall), and priority stays per topic: `lessons/` and `reference/` are labelled at the lessons level, `research/` is labelled `research` (below lessons: verify before relying on it), and the documentation copies keep their configured levels. Results are ordered by relevance; the label tells the agent how far to trust each hit. Lessons saved before this layout, directly in `memory/`, are still searched.
- **Search, in two steps.**
  1. `search` ranks lessons and documentation together (`TextSearch`, keyword mode). It returns title, source, trust level, path and address for each hit, plus an excerpt of the best lessons or official hit (autofetch).
  2. With no hit, the same call retries in free-text mode (rare words weigh more, a share of the words is enough). With still no hit, the status line says the last step: delegate a wider look to a subagent, or search the web through a configured backend, then save what was learned.
- **Save.** `save` writes to any of the three places (`--kind lesson|reference|research`, default lesson) and needs a topic, a title, at least one source, and a body that links to at least one existing memory file, or `--no-related "<reason>"`. Before writing, it searches the title, prints the closest files as links to use, and warns when one looks like the same lesson (append instead). Frontmatter holds only `date`, `topic` and `sources`.
- **Append.** `append` adds a dated section to an existing memory file and never rewrites what is there.
- **Hook and gate.** On the first turn the hook still lists only titles, trust levels and paths of the local matches (ADR-0011): Hermes appends hook output to the user message, so no page text goes there. The excerpt arrives when the agent runs `search`, as tool output marked as data. The gate opens on `pikkumies.php search`. The hook, the gate, the skill text and the removal of `docs.php` change in one commit, with a test that the new search opens the gate.
- **Doctrine.** The memory sections of `doctrine/doctrine.md` (already in every turn through SOUL.md) are extended to cover:
  - search first;
  - the two steps;
  - save at once, with sources and links in the text;
  - append-only;
  - the ladder;
  - wide searches delegated to a subagent, which must return paths and quotes;
  - never store secrets or other people's data.

## Consequences
- One skill and one command for everything the agent knows on the machine.
- Saved lessons link to each other, so search finds neighbours.
- Search output can carry text that someone else wrote; it is tool output, labelled as data, and the excerpt comes only from lessons or official sources.
- Later memory sources (chat exports, other memory systems) become sibling units under `tools/memory/`.

## References
- ADR-0007, ADR-0011, ADR-0012, ADR-0015

## Amendment 2026-10-17
The wiki and the knowledge base are also tool units of their own (ADR-0037): `tools/documentation/wiki` and `tools/documentation/kb` search and list only those sources. This unit is unchanged: its search still covers the lessons and every source, and it keeps `refresh` and `sources`.
