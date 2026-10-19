# ADR-0043: Lessons before documentation: the downloaded copies wait for the memory search

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends [ADR-0011](0011-documentation-sources-and-search-first.md) (reading stays allowed before the search) and [ADR-0037](0037-wiki-and-kb-tool-units.md) (the wiki and kb units run before the search) for documentation only.

## Context
The request of 2026-10-18, verbatim: "[investigate] that memory search and then the search from known sources is being enforced and it is fully functional".

What the question suite showed on a live run the same day (`tests/testing/questions/`, question `borg-prune-keep-daily-weekly`):
- The agent answered a documentation question correctly, but its only two tool calls were `read_file` on a page under `local/docs-cache/borg-docs/`. It never ran the memory search.
- The page path came from the first-turn hint (ADR-0011), which lists the titles and paths of local matches next to the instruction to search first.
- The search-first gate allowed it: `read_file` is a read-only tool, and the gate only blocks tools that act. So for a question that needs no action, nothing put the lessons before the documentation, and a lesson that corrects or narrows a page was never seen.
- The wiki and kb units were allowed before the search for the same reason (ADR-0037).

## Options Considered
- **Leave it, change the question's check.** The order the request names (lessons, then the known sources) would hold only when the agent also acts. Rejected.
- **Block every read before the search.** Reading the user's own files to understand the task is useful and harmless. Rejected.
- **Drop paths from the first-turn hint.** The hint would still name the pages, and the agent could still open them. It treats the symptom. Rejected.
- **Block reads of the downloaded documentation, and the wiki and kb units, until the memory search ran (chosen).** The memory search covers the lessons and the same documentation, takes about a second on the local copies, and opens the gate. The cost is one blocked call.

## Decision
- `hooks/search/search-first-gate/search-first-gate.php`: until the session's memory search ran (or after a new question re-armed the gate), it blocks:
  - `read_file` and `search_files` on a path with a `docs-cache` path segment;
  - a plain `search` or `list` call of the wiki and kb units.
- The block message says "lessons before documentation" and gives the search command.
- Everything else is unchanged. Other reads, asking the user, and the memory unit's `list` and `sources` stay allowed. After the search, the documentation and both units are open as before.
- As before, the gate is a prompt for the agent's own habits, not a security boundary: the agent's user can write its state files.

## Consequences
- An answer drawn from the documentation now always follows a search that also showed the matching lessons.
- One extra call when the agent goes for a page or the wiki/kb units first. The search's hits include the same page, so the work is not repeated.
- Tests: `tests/HookSearchTest.php` covers the blocked cases, a file merely named like the cache, and the documentation opening after the search. Mutation checked.

## References
- ADR-0011 (documentation sources, the first-turn hint, the gate), [ADR-0028](0028-search-first-every-turn-and-a-light-model-for-hooks.md) (search first on every question), [ADR-0031](0031-search-tiers.md) (search tiers), ADR-0037 (wiki and kb units), [ADR-0038](0038-plain-search-command-config-overlay-live-source-check.md) (a plain search command opens the gate)
