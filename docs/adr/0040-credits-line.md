# ADR-0040: The credits line: a start banner, the `pikkumies tmux` status line and `credits line`

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Extends the credits tool unit; adds to the tmux session of ADR-0032.

## Context
The request of 2026-10-18: "the pikkumies status line shows how many tokens you have when using virsilipas, how much remaining. make sure the statusline is easy to read, pretty nice to look at, graphical for text interface."

What was found:
- The platform API meters credits, not tokens. `GET /usage` gives the balance, credits charged per UTC day and the key's grants; `GET /models` gives each model's rates in credits per million tokens (input, cached input, output). A token count can only be an estimate: the same credits buy more input tokens than output tokens (four times more at the rates in the API's own example).
- There is no allowance or reset date, so "how much remaining" needs something to measure against. The newest grant is the natural one: the balance plus what was charged since that grant was added is what that grant left to spend.
- Hermes' status bar has fixed built-in fields only (`hermes_cli/cli_status_bar_mixin.py`, Hermes 2026.9.24): `display.status_bar.fields` chooses which of them show and nothing else; there is no field fed by a command or a plugin.
- Hermes' `transform_llm_output` hook could append a footer to every answer, but Hermes stores and replays the transformed text (`tests/agent/test_transform_llm_output_persistence.py`), so the footer would be fed back to the model on every later turn.

## Options Considered
- **A segment in Hermes' status bar.** Not possible without patching Hermes, which stays vanilla (ADR-0033). Rejected.
- **A footer through `transform_llm_output`.** It would pollute the stored conversation and cost tokens on every turn. Rejected.
- **The balance in the session title.** It would overwrite the names people give their sessions. Rejected.
- **Hermes' own `total_tokens` field.** It counts tokens used in the session, not what is left. Listing it would also hide every field not listed. Left to the person (`/statusbar`).
- **A live line only where Pikkumies owns the screen: `pikkumies tmux`; a banner at the start of every plain session; the agent's `credits line`.** Chosen.

## Decision
- **`credits line`** (tool unit `system/credits`) prints one line, for example `💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≈1.2M–4.7M tokens · today 35k`. `--jsonl` prints the record (`balance`, `pot`, `pct_left`, `tokens_low`, `tokens_high`, `model`, `today_credits`, `approx`).
  - Pot = balance + the credits of every `daily` row on or after the newest grant's UTC day, from `GET /usage?days=400&grants=1`. No grant: no pot, no bar and no percent. A grant older than the 400-day window marks `approx.pot`. Spending earlier on the grant's own day is counted too, so the pot can be slightly high.
  - Bar: 10 cells, percent / 10 rounded half up, clamped to 0..10. The percent is shown rounded and clamped to 0..100.
  - Tokens: balance × 1,000,000 ÷ the output rate (low end) and ÷ the input rate (high end) of the model in use (`provider.model`, else the first model listed), rounded down, always shown with `≈`. Without rates the token part is left out and the rest still shows.
  - Numbers shortened to k and M with one decimal, a trailing `.0` dropped.
  - `--ascii`, or a locale whose first set variable of `LC_ALL`, `LC_CTYPE`, `LANG` does not name UTF-8: `cr` instead of the emoji, `[#########-]`, `|` and `~`. `--tmux`: no emoji, and tmux colour codes around the bar (`#[fg=green]` over 25%, yellow from 10 to 25%, red under 10%, on a black background because tmux's default status bar is green), then `#[default]`.
- **Cache** under `local/state/credits/` (directory 0700, files 0600): the record for 60 seconds, the model rates for a day, and an endpoint's HTTP 404 on `/usage` for a day, so another provider is asked once a day, not every minute. Each request gives up after 2 seconds. Any failure (no key, 404, timeout, a bad answer) prints nothing and exits 0. The key goes only into the Authorization header; it is never printed or written.
- **Start banner.** `pikkumies` on a terminal, for an interactive session, prints the line once just before Hermes starts. It calls the unit's code as a library, not as a subprocess, and is silent on failure.
- **tmux.** `pikkumies tmux` sets, on the `pikkumies` session only (`set-option -t =pikkumies:`, never `-g`), `status-right` to `#(<php> <credits.php> line --tmux) %H:%M`, `status-right-length` 90 (the default of 40 would cut the line) and `status-interval` 60. The person's other tmux sessions keep their own status line.

## Consequences
- A live, coloured credits line in `pikkumies tmux`; every plain session starts with the balance on screen; the agent answers "how many tokens do I have left" from a record that says the range is an estimate.
- Plain sessions have no live line: the banner scrolls away. The agent's `credits line` and `balance` stay available.
- One extra request at most once a minute per account while a tmux session is open, two when the day-old rates expire.
- Future work: a status-bar segment fed by a command, proposed upstream to Hermes; when it exists, the plain session can show this line live too.

## References
- ADR-0015 (tool units), ADR-0032 (`pikkumies tmux`), ADR-0033 (vanilla Hermes).
- `pikkumies/tools/system/credits/` (`CreditsFormat.php`, `CreditsLine.php`), `tests/CreditsLineFormatTest.php`, `tests/CreditsLineTest.php`.
