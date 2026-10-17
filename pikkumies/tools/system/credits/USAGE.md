# credits

The AI credits left on this account's key, read from the provider's `GET /balance` route. Read-only; one request to the AI endpoint, nothing else goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| balance | `[--human]` | no | One record: `balance` (credits left, already net of running requests; negative after a negative grant), `reserved` (held by running requests), `in_flight` (requests running now), `limits` (`max_concurrent`, `requests_per_minute`, `credits_per_minute`; `null` = no limit), `last_minute` (`requests` and `credits` over the last 60 seconds only), `key_from` (the key file used, or `PIKKUMIES_KEY`), `endpoint_host`. |

```sh
php ~/pikkumies/tools/system/credits/credits.php balance            # JSON Lines for the agent
php ~/pikkumies/tools/system/credits/credits.php balance --human    # for people
```

- The key is read the way the agent itself reads it (`PIKKUMIES_KEY`, then `~/.pikkumiesKey`, then `~/.virsilipasKey`) and goes only into the request's Authorization header. It is never printed, masked or not; `key_from` says where it came from.
- `last_minute` is the API's `window`: the last 60 seconds. It is not a day, a month or the key's lifetime.
- An endpoint without a balance route (HTTP 404, usually your own provider) is an error naming where a key comes from; so is no key at all, and then no request is made.
