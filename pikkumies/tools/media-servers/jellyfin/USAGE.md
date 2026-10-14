# jellyfin

The user's own Jellyfin through its HTTP API. Same verbs and records as the other media servers (`tools/media-servers/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | | no | `version`, `server_name`, `found` (native, docker, url), `url`, `listen`, `libraries`, `items`, `streams`, `transcodes`, `transcode_bitrate`, `hardware_acceleration` (the setting as found), `running_tasks`, `pending_restart`, `update_available`, `pmss_status` (the PMSS watchdog's word), `login_from`. |
| libraries | | no | One record per library: `id`, `name`, `type`, `paths`, `items` (video, audio, photo and book items), `scanning`, `progress`. |
| scan | `<library>... \| --all` | yes | Starts a scan of each named library (`metadataRefreshMode` and `imageRefreshMode` `Default`, no `replaceAll*`). `result`: `started` or `already scanning`. |
| sessions | `[--addresses]` | no | What is playing: `id`, `user`, `client`, `device`, `title`, `progress`, `paused`, `play`, `transcode_reasons`, `hardware`, `bitrate`, `remote`; `address` only with `--addresses`. |
| end-stream | `<session-id>... [--reason <text>]` | yes | Sends Stop to each session (and the reason as a message first, when given); `verified` when it left the list. A failed stop ends the call `ok:false` with each id's result. |
| recent | `[--limit N]` | no | Recently added items across libraries: `title`, `type`, `library`, `added_at`. |
| logs | `[--lines N] [--addresses]` | no | `file` and its last `lines` (default 50): the log file for a PMSS install, else the newest log the API lists. |
| restart | | yes | PMSS: the installer's `--stop-app=jellyfin`, then `--start-app=jellyfin`; a container on the user's own rootless Docker: `docker restart`; waits up to 90 s for an answer. Elsewhere it refuses and prints the provider's way. |
| login | | yes | The user types an API key at a terminal; it is checked once and stored in `local/secrets/jellyfin`. |

```sh
J=~/pikkumies/tools/media-servers/jellyfin/jellyfin.php
php $J stats --human
php $J libraries
php $J scan Movies
php $J scan --all
php $J sessions --human
php $J end-stream 6f1c0e2a9b7d4c3e8f5a1b2c3d4e5f60 --reason "Server maintenance"
php $J recent --limit 10
php $J logs --lines 100
php $J restart
php $J stats --url http://127.0.0.1:8096/jellyfin
```

- Library arguments are an `id` or a `name` from `libraries`; session ids come from `sessions`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- `login` runs in the user's own terminal: `php $J login`.
