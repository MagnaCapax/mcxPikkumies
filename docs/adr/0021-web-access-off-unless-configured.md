# ADR-0021: Web search and page fetching leave the machine only when the user configured them

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. Corrects the ADR-0011 context line "Hermes' own web_search and web_extract need a paid gateway or API keys".

## Context
The request of 2026-10-14 asks whether Hermes comes with deep research tooling, and to decide whether Pikkumies needs its own (a later stage of the tool-unit work, ADR-0015).

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

## Consequences
- Nothing the agent searches for leaves the machine unless the user set that up.
- Out of the box, the agent cannot search the web; it says so and asks.

## References
- ADR-0011, ADR-0015, ADR-0018, ADR-0019
