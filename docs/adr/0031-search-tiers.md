# ADR-0031: Search tiers: memory, this host's docs, other providers' docs, then deep research

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-16
- Status: Accepted. Supersedes the search ladder of ADR-0027 (its "Doctrine" point) and the order of what search-first enforces in ADR-0028; everything else in both stands.

## Context
The operator's request of 2026-10-16: "make sure the memory searches / data finding mission is executed and the tiering is local memory, this provider documentation (all of it), then 3rd party similar provider documentation, and final is deep research online", with rules that enforce such searches.

What existed:
- One search ranked lessons and this host's documentation together by score (ADR-0011, ADR-0018). A long lesson could rank below a short app-manual page that merely named the same words.
- Sources scoped to another provider were filtered out entirely (`DocSources::usable()`, ADR-0027). Similar providers document the same apps and the same problems (rTorrent, a full disk, the *arr apps), so a host with thin documentation of its own could not use theirs.
- The ladder in `doctrine/doctrine.md` was: memory and this host's docs, then deep research, then the support link, then ask the user.
- The search-first gate opened for every tool, the web tools included, as soon as a search command started (ADR-0028).

ADR-0027's load note: every account fills its own copy, so on a shared server a source's requests multiply by the number of accounts, all from one IP. Adding every other provider's documentation to every account would multiply that again.

## Options Considered
- **Keep one ranking and add peer sources to it.** Rejected: another provider's page could outrank this host's own documentation, and the agent would follow a policy that does not apply here.
- **Search peer documentation live** when tiers 1-2 miss. Rejected: it sends the user's words off the machine (ADR-0011) and makes a search wait on the network.
- **Download peer documentation for every account at install.** Rejected: the load note above; most accounts would never need it.
- **Tiers with a fixed order, peer copies filled in the background only after a need is shown (chosen).**

## Decision
- **Tiers**, highest first. A higher tier's hit always comes before a lower tier's, whatever the scores; within a tier the ranker's order stands. All pages are scored in one pass, so the free-text word weights are the same in every tier.
  1. Memory on this machine: lessons, reference and research notes (`TextSearch::lessonDirs`).
  2. This host's provider and app documentation: every source in scope (`DocSources::active()`), the app manuals included.
  3. Other providers' documentation: sources that are on, valid, not `link`, not in scope, and scoped to at least one Host::TABLE name (`DocSources::peers()`; `generic` alone is not another provider). Each hit keeps its source's trust label and carries the note that another provider's documentation may not apply to this host.
  4. Deep research on the web: never run by a search. The search names it in `next` when tiers 1-3 have nothing, or when only tier 3 matched.
- **Limit**: `--limit N` (default 5) is per tier, so this host's documentation is never pushed out by many lessons. The memory search prints the groups in order; the first hit of each group carries a `heading` field, every hit a `tier` field; the status line has `tiers_searched` and `hits_per_tier`.
- **Peer load**:
  - Peer sources are never downloaded in the foreground: a search reads only their copies on disk and never refreshes them, whatever their type.
  - Per account, the background fill (`pikkumies docs-fill`, ADR-0027) takes peer sources only after the first memory search that found nothing in tiers 1-2. That search creates `local/docs-peer-enabled`.
  - From then on each peer source gets at most 50 requests a day (`PEER_FILL_REQUESTS`) and a 30-day refresh (`PEER_REFRESH`), whatever its own `refresh`. A peer MediaWiki source needs at most 40 requests a refresh; a knowledge-base export one.
  - The support links (`link`) of other providers are never offered; only this host's, and only when tiers 1-2 found nothing.
- **Enforcement**:
  - The memory search records each completed search in `local/state/search-first/last-search.json`: the time, the tiers searched, and the hits per tier.
  - `DocSources::tiersSearchedThisTurn($sessionId)` returns the tiers covered in the session's current turn. A turn counts as searched when the gate's session marker is not older than the re-arm (ADR-0028) and the record was written at or after the marker, i.e. the search the gate let through has completed.
  - `DocSources::webResearchAllowed($sessionId)` asks for tiers 1-3. The search-first gate calls it for `web_search` and `web_extract` (`DocSources::WEB_TOOLS`) before its early "already searched" exit, and blocks with `DocSources::webGateMessage()` while it is false.
- **Doctrine**: the "Search first" line names the ladder at the same size: memory, this host's provider docs, similar providers' docs, deep research, the support link, ask the user.

## Consequences
- The agent sees what this machine and this host's provider say before anything else, and other providers' answers only after them, labelled as such.
- A host whose own documentation is thin still gets answers from similar providers after its first miss, without any request made while the user waits.
- **Load:** a peer source costs an enabled account at most 50 requests a day until filled, then about pages ÷ 30 a day. Only accounts that have had a tier-1/2 miss fetch peers at all. On a shared server this still multiplies by the number of such accounts; the root-maintained shared cache of ADR-0027 remains the answer if sites start refusing.
- **Disk and time:** peer copies count against the same 200 MB cache cap. Searches, including the first-turn hint, read more pages once peers are filled, at the same per-page cost as tier 2.
- **Enforcement limits:** the record is per account, not per session, because the search does not know the session id. Two sessions searching at once can satisfy each other's gate. Every file involved is writable by the agent's own user, so this remains a safety prompt, not a security boundary (AGENTS.md). A search always covers tiers 1-3; tier 3 counts as searched even when no peer copy is on disk yet.
- **Follow-ups:** the gate hook itself is wired after the hook move of ADR-0030. The memory unit's `USAGE.md` and `skill.md` should name the tiers in the same words.

## References
- ADR-0011, ADR-0018, ADR-0027 (load note, `site` fill), ADR-0028 (re-arm, gate), ADR-0030 (hook units).
- `pikkumies/lib/DocSources.php` (`search`, `peers`, `fill`, `recordSearch`, `tiersSearchedThisTurn`, `webResearchAllowed`), `pikkumies/tools/memory/pikkumies/pikkumies.php`, `tests/DocsTest.php`.
