# autobrr

The user's own autobrr (release automation from IRC announce channels and feeds) through its API on 127.0.0.1 (`<baseUrl>api`, key in the `X-API-Token` header). autobrr keeps its API keys in its own database and PMSS creates none, so the user stores one once with `login`. Shared rules: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every filter: `id`, `name`, `enabled`, `priority`, `actions`, `actions_enabled`, `indexers` (names), `updated_at`. |
| show | `<id>...` | no | The list record plus `created_at`, the matching rules (sizes, `match_releases`, `except_releases`, groups, shows, seasons, resolutions, sources, codecs, ...) and `action_list` (`name`, `type`, `enabled`). Tracker settings, action commands, paths and webhooks are never printed. |
| stats | | no | `version`, `found` (pmss, default, dotdir, docker, override) and `config`, `port`, `url_base`, `process` (tmux state on PMSS), `filters`, `filters_enabled`, `irc_networks`, `health_warnings`, and the release counts `releases_total`, `releases_filtered`, `releases_filter_rejected`, `releases_push_approved`, `releases_push_rejected`, `releases_push_error`. |
| health | | no | The database check and every IRC network: `source` (`database`, `irc <name>`), `type` (ok, notice, warning, error), `message` (connected or not, and the connection errors), `wiki`. |
| history | `[--limit N]` | no | Newest releases first: `id`, `event_at`, `title` (the release name), `indexer`, `filter`, `status`, `size`, `protocol`, `actions` (`action`, `type`, `client`, `status`, `rejections`). No `--since`. Download links are never printed. |
| restart | | yes | PMSS: refused while `~/.autobrrDisable` exists; otherwise `install-media-stack.sh --stop-app=autobrr`, then `--start-app=autobrr`, as the PMSS panel does. Docker: `docker restart autobrr`. Then waits up to 60 s until a new autobrr answers (`answered`: a new listening socket on PMSS, a newer container start in Docker); on PMSS also for its tmux session (`tmux`). Elsewhere refused with a hint. |
| toggle | `<id>... --on\|--off` | yes | Turns the named filters on or off, one at a time, and reads each back: `was_enabled`, `enabled`, `state` (changed, unchanged, failed, not_sent). The first failure (an error, or a filter that reads back otherwise) stops the rest and the call ends `ok:false` naming every filter. A filter that is on makes autobrr grab matching releases by itself. |
| login | | yes | The user types an API key made in autobrr (Settings, API keys) at a terminal prompt (not shown, never in the chat or on the command line). It is checked against autobrr and stored in `pikkumies/local/secrets/autobrr` (mode 0600): `stored`, `verified`. |

```sh
A=~/pikkumies/tools/arr/autobrr/autobrr.php
php $A login                      # the user runs this once, in a terminal
php $A list --human
php $A show 3
php $A history --limit 50
php $A toggle 3 --off
php $A health
```

- Ids are the filter ids `list` prints.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The key is read from `local/secrets/autobrr` on every call and never printed.
