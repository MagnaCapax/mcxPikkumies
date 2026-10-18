# ADR-0040: The credits line: a start banner, the `pikkumies tmux` status line and `credits line`

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Extends the credits tool unit; adds to the tmux session of ADR-0032.

## Context
The request of 2026-10-18: "the pikkumies status line shows how many tokens you have when using virsilipas, how much remaining. make sure the statusline is easy to read, pretty nice to look at, graphical for text interface."

What was found:
- The platform API meters credits, not tokens. `GET /usage` gives the balance, credits charged per UTC day and the key's grants, newest first, a refund being a grant with negative credits; `GET /models` gives each model's rates in credits per million tokens (input, cached input, output). The same credits buy more input tokens than output tokens, and more cached input tokens still (16 times more than output at the rates in the API's own example), so no token range is a true range. What can be said honestly is a floor: the tokens left if everything were billed at the model's highest rate.
- There is no allowance or reset date, so "how much remaining" needs something to measure against. The newest positive grant is the natural one: the balance plus what was charged since that grant was added is what that grant left to spend.
- Hermes' status bar has fixed built-in fields only (`hermes_cli/cli_status_bar_mixin.py`, Hermes 2026.9.24): `display.status_bar.fields` chooses which of them show and nothing else; there is no field fed by a command or a plugin.
- Hermes does have a widget hook for wrapper CLIs: a Python subclass of its CLI overrides `_get_extra_tui_widgets` (`hermes_cli/cli_tui_mixin.py`) to add widgets above the status bar. Using it means shipping a Python wrapper, and this repository has no Python (AGENTS.md).
- Hermes' `transform_llm_output` hook could append a footer to every answer, but Hermes stores and replays the transformed text (`tests/agent/test_transform_llm_output_persistence.py`), so the footer would be fed back to the model on every later turn.

## Options Considered
- **A segment in Hermes' status bar.** Not possible without patching Hermes, which stays vanilla (ADR-0033). Rejected.
- **A widget through Hermes' wrapper-CLI hook.** Upstream supports it, but it needs a Python wrapper CLI, and this repository has no Python. Rejected for that reason.
- **A footer through `transform_llm_output`.** It would pollute the stored conversation and cost tokens on every turn. Rejected.
- **The balance in the session title.** It would overwrite the names people give their sessions. Rejected.
- **Hermes' own `total_tokens` field.** It counts tokens used in the session, not what is left. Listing it would also hide every field not listed. Left to the person (`/statusbar`).
- **A live line only where Pikkumies owns the screen: `pikkumies tmux`; a banner at the start of every plain session; the agent's `credits line`.** Chosen.

## Decision
- **`credits line`** (tool unit `system/credits`) prints one line, for example `💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≥1.1M tokens · today 35k`. `--jsonl` prints the record (`balance`, `pot`, `pct_left`, `tokens_min`, `model`, `today_credits`, `approx.pot`).
  - Pot = balance + the credits of every `daily` row on or after the newest positive grant's UTC day, from `GET /usage?days=400&grants=10` (ten grants, so a refund on top does not hide the grant before it). No positive grant: no pot, no bar and no percent. A grant older than the 400-day window marks `approx.pot`, and the line then shows `~` before the percent. Spending earlier on the grant's own day is counted too, so the pot can be slightly high.
  - A balance of zero or below (below zero only after a refund) is 0%: an empty bar, red in tmux.
  - Every remaining figure rounds down, so the line never shows more than is left: the percent (one decimal in the record, a whole number on the line), the bar (10 cells, percent / 10 rounded down, so a full bar is 100%) and the numbers (k and M with one decimal, a trailing `.0` dropped: 4,960,000 is `4.9M`).
  - Tokens: `≥` balance × 1,000,000 ÷ the higher of the output and input rates of the model in use (`provider.model`, else the first model listed), rounded down. Any mix of input, cached input and output buys at least that many at the current rates. A request the platform bills by bytes, because the upstream reported no usage, can cost more. Without rates the token part is left out and the rest still shows.
  - `today` is the credits charged on the current UTC day, not the person's local day.
  - `--ascii`, or a locale whose first set variable of `LC_ALL`, `LC_CTYPE`, `LANG` does not name UTF-8: `cr` instead of the emoji, `[#########-]`, `|` and `>=`. `--tmux`: no emoji, and tmux colour codes around the bar (`#[fg=green]` over 25%, yellow from 10 to 25%, red under 10%, on a black background because tmux's default status bar is green), then `#[default]`.
- **Cache** under `local/state/credits/` (directory 0700, files 0600). Each file is for one endpoint and one key: it holds the first 12 hex digits of the key's sha256, never the key. The record is kept 60 seconds, for one model. The model rates are kept a day. A 404 on `/usage` (no usage route: another provider) is kept a day. Any other failure is kept 10 minutes, so tmux does not ask every minute: another HTTP status, no answer at all, or an answer that cannot be read, on `/usage` or on `/models`. A cache dated in the future (the clock was set back) is not trusted.
- **Timeouts.** curl gives each request 2 seconds (`CURLOPT_TIMEOUT`), connecting included. Before curl starts, `Http` resolves the endpoint's host name itself (`gethostbynamel` and an AAAA lookup, for its local-listener check, ADR-0019), under the system resolver's own timeouts, outside those 2 seconds. A line with nothing cached makes two requests.
- Any failure (no key, 404, timeout, a bad answer) prints nothing and exits 0. The key goes only into the Authorization header; it is never printed or written.
- **Start banner.** `pikkumies` on a terminal, for an interactive session, prints the line once just before Hermes starts. It calls the unit's code as a library, not as a subprocess, and is silent on failure.
- **tmux.** `pikkumies tmux` sets, on the `pikkumies` session only (`set-option -t =pikkumies:`, never `-g`), `status-right` to `#(<php> <credits.php> line --tmux) %H:%M`, `status-right-length` 90 (the default of 40 would cut the line) and `status-interval` 60. tmux expands `%` and `#` inside `#()` too, so they are doubled in the command and a path holding them still runs. The person's other tmux sessions keep their own status line.

## Consequences
- A live, coloured credits line in `pikkumies tmux`; every plain session starts with the balance on screen; the agent answers "how many tokens do I have left" with a floor it can stand behind.
- Plain sessions have no live line: the banner scrolls away. The agent's `credits line` and `balance` stay available. The reason is this repository's no-Python rule, not a missing hook upstream.
- One extra request at most once a minute per account while a tmux session is open, two when the day-old rates expire; a failing endpoint is asked at most every 10 minutes.

## References
- ADR-0015 (tool units), ADR-0019 (local listeners), ADR-0032 (`pikkumies tmux`), ADR-0033 (vanilla Hermes).
- `pikkumies/tools/system/credits/` (`CreditsFormat.php`, `CreditsLine.php`), `pikkumies/lib/Http.php`, `tests/CreditsLineFormatTest.php`, `tests/CreditsLineTest.php`, `tests/CreditsLineCacheTest.php`, `tests/fixtures/credits/harness.php`.
