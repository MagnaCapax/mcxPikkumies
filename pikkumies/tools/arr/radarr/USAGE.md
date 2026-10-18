# radarr

The user's own Radarr through its API on 127.0.0.1 (`/api/v3` under the UrlBase in config.xml). Shared verbs, ids, records and delete rules: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every movie: `id`, `title`, `year`, `meta_id` (`tmdb:603`), `monitored`, `status`, `size` (bytes), `path`, `added_at`, `has_file`. |
| show | `<id>... [--files]` | no | The list record plus `overview`, `root`, `quality_profile_id`, `imdb`. `--files` adds every file in the folder and the delete preview (`expect`, `freed_bytes`, `refused`); use `--limit 0` (and `--offset` when `truncated`) so every file is shown. |
| stats | | no | Version, `started_at`, `found` (pmss, servarr, docker, override) and `config`, `port`, `url_base`, `process` (tmux state on PMSS), the movie count, `health_warnings`, `free_bytes` (the smallest free space) and `disks`. |
| health | | no | Radarr's health warnings: `source`, `type` (ok, notice, warning, error), `message`, `wiki`. |
| history | `[--since <time>] [--limit N]` | no | Newest events first: `id`, `event_at`, `event` (grabbed, downloadFolderImported, downloadFailed, ...), `item`, `title`. `--since` takes a date or "-2 days". |
| restart | | yes | PMSS: refused while `~/.radarrDisable` exists; otherwise `install-media-stack.sh --stop-app=radarr`, then `--start-app=radarr`, as the PMSS panel does. Docker: `docker restart radarr`. Then waits up to 60 s for a newer `started_at` (`answered`); on PMSS also for its tmux session (`tmux`): an app that answers outside it is an error. Elsewhere refused with a hint. |
| lookup | `<title\|tmdb:N\|imdb:ttN>` | no | Metadata search: `meta_id`, `title`, `year`, `in_library`, `id` (when in the library), `overview`. |
| add | `<tmdb:N\|imdb:ttN>... [--profile <name\|id>] [--root <path>] [--no-search]` | yes | Adds each title as monitored (`result`: added, exists or failed with the reason) and starts the search unless `--no-search`. Profile and root folder are needed unless exactly one exists. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes items out of Radarr and keeps the files (`disposal: kept`). With `--with-data` Radarr deletes each item's whole folder; the tool first checks `--expect` against a fresh plan, then checks every folder afterwards: `disposal` deleted, recycled or refused, and `freed_bytes`. The first item the app fails stops the call: `failed`, the rest `not_sent`, and the call ends `ok:false` naming every item. |
| search | `<id>...` | yes | Starts the app's own search (one `MoviesSearch` for all the ids) and waits up to 60 s: one record per movie, `id`, `title`, then that command's `command_id`, `name`, `state` (completed, or still queued/started with a note). It may download and replace files. |
| queue | `[--limit N]` | no | Tracked downloads: `id`, `item`, `title`, `status`, `tracked`, `size`, `left_bytes`, `finishes_at`, `client`, `protocol`, `message`. Read only. |
| wanted | `[--cutoff] [--limit N]` | no | Missing monitored movies, as `list` records. `--cutoff`: below the quality cutoff instead. |
| releases | `<id>` | no | Releases for one movie: `handle`, `title`, `size`, `quality`, `indexer`, `seeders`, `rejected`, `rejection` (the first reason, if rejected). Guids and links are never printed; `handle` names the result for `grab`, for 30 minutes. |
| grab | `<handle>` | yes | Sends the named release (from `releases`) to Radarr's own `DownloadRelease`, which hands it to Radarr's configured download client: `result`, `title`, `size`. |

```sh
T=~/pikkumies/tools/arr/radarr/radarr.php
php $T list --search "matrix"
php $T lookup "the matrix"
php $T add tmdb:603 --profile HD-1080p --no-search
php $T stats --human
php $T show 12 --files --limit 0         # show the user every file and the expect value
php $T remove 12 --with-data --expect 41.2GiB:44239978905:7b5200
php $T history --since "-2 days"
php $T releases 12
php $T grab a1b2c3
```

- Plain numbers are library ids from `list`; `tmdb:N\|imdb:ttN` ids are only for `lookup` and `add`; a release handle comes from `releases` and expires after 30 minutes or the next `releases` call.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
