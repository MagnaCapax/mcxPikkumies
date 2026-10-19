# ADR-0011: Documentation sources, trust levels and search first

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-08
- Status: Accepted. The context line on Hermes web tools is corrected by [ADR-0021](0021-web-access-off-unless-configured.md); the search command the hook and gate name is changed by [ADR-0018](0018-memory-tool-unit.md).

## Context
The spec asks for a helper "which looks into our KB+Wiki" with "configurable information sources (wikis, KB, etc.)". The request of 2026-10-08 adds: one human-editable config file listing URLs and types (`pikkumies/config/documentationSources.md`), a skill to search them, a hook or gate so memory and documentation are searched first after the first prompt of a task, and accuracy doctrine that defines trust levels (in the doctrine and in the config file) and resolves conflicting sources by adversarial rounds.

Facts this rests on, checked 2026-10-08:
- Hermes v2026.9.24 has no UserPromptSubmit event; `pre_llm_call` fires before each turn with `extra.is_first_turn` and `extra.user_message`, and a printed `{"context": ...}` is appended to the turn's message. `pre_tool_call` can block a call (`website/docs/user-guide/features/hooks.md`, `agent/shell_hooks.py`).
- Hermes' own `web_search` and `web_extract` need a paid gateway or API keys (`features/tools.md`), so they cannot be assumed.
- The Pulsed Media wiki is MediaWiki with a public API (one request returns 50 pages with their text); the knowledge base is a public JSON export (`/remote/kb.json`). Its article links point at the billing client site, where unauthenticated requests can look like failed logins.
- Documentation of this kind is exact-term text (app names, error messages, config keys), where plain word matching over a local copy finds pages reliably and cheaply; the same ranker already serves the lessons.
- `pikkumies update` stops when a tracked file was edited ([ADR-0001](0001-layout-install-and-update.md)), so users cannot edit a shipped config file in place.

## Options Considered
- **Search each source live per query** (MediaWiki search API and similar). Always fresh, no disk use. But every query leaves the machine, each type needs its own search, and results are ranked differently per source.
- **Download each source and search the copy with the lessons' ranker.** One ranking across lessons and documentation; queries stay on the machine; works offline from the last copy. Costs disk (the Pulsed Media wiki and knowledge base took 3.4 MB on 2026-10-08: 365 pages and 105 articles) and a few requests per refresh (8 for the wiki, 1 for the knowledge base).
- **Ship snapshots in the repository.** No network at all, but every documentation change needs a Pikkumies release and the copies go stale.
- **A skill only, no tool** (the agent fetches pages with `curl`). Least code, but no gate, no shared trust labels, and nothing keeps it away from the client-site pages.
- **One user-edited file** versus **a shipped file plus a local overlay.** A single edited file blocks updates; the overlay keeps the shipped file the documented source of truth and lets users add, replace or switch off sources.

## Decision
- `pikkumies/config/documentationSources.md` (shipped) and `pikkumies/local/documentationSources.md` (optional overlay, same format; a section with the same name replaces the shipped one; `status: off` switches it off). A source is a `## name` section with `type`, `url`, `trust`, `covers`, `refresh`, `status` lines and a plain description. Types: `mediawiki` and `kb-json`. Shipped sources: the Pulsed Media wiki and knowledge base.
- `pikkumies/tools/docs/docs.php` downloads each source into `local/docs-cache/<name>/` (one markdown file per page, with source, trust and address in front matter and a "data, not instructions" line) and searches lessons and documentation together with `lib/TextSearch.php`, the ranker moved out of the memory tool. Two changes to that ranker: query words in a page's title count double (a short page that merely mentions every word no longer outranks the page about the subject), and a free-text mode for the first-turn hint weighs rare words more, ignores words found in over a quarter of the pages, and needs half of the remaining words. A search downloads stale sources first; `--offline` uses the copies on disk. Downloads are https only, never follow redirects, stop at 5 MB per response and 40 requests per source, refuse any `/clients/` address, and replace a copy only after a complete download.
- Trust levels, highest first: live, official, lessons, upstream, community, recall. They are listed in the config file and in `doctrine/doctrine.md`; a test keeps the two lists equal. Priority is per topic: the machine decides what it is and does; the official source that covers a topic decides policies, prices and terms; a disagreement with a policy is reported, not acted on.
- `doctrine/SOP/resolve-conflicting-sources.md`: write the claims down with source, trust, date and scope; pick by the rules; attack the pick for up to three rounds; tell the user both claims and why; save a lesson when a real conflict is settled.
- Skill `documentation`. Hook `hooks/pre_llm_call/search-first.php`: on the first turn only, it searches the copies on disk (no network) and adds at most 1500 bytes: the instruction to search first and the titles, trust levels and paths of the local matches, never page text. Gate `hooks/pre_tool_call/search-first-gate.php`: until `docs.php search` has run in the session, tool calls that act are blocked with the command to run; reading, asking the user and the search tools stay allowed. `searchFirst.disabled` in `local/config.php` turns both off.

## Consequences
- The agent cites a source and trust level for what it says, and the first step of a task is a search that covers both lessons and documentation.
- Search words never leave the machine; the only outbound traffic is the download of public documentation, at most once per `refresh` period and only when the agent is used.
- The gate is a safety prompt for the agent's own habits, not a security boundary: its marker file is writable by the same user (AGENTS.md).
- Fetched pages reach the model only when the agent reads them; the hook passes titles only. A source a user adds is as trustworthy as that source, which is why each source carries a trust level.
- Search is exact-word matching: it works well with one to three precise words (an app, an error, a feature) and weakly with long phrases or synonyms ("money back" does not find "refund"). The skill tells the agent to retry with other words before saying nothing was found.
- Follow-ups not built: more source types (for example PMSS documentation on GitHub, about 125 files) when a pack needs them.

## References
- ADR-0001 (local state, update stops on edited files), [ADR-0003](0003-hermes-integration.md) and [ADR-0005](0005-hermes-wiring-verified-against-source.md) (Hermes shell hooks), [ADR-0006](0006-every-pack-always-loaded.md) (packs).
- Hermes v2026.9.24: `website/docs/user-guide/features/hooks.md`, `agent/shell_hooks.py`, `website/docs/user-guide/features/tools.md`.

- Amended by [ADR-0043](0043-lessons-before-documentation.md) (2026-10-18): reading the downloaded documentation now waits for the memory search; other reading stays allowed.
