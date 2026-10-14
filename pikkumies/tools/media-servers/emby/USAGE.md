# emby

The user's own Emby through its HTTP API (under `/emby`). Same verbs and records as the other media servers (`tools/media-servers/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | | no | `version`, `server_name`, `found` (docker, url), `url`, `listen`, `libraries`, `items`, `streams`, `transcodes`, `transcode_bitrate`, `hardware_acceleration` (Emby's `HardwareAccelerationMode` as found), `running_tasks`, `pending_restart`, `update_available`, `login_from`. |
| libraries | | no | One record per library: `id`, `name`, `type`, `paths`, `items`, `scanning`, `progress`. |
| scan | `<library>... \| --all` | yes | Starts a recursive scan of each named library (`MetadataRefreshMode` and `ImageRefreshMode` `Default`, no `ReplaceAll*`). `result`: `started` or `already scanning`. |
| sessions | `[--addresses]` | no | What is playing: `id`, `user`, `client`, `device`, `title`, `progress`, `paused`, `play`, `transcode_reasons`, `hardware` (decoder or encoder in hardware), `bitrate`, `remote`; `address` only with `--addresses`. |
| end-stream | `<session-id>... [--reason <text>]` | yes | Sends Stop to each session (and the reason as a message first, when given); `verified` when it left the list. A failed stop ends the call `ok:false` with each id's result. |
| recent | `[--limit N]` | no | Recently added items across libraries: `title`, `type`, `library`, `added_at`. |
| logs | `[--lines N] [--addresses]` | no | `file` (the newest log the API lists) and its last `lines` (default 50). |
| restart | | yes | A container on the user's own rootless Docker: `docker restart`, then waits up to 90 s for an answer. Elsewhere it refuses and says how. |
| login | | yes | The user types an API key at a terminal; it is checked once and stored in `local/secrets/emby`. |

```sh
E=~/pikkumies/tools/media-servers/emby/emby.php
php $E stats --human
php $E libraries
php $E scan Movies
php $E sessions
php $E end-stream 3fa85f6457174562 --reason "Server maintenance"
php $E recent --limit 10
php $E logs --lines 100
php $E restart
php $E stats --url http://127.0.0.1:8097
```

- Library arguments are an `id` or a `name` from `libraries` (Emby's library ids are short numbers); session ids come from `sessions`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- `login` runs in the user's own terminal: `php $E login`.
