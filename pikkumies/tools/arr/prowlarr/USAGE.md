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
| login-check | | no | Why the user cannot log in: one forms login with the password in `~/.media-stack-credentials.txt` (works: says where it is and stops), else one record per cause (`cause`, `detail`, `next`) and status `next`, the step to take. Causes: `tools/arr/README.md` "Why a login fails". |
| set-login | | yes | A new random password for the app's user: written to `~/.media-stack-credentials.txt` first, then set through the API (forms, required); a refused change restores the file. Checked: the API without the key must answer 401/403 and the new pair must log in. Never prints the password: `password_file` and `password_line` say where it is. On an app the PMSS installer manages, config.xml `UpdateMechanism` goes back to `Script` and the app restarts (`update_mechanism`, `restart`), as the installer does; every host change does the same. |
| repair | | yes | Host settings back to the safe values through the API: 127.0.0.1 only, forms login required, PMSS proxy UrlBase; `changed` lists them, then `restart` when the address or UrlBase changed. An empty or invalid config.xml is copied aside; the hint names the installer rerun. |
| config | `show [<section>]` \| `set <section> <field> <value>` | yes (`show`: no) | The app's own settings by section (`host`, `ui`, `downloadclient`, library apps also `mediamanagement`, `naming`, `indexer`): `section`, `field`, `value`, secrets `hidden`. `set` changes one field and reads it back: `old`, `value`. The login, the 127.0.0.1 address, API key, port, UrlBase, updates and permissions are never set here. |

```sh
P=~/pikkumies/tools/arr/prowlarr/prowlarr.php
php $P list --human
php $P show 3
php $P releases "ubuntu 24.04" --indexer 3,5
php $P grab a1b2c3
php $P test
php $P sync
php $P login-check
php $P config show host
php $P config set ui firstDayOfWeek 1
```

- Ids are the indexer ids `list` prints; a release handle comes from `releases` and expires after 30 minutes or the next `releases` call.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
