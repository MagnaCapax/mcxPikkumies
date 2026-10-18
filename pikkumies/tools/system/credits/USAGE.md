# credits

The AI credits left on this account's key, read from the provider's `GET /balance` route (`balance`) or its `GET /usage` and `GET /models` routes (`line`). Read-only; nothing but those requests to the AI endpoint goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| balance | `[--human]` | no | One record: `balance` (credits left, already net of running requests; negative after a negative grant), `reserved` (held by running requests), `in_flight` (requests running now), `limits` (`max_concurrent`, `requests_per_minute`, `credits_per_minute`; `null` = no limit), `last_minute` (`requests` and `credits` over the last 60 seconds only), `key_from` (the key file used, or `PIKKUMIES_KEY`), `endpoint_host`. |
| line | `[--tmux] [--ascii] [--jsonl]` | no | One line: `💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≈1.2M–4.7M tokens · today 35k`. The bar and percent are the balance against the newest grant's pot (the balance plus what was charged since that grant's UTC day); no grant, no bar. Tokens are an estimate from the model's rates (low end at the output rate, high end at the input rate), so always `≈`. `--jsonl` prints the record instead (`balance`, `pot`, `pct_left`, `tokens_low`, `tokens_high`, `model`, `today_credits`, `approx`); `--tmux` the tmux status form (colour codes around the bar, no emoji); `--ascii`, or a locale that is not UTF-8, plain ASCII. Prints nothing and exits 0 when credits cannot be read. |

```sh
php ~/pikkumies/tools/system/credits/credits.php balance            # JSON Lines for the agent
php ~/pikkumies/tools/system/credits/credits.php balance --human    # for people
php ~/pikkumies/tools/system/credits/credits.php line               # one line; `pikkumies` prints it at start and `pikkumies tmux` shows it in the status line
```

- The key is read the way the agent itself reads it (`PIKKUMIES_KEY`, then `~/.pikkumiesKey`, then `~/.virsilipasKey`) and goes only into the request's Authorization header. It is never printed, masked or not; `key_from` says where it came from.
- `last_minute` is the API's `window`: the last 60 seconds. It is not a day, a month or the key's lifetime.
- An endpoint without a balance route (HTTP 404, usually your own provider) is an error naming where a key comes from; so is no key at all, and then no request is made.
- `line` caches under `~/pikkumies/local/state/credits/`: its record for 60 seconds, the model rates for a day, and an endpoint without a usage route (HTTP 404, usually your own provider) for a day, so that endpoint is asked at most once a day. Each request gives up after 2 seconds.
