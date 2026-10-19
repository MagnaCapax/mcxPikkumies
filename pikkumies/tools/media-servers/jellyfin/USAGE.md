# jellyfin

The user's own Jellyfin through its HTTP API. Same verbs and records as the other media servers (`tools/media-servers/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | | no | `version`, `server_name`, `found` (native, docker, url), `url`, `listen`, `libraries`, `items` (the sum of the libraries' counts, as `libraries` shows them; `null` when one is unknown or the credential may not list users), `item_counts` (the non-zero per-type counts of `/Items/Counts`, e.g. `movie`, `episode`, as the server gives them), `streams`, `transcodes`, `transcode_bitrate`, `hardware_acceleration` (the setting as found), `running_tasks`, `pending_restart`, `update_available`, `pmss_status` (the PMSS watchdog's word), `login_from`. |
| libraries | | no | One record per library: `id`, `name`, `type`, `paths`, `items` (video, audio, photo and book items), `scanning`, `progress`. |
| scan | `<library>... \| --all` | yes | Starts a scan of each named library (`metadataRefreshMode` and `imageRefreshMode` `Default`, no `replaceAll*`). `result`: `started` or `already scanning`. |
| sessions | `[--addresses]` | no | What is playing: `id`, `user`, `client`, `device`, `title`, `progress`, `paused`, `play`, `transcode_reasons`, `hardware`, `bitrate`, `remote`; `address` only with `--addresses`. |
| end-stream | `<session-id>... [--reason <text>]` | yes | Sends Stop to each session (and the reason as a message first, when given); `verified` when it left the list. A failed stop, or a session that still plays after it (the list is read up to 5 times, 0.4 s apart; a client may ignore the command), ends the call `ok:false` with each id's result. |
| recent | `[--limit N]` | no | Recently added items across libraries: `title`, `type`, `library`, `added_at`. When truncated, `total` is the merged count, or `null` (unknown) when a library had more than one call read: the server sends no total for recently added items. |
| logs | `[--lines N] [--addresses]` | no | `file` and its last `lines` (default 50): the log file for a PMSS install, else the newest log the API lists. |
| restart | | yes | PMSS: the installer's `--stop-app=jellyfin`, then `--start-app=jellyfin`; a container on the user's own rootless Docker: `docker restart`; waits up to 90 s for an answer. Elsewhere it refuses and prints the provider's way. |
| login | | yes | The user types an API key at a terminal; it is checked once and stored in `local/secrets/jellyfin`. |
| login-check | | no | Why the user cannot log in: whether Jellyfin answers and its setup wizard is finished, then one login with `JELLYFIN_USERNAME` and `JELLYFIN_PASSWORD` from `~/.media-stack-credentials.txt` (works: says where it is, keeps the session, stops), else one record per cause (`cause`, `detail`, `next`). A refused one is marked and not tried again. Causes: README.md "Why a Jellyfin login fails". |
| set-login | | yes | A new random password for that user: written to `~/.media-stack-credentials.txt` first, then set through Jellyfin's own password endpoint with the current one from the file; a refused change restores the file. Checked by a real login with the new pair. Jellyfin signs out the user's other sessions (`other_sessions`). Never printed: `password_file` and `password_line` say where it is. |
| config | `show [<field>]` \| `set <field> <value>` | yes (`show`: no) | Jellyfin's server configuration (`/System/Configuration`). `show` hides secret-named values and skips lists and objects; `set` changes one field, keeps its type, posts the rest back as it was and reads it back. Never set here: the login and its methods, the startup wizard, the network, the data folders, Jellyfin's internal flags. |

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
php $J login-check
php $J set-login
php $J config show
php $J config set MinResumePct 5
php $J stats --url http://127.0.0.1:8096/jellyfin
```

- Library arguments are an `id` or a `name` from `libraries`; session ids come from `sessions`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- `login` runs in the user's own terminal: `php $J login`.
