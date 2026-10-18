---
name: credits
description: AI credits left on this account's key (balance, limits, last minute's use); use it for every "how many credits / AI credits / tokens left / balance / out of credit" question and never read key files.
---

# AI credits

Run the `balance` verb and answer from its record. This tool is the only way to the balance. For "how many tokens do I have left" or a quick overview, run `line --jsonl`: it adds what is left of the newest grant (`pct_left`), today's use and a token range.

- Never read, list, search or print a key file (`~/.virsilipasKey`, `~/.pikkumiesKey`, the Hermes `.env`) or a key, and never call the balance route yourself: the tool reads the key so you do not.
- `balance` is credits left, already net of running requests. `reserved` and `in_flight` are what running requests hold right now.
- `last_minute` covers the last 60 seconds only. Never report it as a day, a month or total use; `line` gives today's use (UTC day) as `today_credits`.
- `limits` fields that are `null` mean no limit.
- The API counts credits, not tokens. `tokens_low`-`tokens_high` is an estimate from the model's rates (all output at the low end, all input at the high end): always say it is approximate. `line` prints nothing when the provider does not report usage; then answer from `balance`.
- On an error, give the user its hint: where a key comes from (the Pulsed Media client area page "Virsilipas AI key" under "AI credits", or their own provider's account page). Do not go looking for the key.
- Never promise more credits or name prices.
