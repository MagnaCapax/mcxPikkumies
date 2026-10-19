# ADR-0082: Web search can be turned on with one command, through ddgs (DuckDuckGo), and stays off until the person does

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Extends [ADR-0021](0021-web-access-off-unless-configured.md) and keeps its decision: nothing the agent searches for leaves the machine until the user sets it up, and until then the agent says it cannot search and asks. This ADR makes that setup one command.

## Context
With web search off, Hermes prints "Web search is off ... Run `hermes setup tools`" and the agent can only point at Hermes' setup menu: several steps, and most backends need an API key and a signup. The operator's rules for Pikkumies: privacy first, fully opt in only (2026-10-19), and near zero questions, finished tasks ([ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md)). ADR-0021 already allows one question here.

Facts read in the pinned Hermes source for this change (the earlier draft of this ADR, 2026-10-19; not run on a live host):
- `ddgs` (`plugins/web/ddgs/provider.py`) is the one built-in search provider that needs no key and no account; it searches DuckDuckGo through the optional `ddgs` Python package, search only (no extract).
- The package is an optional extra (`pyproject.toml`), not in the default install. `pm.cli install --extra NAME` and `hermes pm install --extra NAME` add it; extras union into the installed venv (`pm/install.py`).
- `web.search_backend` defaults to `""` (`hermes_cli/config_defaults.py`). `web.keyless_fallback`, the anonymous ring of four other services, does not include ddgs and stays off (ADR-0021).

## Options Considered
- **ddgs as the default backend.** Working search with no question, but every install would send queries to DuckDuckGo without anyone choosing it: against ADR-0021, AGENTS.md ("never sends anything off the machine that the user did not ask it to") and the operator's opt-in rule. Rejected.
- **Turn `web.keyless_fallback` on.** Four outside services, none chosen. Rejected (ADR-0021).
- **Leave it to `hermes setup tools`.** Opt-in, but several steps and usually a key. Kept as the way to pick another backend.
- **A setting, off by default, that the person turns on with one command, which pins ddgs.** Chosen.

## Decision
- A new setting `web.search`, default off. `pikkumies websearch on|off|status` is the person's switch (`lib/WebSearch.php`, the `browse`/`access` verb pattern).
- While it is on, every start pins `web.search_backend: "ddgs"` in the managed Hermes config ([ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md)); off, nothing names a backend, so the pin is gone at the next start. The seed never names one, and a backend the user configured in Hermes themselves stays theirs while it is off. While it is on, the managed pin wins; to use another backend the user turns it off first.
- Turning it on is the person's: `ConfigureTiers` makes off-to-on a "person" change, so `Config::set` (the configure tool included) refuses it, and the verb refuses `on` from inside an agent (`NestedAgent::depth()`), as `configure undo` does. Off is free. These are safety prompts, not security (AGENTS.md).
- The `ddgs` package is built in (`HermesInstall::build()`, its own `pm.cli install --extra ddgs` after the full install), so `on` works at once. The package alone sends nothing. On a Hermes built before this change, `websearch on` also runs `hermes pm install --extra ddgs`.
- SOUL.md says which state it is in. Off: the agent says web search is off and names `pikkumies websearch on`, the one question ADR-0021 allows; it never turns it on itself. On: each `web_search` sends its query to DuckDuckGo.

## Consequences
- Out of the box nothing is searched online, as before. One command from the person turns on working, keyless search.
- Once on, DuckDuckGo receives each query when the agent calls `web_search`, and only then. `web_extract` still has no provider.
- Not verified on a live host: `hermes pm install --extra ddgs` on an older build, and DuckDuckGo answering the pinned `ddgs` package. Read from source only.

## References
- ADR-0021, ADR-0062, ADR-0073, ADR-0059 (tiers), ADR-0077 and ADR-0079 (the verb pattern).
- `pikkumies/lib/WebSearch.php`, `Config.php`, `ConfigureTiers.php`, `HermesConfig.php`, `HermesInstall.php`, `Launch.php`; `tests/WebSearchTest.php`.
