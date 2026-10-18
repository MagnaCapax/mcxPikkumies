# prowlarr

The user's own Prowlarr through its API on 127.0.0.1 (`/api/v1` under the UrlBase in config.xml). Shared verbs and records: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every indexer: `id`, `name`, `enabled`, `protocol`, `privacy`, `priority`, `implementation`, `message`. |
| show | `<id>...` | no | The list record plus `urls` and `fields` (the settings): `name`, `label`, and `value` only when the setting's privacy is normal; passwords, keys and cookies show `hidden: true`. |
| stats | | no | Version, `started_at`, `found` and `config`, `port`, `url_base`, `process` (tmux state on PMSS), the indexer count, `health_warnings`. |
| health | | no | Prowlarr's health warnings: `source`, `type`, `message`, `wiki`. |
| history | `[--since <time>] [--limit N]` | no | Newest events first: `id`, `event_at`, `event` (indexerQuery, releaseGrabbed, ...), `item` (indexer id), `title` (the query), `successful`. |
| restart | | yes | PMSS: refused while `~/.prowlarrDisable` exists; otherwise `install-media-stack.sh --stop-app=prowlarr`, then `--start-app=prowlarr`, as the PMSS panel does. Docker: `docker restart prowlarr`. Then waits up to 60 s for a newer `started_at` (`answered`); on PMSS also for its tmux session (`tmux`): an app that answers outside it is an error. Elsewhere refused with a hint. |
| releases | `<text> [--indexer <id>,<id>] [--limit N]` | no | Searches the indexers (all, or the ones named): `handle`, `title`, `indexer`, `indexer_id`, `size`, `age_hours`, `seeders`, `leechers`, `protocol`, `published_at`, `info_url`, `categories`. Download links, magnets and guids are never printed; `handle` names the result for `grab`, for 30 minutes. |
| grab | `<handle> [--client rtorrent\|qbittorrent\|deluge] [--via-prowlarr]` | yes | Sends the named release (from `releases`) onward: `result`, `path` (`prowlarr client`, or the torrent client used), `title`, `size`. `--via-prowlarr`, or no `--client` while Prowlarr has an enabled download client, asks Prowlarr to send it to that client; otherwise this fetches the release itself and adds it to `--client`, or the one torrent client installed and running for this account (refused, naming the choices, when there is none or more than one). |
| test | `[<id>...]` | no (contacts the trackers) | Tests the named indexers, or every enabled one: `id`, `name`, `valid`, `errors`. |
| sync | | yes | Runs `ApplicationIndexerSync` (not forced) and waits up to 60 s: it rewrites the indexer settings in the connected apps. |

```sh
P=~/pikkumies/tools/arr/prowlarr/prowlarr.php
php $P list --human
php $P show 3
php $P releases "ubuntu 24.04" --indexer 3,5
php $P grab a1b2c3
php $P test
php $P sync
```

- Ids are the indexer ids `list` prints; a release handle comes from `releases` and expires after 30 minutes or the next `releases` call.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
