# jackett

The user's own Jackett through its API on 127.0.0.1 (under BasePathOverride from ServerConfig.json). The key goes in the URL as Jackett requires, only to the user's own port, and is never printed (README.md "The key in the URL"). Shared rules: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every configured indexer: `id` (Jackett's own name, like `1337x`), `name`, `enabled`, `privacy` (public, semi-private, private), `language`, `site`. |
| show | `<id>...` | no | The list record plus `description`, `limit_default`, `limit_max`, `search_modes` (mode: the parameters it takes, or false) and `categories`. |
| stats | | no | `found` (default, docker, override) and `config`, `port`, `url_base`, `process`, the configured indexer count, `health_warnings`; `version` and `started_at` are null (Jackett shows them only to its admin). |
| health | | no | Warnings only when Jackett's `/health` does not answer OK; Jackett has no other health checks. |
| history | | no | Not available: Jackett keeps no history. The call ends `ok:false` and says so. |
| restart | | yes | Docker: `docker restart jackett` against the user's own daemon, then waits up to 60 s for a newer container start time (`answered`). Anywhere else the tool refuses and says how. |
| test | `[<id>...]` | no (contacts the trackers) | An empty search on each named indexer, or on every configured one: `id`, `name`, `valid`, `results`, `elapsed_ms`, `error`. No results counts as a failure, as in Jackett's own test. |

```sh
J=~/pikkumies/tools/arr/jackett/jackett.php
php $J list --human
php $J show 1337x
php $J test
php $J test 1337x privtracker
```

- Ids are the indexer ids `list` prints; one unknown id fails the call before anything is contacted.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from ServerConfig.json on every call and never printed.
