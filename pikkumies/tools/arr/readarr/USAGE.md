# readarr

The user's own Readarr through its API on 127.0.0.1 (`/api/v1` under the UrlBase in config.xml), read verbs only: upstream archived Readarr, so the tool adds, removes and searches nothing. Shared verbs, ids and records: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every author: `id`, `title` (the author name), `year` (0), `meta_id` (empty: no metadata id is used), `monitored`, `status`, `size` (bytes), `path`, `added_at`, `books` (book files), `books_total`. |
| show | `<id>...` | no | The list record plus `overview`, `root`, `quality_profile_id`. |
| stats | | no | Version, `started_at`, `found` (servarr, docker, override) and `config`, `port`, `url_base`, `process`, the author count, `health_warnings`, `free_bytes` (the smallest free space) and `disks`. |
| health | | no | Readarr's health warnings: `source`, `type` (ok, notice, warning, error), `message`, `wiki`. |
| history | `[--since <time>] [--limit N]` | no | Newest events first: `id`, `event_at`, `event`, `item` (author id), `title`. `--since` takes a date or "-2 days". |
| restart | | yes | Docker: `docker restart readarr` against the user's own daemon, then waits up to 60 s for a newer `started_at` (`answered`). Readarr is not one of the PMSS media-stack installer's apps, so anywhere else the tool refuses and says how. |
| queue | `[--limit N]` | no | Tracked downloads: `id`, `item`, `title`, `status`, `tracked`, `size`, `left_bytes`, `finishes_at`, `client`, `protocol`, `message`. Read only. |
| wanted | `[--cutoff] [--limit N]` | no | Missing monitored books: `id` (book id), `item` (author id), `title`, `released_at`. `--cutoff`: below the quality cutoff instead. |

```sh
B=~/pikkumies/tools/arr/readarr/readarr.php
php $B list --human
php $B stats
php $B wanted --limit 50
php $B history --since "-7 days"
```

- Ids are the author ids `list` prints.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.xml on every call and never printed.
