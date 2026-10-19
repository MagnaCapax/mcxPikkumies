# ADR-0021: Web search and page fetching leave the machine only when the user configured them

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. Corrects the [ADR-0011](0011-documentation-sources-and-search-first.md) context line "Hermes' own web_search and web_extract need a paid gateway or API keys". Amended by [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md): a web search backend and its key configured in Hermes now persist across starts. Amended by [ADR-0077](0077-isolated-browser-in-a-private-network-namespace.md): browser rendering works out of the box where the server has Chromium, in an isolated browser; lazy installs stay off.

## Context
The request of 2026-10-14 asks whether Hermes comes with deep research tooling, and to decide whether Pikkumies needs its own (a later stage of the tool-unit work, [ADR-0015](0015-tool-units-one-skill-per-tool.md)).

Facts checked 2026-10-14 in the pinned Hermes v2026.9.24:
- With no web backend configured, `web_search` and `web_extract` send the model's queries and the pages it fetches to the free tiers of outside search services. `web.keyless_fallback` defaults to true (`hermes_cli/config_defaults.py`, `agent/web_search_registry.py`).
- `web.keyless_rescue` (default true) retries a failed call of a configured backend once on the same outside services.
- Pikkumies' generated `config.yaml` did not set either key, so every install had the fallback on.

AGENTS.md: "It never sends anything off the machine that the user did not ask it to."

## Options Considered
- **Leave the Hermes default.** The agent's searches would go to outside services nobody chose.
- **Turn the fallback off and let a user who wants web search configure a backend** (for example a self-hosted SearXNG, or a keyed service the user picks).

## Decision
- The generated `config.yaml` sets `web.keyless_fallback: false` and `web.keyless_rescue: false`.
- Web search works when the user configures a backend in Hermes. Until then the agent says it cannot search the web and asks the user.
- Whether Pikkumies ships its own research tooling is decided in the deep-research stage and appended to this ADR.

### Deep research (decided 2026-10-14)
Hermes v2026.9.24 has no deep-research loop. It has the parts: `web_search` and `web_extract`, browser tools (a local Chromium driven by a Node command-line tool), subagents (`delegate_task`), cron, and the bundled `grounded-citations` skill, a ledger of numbered sources with quote verification. The only built-in "research engine" is a paid outside service reached through an optional skill.
- Pikkumies adds the procedure unit `tools/research/deep-research`: its skill runs the loop on those parts. It searches memory first, then plans, searches, extracts, keeps the ledger with `grounded-citations`, sends wide sub-questions to subagents that return paths and quotes, verifies, answers with sources, and saves the result with `pikkumies save --kind research`. No new fetching code.
- A provider may later offer its own search and extract service to its customers under their key. Hermes already takes such services as a backend URL, so nothing about how it works lives in this repository.
- `security.allow_lazy_installs` is false in the generated config. Otherwise Hermes installs optional Python packages from PyPI (unpinned) and downloads a browser of about 170 MB at runtime (`tools/lazy_deps.py`). That undoes the pinned build of [ADR-0009](0009-hermes-from-a-pinned-release-tarball.md), repeats after every update because the venv is rebuilt, and costs every account on a shared server the download. A user who wants those features sets `hermes.lazyInstalls` to true in `local/config.php`.

## Consequences
- Nothing the agent searches for leaves the machine unless the user set that up.
- Out of the box, the agent cannot search the web; it says so and asks.
- Out of the box there is no browser rendering; the research skill says so and names the switch.

## References
- ADR-0011, ADR-0015, [ADR-0018](0018-memory-tool-unit.md), [ADR-0019](0019-hosts-and-credentials.md)
