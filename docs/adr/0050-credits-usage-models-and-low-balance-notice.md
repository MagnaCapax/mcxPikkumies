# ADR-0050: Credits `usage` and `models`, and a once-per-session low-balance notice

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Extends the credits tool unit (ADR-0040); adds one `pre_llm_call` hook unit (ADR-0030).

## Context
The operator's request of 2026-10-18, verbatim except for the bracketed paraphrase: "virsilipas tooling, doctrine etc. for what functions there could be, [including server-side extras], and wire the statusline to use that tooling etc. as well." Server-side extras are a separate project and are not part of this ADR.

What was found:
- The statusline already uses the credits tool: `pikkumies tmux` runs `credits line --tmux` every minute and `pikkumies` prints the same line at the start of a session (ADR-0040). Nothing there needs rewiring.
- The platform proxy (its `src/Proxy.php`, lines 77-90) answers `GET {endpoint}/usage?days=N&grants=M` with `balance`, `days`, `since`, `daily` rows (`day`, `requests`, `prompt_tokens`, `cached_tokens`, `completion_tokens`, `reasoning_tokens`, `credits`), the same counters per `models` row (`model`), and `grants`. The credits line reads only `balance`, `daily` and `grants`; the per-model rows and the token counters were not shown anywhere. `GET {endpoint}/models` is the standard models list; the line reads each model's `credits_per_million_tokens` from it.
- With no tool to list the models and their rates, an agent asked to pick a model for a task names one from memory, which may not exist on the endpoint and says nothing about its cost there.
- The line's record is cached for every account with a tmux session or a recent start banner, and it already holds `pct_left` and `tokens_min`. The agent itself never sees it unless it runs `credits line`, so it can spend the last credits on expensive steps without knowing.
- Hermes' status bar shows fixed built-in fields only (`display.status_bar.fields`); there is no field fed by a command (ADR-0040).
- A provider without these routes answers 404. The line already stays silent then and remembers it for a day; `balance` already turns a 404 into an error naming where a key comes from.

## Options Considered
- **A second marker in the tmux status line** (for example "low" or today's top model). Rejected: another request a minute per session for a figure the bar already colours red under 10%.
- **Hermes' `total_tokens` status bar field.** Rejected in ADR-0040: it counts tokens used, not left, and listing it hides every field not listed.
- **The agent runs `credits line` itself every turn.** Rejected: a tool call and its tokens on every turn, to learn something that changes rarely.
- **Hard-code a "cheap model" list or model advice in the skill.** Rejected: model ids and rates belong to the deployer's endpoint (AGENTS.md, provider-agnostic); they change without a release.
- **Two read-only verbs on the credits tool and one hook that reads the line's existing cache (chosen).**

## Decision
- **`credits usage [--days N]`** (1 to 400, default 30): `GET /usage?days=N`. Records: a summary (`balance`, `period` with `days` and `since` as a UTC day, `totals` of every counter over the daily rows plus `cache_share`), then one row per model, most credits first and ties by name (`model`, `requests`, `credits`, `prompt_tokens`, `completion_tokens`, `cache_share`), then one row per day the endpoint lists, newest first (`day`, `requests`, `credits`). `cache_share` is cached / prompt tokens to two decimals, `null` with no prompt tokens. Paged like every tool's records. A bad `--days` is an error before any request.
- **`credits models`**: `GET /models`, in the endpoint's order: `id`, `credits_per_million_tokens` (`input`, `output`, `cached_input` when sent; read by `CreditsFormat::rate`, which the line's rate cache now uses too) and `description`, each only when the endpoint sends it. Nothing is added.
- All three API verbs share one request path, `CreditsUsage::get`: no key or no endpoint is an error and no request; a 404 is one message for every route ("the AI endpoint (host) has no <route> route, so it cannot report credits", with `balance`'s existing hint); a refused key, no answer or an unreadable answer are the errors `balance` already gave. The answer's body is never quoted.
- **The skill** tells the agent to read `credits models` to choose a model (ids, rates and descriptions come from the server; never name a model from memory) and `credits usage` when the user asks where the credits went.
- **`CreditsFormat::isLow`** is the one test of "credits are low": `pct_left` under `LOW_PCT` (10, the percent under which the tmux bar is already red) or `tokens_min` under `LOW_TOKENS` (200,000 at the highest rate: a long session can send tens of thousands of tokens a turn). A missing figure never counts.
- **Hook unit `hooks/system/low-balance/`** (`pre_llm_call`, fail open, 2 s): reads the line's cache through `CreditsLine::cached` (the same freshness check as the line, for this endpoint, key and model) only when it is under 10 minutes old; when `isLow` is true, answers one fixed sentence with the numbers: credits are low, prefer the cheapest steps, tell the user once. Once per session: an empty marker named after the sha1 of the session id (validated `^[A-Za-z0-9._-]{1,128}$`) under `local/state/low-balance/`, opened with `x` so a second turn finds it; markers older than 7 days are removed. No network request and no model call; a stale or missing cache is silence. `lowBalance.disabled` turns it off.
- The `pre_llm_call` Hermes entry's timeout grows from 22 to 24 seconds (the units' sum plus 2).

## Consequences
- The agent can answer "where did my credits go" by model and by day, and chooses models from what the endpoint offers at the endpoint's own rates.
- An agent on a nearly empty key is told once, with numbers, before it spends the rest; the user hears it from the agent.
- The notice depends on the line having run in the last 10 minutes (a tmux session, or a session started on a terminal). A one-shot run with no recent line gets no notice; that is accepted rather than adding a request to every turn.
- Another provider gets the same 404 error from `usage` as from `balance`, and `models` still lists its ids, without rates.
- The platform's own server-side tools are a separate project and not part of this change.

## References
- ADR-0015 (tool units), ADR-0030 (hook units), ADR-0040 (the credits line, its cache and the statusline), ADR-0041 (a once-only `pre_llm_call` reminder, the pattern this hook follows).
- `pikkumies/tools/system/credits/` (`CreditsUsage.php`, `CreditsFormat.php`, `CreditsLine.php`), `pikkumies/hooks/system/low-balance/`, `tests/CreditsUsageTest.php`, `tests/LowBalanceHookTest.php`.
