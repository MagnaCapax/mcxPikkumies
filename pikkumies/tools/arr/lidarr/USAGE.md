# lidarr

The user's own Lidarr through its API on 127.0.0.1 (`/api/v1` under the UrlBase in config.xml). Shared verbs, ids, records and delete rules: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every artist: `id`, `title` (the artist name), `year` (0: artists have none), `meta_id` (`mbid:<MusicBrainz id>`), `monitored`, `status`, `size` (bytes), `path`, `added_at`, `albums`, `tracks` (track files), `tracks_total`. |
| show | `<id>... [--files]` | no | The list record plus `overview`, `root`, `quality_profile_id`. `--files` adds every file in the artist folder and the delete preview (`expect`, `freed_bytes`, `refused`); use `--limit 0` (and `--offset` when `truncated`) so every file is shown. |
| stats | | no | Version, `started_at`, `found` (servarr, docker, override) and `config`, `port`, `url_base`, `process`, the artist count, `health_warnings`, `free_bytes` (the smallest free space) and `disks`. |
| health | | no | Lidarr's health warnings: `source`, `type` (ok, notice, warning, error), `message`, `wiki`. |
| history | `[--since <time>] [--limit N]` | no | Newest events first: `id`, `event_at`, `event` (grabbed, trackFileImported, downloadFailed, ...), `item` (artist id), `title`. `--since` takes a date or "-2 days". |
| restart | | yes | Docker: `docker restart lidarr` against the user's own daemon, then waits up to 60 s for a newer `started_at` (`answered`). Lidarr is not one of the PMSS media-stack installer's apps, so anywhere else the tool refuses and says how. |
| lookup | `<name\|mbid:UUID>` | no | Metadata search: `meta_id`, `title`, `year`, `in_library`, `id` (when in the library), `overview`. Lidarr resolves `mbid:`, `lidarr:` and `lidarrid:` ids itself. |
| add | `<mbid:UUID>... [--profile <name\|id>] [--root <path>] [--no-search]` | yes | Adds each artist as monitored (`result`: added, exists or failed with the reason), with the root folder's default metadata profile, and starts the search for missing albums unless `--no-search`. Quality profile and root folder are needed unless exactly one exists. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes artists out of Lidarr and keeps the files (`disposal: kept`). With `--with-data` Lidarr deletes each artist's whole folder; the tool first checks `--expect` against a fresh plan, then checks every folder afterwards: `disposal` deleted, recycled or refused, and `freed_bytes`. The first item the app fails stops the call: `failed`, the rest `not_sent`, and the call ends `ok:false` naming every item. |
| search | `<id>...` | yes | Starts the app's own search (one `ArtistSearch` per artist) and waits up to 60 s: `command_id`, `state` (completed, or still queued/started with a note). A command the app does not accept stops the rest (`failed`, then `not_sent`); the sent ones are still waited for and the call ends `ok:false` naming every one. A sent command whose state cannot be read is `unknown` (the app may still run it), and the call also ends `ok:false`. It may download and replace files. |
| queue | `[--limit N]` | no | Tracked downloads: `id`, `item`, `title`, `status`, `tracked`, `size`, `left_bytes`, `finishes_at`, `client`, `protocol`, `message`. Read only. |
| wanted | `[--cutoff] [--limit N]` | no | Missing monitored albums: `id` (album id), `item` (artist id), `title`, `released_at`. `--cutoff`: below the quality cutoff instead. |
| login-check | | no | Why the user cannot log in: one forms login with the password in `~/.media-stack-credentials.txt` (works: says where it is and stops), else one record per cause (`cause`, `detail`, `next`) and status `next`, the step to take. Causes: `tools/arr/README.md` "Why a login fails". |
| set-login | | yes | A new random password for the app's user: written to `~/.media-stack-credentials.txt` first, then set through the API (forms, required); a refused change restores the file. Checked: the API without the key must answer 401/403 and the new pair must log in. Never prints the password: `password_file` and `password_line` say where it is. On an app the PMSS installer manages, config.xml `UpdateMechanism` goes back to `Script` and the app restarts (`update_mechanism`, `restart`), as the installer does; every host change does the same. |
| repair | | yes | Host settings back to the safe values through the API: 127.0.0.1 only, forms login required, PMSS proxy UrlBase; `changed` lists them, then `restart` when the address or UrlBase changed. An empty or invalid config.xml is copied aside; the hint names the installer rerun. |
| config | `show [<section>]` \| `set <section> <field> <value>` | yes (`show`: no) | The app's own settings by section (`host`, `ui`, `downloadclient`, library apps also `mediamanagement`, `naming`, `indexer`): `section`, `field`, `value`, secrets `hidden`. `set` changes one field and reads it back: `old`, `value`. The login, the 127.0.0.1 address, API key, port, UrlBase, updates and permissions are never set here. |

```sh
L=~/pikkumies/tools/arr/lidarr/lidarr.php
php $L list --search "radio"
php $L lookup "artist name"
php $L add mbid:<id from lookup> --profile Lossless
php $L stats --human
php $L show 12 --files --limit 0         # every file and the expect value, said in your message
php $L remove 12 --with-data --expect 41.2GiB:44239978905:7b5200
php $L wanted --limit 50
php $L login-check
php $L config show host
php $L config set ui firstDayOfWeek 1
```

- Plain numbers are library ids from `list`; `mbid:` ids are only for `lookup` and `add`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
