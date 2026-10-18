# credits

The AI credits left on this account's key, read from the provider's `GET /balance` route (`balance`) or its `GET /usage` and `GET /models` routes (`usage`, `models`, `line`). Read-only; nothing but those requests to the AI endpoint goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| balance | `[--human]` | no | One record: `balance` (credits left, already net of running requests; negative after a negative grant), `reserved` (held by running requests), `in_flight` (requests running now), `limits` (`max_concurrent`, `requests_per_minute`, `credits_per_minute`; `null` = no limit), `last_minute` (`requests` and `credits` over the last 60 seconds only), `key_from` (the key file used, or `PIKKUMIES_KEY`), `endpoint_host`. |
| usage | `[--days N] [--human]` | no | Where the credits went over the last `N` days (1 to 400, default 30), from `GET /usage`. First a summary record: `balance`, `period` (`days`, `since` as a UTC day), `totals` (`requests`, `credits`, `prompt_tokens`, `cached_tokens`, `completion_tokens`, `reasoning_tokens`, `cache_share`). Then one record per model, most credits first: `model`, `requests`, `credits`, `prompt_tokens`, `completion_tokens`, `cache_share` (cached input tokens / prompt tokens, two decimals; `null` with no prompt tokens). Then one record per day the endpoint lists, newest first: `day`, `requests`, `credits`. Records are paged like every tool's (`--limit`, `--offset`; the status line gives `next_offset`). An endpoint without a usage route (HTTP 404) is the same error as `balance` gives for its route. |
| models | `[--human]` | no | The models `GET /models` lists, in its order (with no model configured, the first is the one used): `id`, `credits_per_million_tokens` (`input`, `output`, and `cached_input` when sent) when the endpoint sends rates, `description` when it sends one. Nothing is added that the endpoint did not send. |
| line | `[--tmux] [--ascii] [--jsonl]` | no | One line: `💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≥1.1M tokens · today 35k`. The bar and percent are the balance against the newest positive grant's pot (the balance plus what was charged since that grant's UTC day; a refund is skipped); no grant, no bar; a balance of zero or below is 0% and an empty bar; `~` before the percent when the grant is older than the usage window. Remaining figures round down. Tokens are a floor: what the balance buys at the model's highest rate, so at least that many (`≥`). `today` is the UTC day. `--jsonl` prints the record instead (`balance`, `pot`, `pct_left`, `tokens_min`, `model`, `today_credits`, `approx.pot`); `--tmux` the tmux status form (colour codes around the bar, no emoji); `--ascii`, or a locale that is not UTF-8, plain ASCII. Prints nothing and exits 0 when credits cannot be read. |

```sh
php ~/pikkumies/tools/system/credits/credits.php balance            # JSON Lines for the agent
php ~/pikkumies/tools/system/credits/credits.php balance --human    # for people
php ~/pikkumies/tools/system/credits/credits.php usage --days 7     # where the credits went this week, by model and by day
php ~/pikkumies/tools/system/credits/credits.php models             # the models on offer, with the endpoint's own rates and descriptions
php ~/pikkumies/tools/system/credits/credits.php line               # one line; `pikkumies` prints it at start and `pikkumies tmux` shows it in the status line
```

- The key is read the way the agent itself reads it (`PIKKUMIES_KEY`, then `~/.pikkumiesKey`, then `~/.virsilipasKey`) and goes only into the request's Authorization header. It is never printed, masked or not; `key_from` says where it came from.
- `last_minute` is the API's `window`: the last 60 seconds. It is not a day, a month or the key's lifetime.
- An endpoint without a balance route (HTTP 404, usually your own provider) is an error naming where a key comes from; so is no key at all, and then no request is made.
- `line` caches under `~/pikkumies/local/state/credits/`: its record for 60 seconds, the model rates for a day, an endpoint without a usage route (HTTP 404, usually your own provider) for a day, and any other failure for 10 minutes; each for one endpoint and key. curl gives each request 2 seconds; the host name lookup comes before that and is not bounded by it.
