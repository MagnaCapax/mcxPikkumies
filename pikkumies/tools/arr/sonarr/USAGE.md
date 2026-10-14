# sonarr

The user's own Sonarr through its API on 127.0.0.1 (`/api/v3` under the UrlBase in config.xml). Shared verbs, ids, records and delete rules: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every series: `id`, `title`, `year`, `meta_id` (`tvdb:81189`), `monitored`, `status`, `size` (bytes), `path`, `added_at`, `episodes`, `episodes_total`. |
| show | `<id>... [--files]` | no | The list record plus `overview`, `root`, `quality_profile_id`, `imdb`. `--files` adds every file in the folder and the delete preview (`expect`, `freed_bytes`, `refused`); use `--limit 0` (and `--offset` when `truncated`) so every file is shown. |
| stats | | no | Version, `started_at`, `found` (pmss, servarr, docker, override) and `config`, `port`, `url_base`, `process` (tmux state on PMSS), the series count, `health_warnings`, `free_bytes` (the smallest free space) and `disks`. |
| health | | no | Sonarr's health warnings: `source`, `type` (ok, notice, warning, error), `message`, `wiki`. |
| history | `[--since <time>] [--limit N]` | no | Newest events first: `id`, `event_at`, `event` (grabbed, downloadFolderImported, downloadFailed, ...), `item`, `title`. `--since` takes a date or "-2 days". |
| restart | | yes | PMSS: refused while `~/.sonarrDisable` exists; otherwise `install-media-stack.sh --stop-app=sonarr`, then `--start-app=sonarr`, as the PMSS panel does. Docker: `docker restart sonarr`. Then waits up to 60 s for a newer `started_at` (`answered`); on PMSS also for its tmux session (`tmux`): an app that answers outside it is an error. Elsewhere refused with a hint. |
| lookup | `<title\|tvdb:N>` | no | Metadata search: `meta_id`, `title`, `year`, `in_library`, `id` (when in the library), `overview`. |
| add | `<tvdb:N>... [--profile <name\|id>] [--root <path>] [--no-search]` | yes | Adds each title as monitored (`result`: added, exists or failed with the reason) and starts the search unless `--no-search`. Profile and root folder are needed unless exactly one exists. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes items out of Sonarr and keeps the files (`disposal: kept`). With `--with-data` Sonarr deletes each item's whole folder; the tool first checks `--expect` against a fresh plan, then checks every folder afterwards: `disposal` deleted, recycled or refused, and `freed_bytes`. The first item the app fails stops the call: `failed`, the rest `not_sent`, and the call ends `ok:false` naming every item. |
| search | `<id>...` | yes | Starts the app's own search (one `SeriesSearch` per series) and waits up to 60 s: `command_id`, `state` (completed, or still queued/started with a note). A command the app does not accept stops the rest (`failed`, then `not_sent`); the sent ones are still waited for and the call ends `ok:false` naming every one. A sent command whose state cannot be read is `unknown` (the app may still run it), and the call also ends `ok:false`. It may download and replace files. |
| queue | `[--limit N]` | no | Tracked downloads: `id`, `item`, `title`, `status`, `tracked`, `size`, `left_bytes`, `finishes_at`, `client`, `protocol`, `message`. Read only. |
| wanted | `[--cutoff] [--limit N]` | no | Missing monitored episodes: `id`, `item` (series id), `title`, `season`, `episode`, `aired_at`. `--cutoff`: below the quality cutoff instead. |

```sh
T=~/pikkumies/tools/arr/sonarr/sonarr.php
php $T list --search "matrix"
php $T lookup "breaking bad"
php $T add tvdb:81189 --profile HD-1080p
php $T stats --human
php $T show 12 --files --limit 0         # show the user every file and the expect value
php $T remove 12 --with-data --expect 41.2GiB:44239978905:7b5200
php $T history --since "-2 days"
```

- Plain numbers are library ids from `list`; `tvdb:N` ids are only for `lookup` and `add`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
