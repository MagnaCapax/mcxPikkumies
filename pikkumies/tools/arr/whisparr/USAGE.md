# whisparr

The user's own Whisparr, v2 or v3, through its API on 127.0.0.1 (`/api/v3` under the UrlBase in config.xml). The tool reads the version on each call and uses that version's shapes (README.md "Which Whisparr"). Shared verbs, ids, records and delete rules: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Everything Whisparr holds. v3: movies and scenes: `id`, `title`, `year`, `meta_id` (`stash:<id>` or `tmdb:<n>`), `monitored`, `status`, `size`, `path`, `added_at`, `has_file`. v2: sites, with `episodes` (scene files) and `episodes_total` instead of `has_file`, `meta_id` `tpdb:<n>`. |
| show | `<id>... [--files]` | no | The list record plus `overview`, `root`, `quality_profile_id`. `--files` adds every file in the item's folder and the delete preview (`expect`, `freed_bytes`, `refused`); use `--limit 0` (and `--offset` when `truncated`) so every file is shown. |
| stats | | no | `version` (2.x or 3.x), `started_at`, `found` (servarr, docker, override) and `config`, `port`, `url_base`, `process`, the item count (`movie` on v3, `series` on v2), `health_warnings`, `free_bytes` and `disks`. |
| health | | no | Whisparr's health warnings: `source`, `type`, `message`, `wiki`. |
| history | `[--since <time>] [--limit N]` | no | Newest events first: `id`, `event_at`, `event`, `item`, `title`, `successful`. `--since` takes a date or "-2 days". |
| restart | | yes | Docker: `docker restart whisparr` against the user's own daemon, then waits up to 60 s for a newer `started_at` (`answered`). Whisparr is not one of the PMSS media-stack installer's apps, so anywhere else the tool refuses and says how. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes items out of Whisparr and keeps the files (`disposal: kept`). With `--with-data` Whisparr deletes each item's whole folder; the tool first checks `--expect` against a fresh plan, then checks every folder afterwards: `disposal` deleted, recycled or refused, and `freed_bytes`. The first item the app fails stops the call: `failed`, the rest `not_sent`, and the call ends `ok:false` naming every item. |
| search | `<id>...` | yes | Starts Whisparr's own search (v3: one `MoviesSearch` for all; v2: one `SeriesSearch` per site) and waits up to 60 s: `command_id`, `state`. A command the app does not accept stops the rest (`failed`, then `not_sent`); the call then ends `ok:false`. It may download and replace files. |
| queue | `[--limit N]` | no | Tracked downloads: `id`, `item`, `title`, `status`, `tracked`, `size`, `left_bytes`, `finishes_at`, `client`, `protocol`, `message`. Read only. |
| wanted | `[--cutoff] [--limit N]` | no | Missing monitored items: v3 the movie or scene records; v2 the scenes: `id`, `item` (site id), `title`, `released_at`. `--cutoff`: below the quality cutoff instead. |

```sh
W=~/pikkumies/tools/arr/whisparr/whisparr.php
php $W stats --human
php $W list --search "studio"
php $W show 12 --files --limit 0         # show the user every file and the expect value
php $W remove 12 --with-data --expect 41.2GiB:44239978905:7b5200
php $W wanted --limit 50
```

- Plain numbers are Whisparr's own ids from `list`; there is no `lookup` or `add` (README.md says why): titles are added in Whisparr's web page.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
