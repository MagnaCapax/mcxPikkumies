# plex

The user's own Plex Media Server through its local HTTP API (JSON). Same verbs and records as the other media servers (`tools/media-servers/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | | no | `version`, `server_name`, `found` (docker, url), `url`, `listen`, `libraries`, `streams`, `transcodes`, `hardware_acceleration` (the server's own settings whose id mentions hardware), `update_available` (always null: not read), `login_from`. No `items` or `item_counts`: each library's count is in `libraries`. When Plex refuses `/status/sessions`, `streams` and `transcodes` are null and `note` says why; the rest still prints. |
| libraries | | no | One record per library section: `id` (the section key), `name`, `type`, `paths`, `items`, `scanning`, `progress` (null). |
| scan | `<library>... \| --all` | yes | Starts a scan of each named section (no `force`). `result`: `started` or `already scanning`. |
| sessions | `[--addresses]` | no | What is playing: `id` (Plex's `Session.id`; a row without one, such as a client that only reports its timeline, gets its `sessionKey`), `user`, `client`, `device`, `title`, `progress`, `paused`, `play`, `hardware`, `remote`; `address` and `public_address` only with `--addresses`. |
| end-stream | `<session-id>... [--reason <text>]` | yes | Asks Plex to terminate each session, with the reason shown to the viewer; `verified` when it left the list. Plex may refuse (HTTP 401, its feature gate); nothing is ended then and the hint says to end it from the Plex dashboard. Two rows with the same id are refused, never guessed. A failed stop, or a session that still plays after it (the list is read up to 5 times, 0.4 s apart; a client may ignore the command), ends the call `ok:false` with each id's result. |
| recent | `[--limit N]` | no | Recently added items: `title`, `type`, `library`, `added_at`. When truncated, `total` is Plex's own `totalSize`. |
| logs | `[--lines N] [--log <name>] [--addresses]` | no | `file` and its last `lines` (default 50): `Plex Media Server.log`, or the log `--log` names (e.g. `"Plex Media Scanner"`, `.log` optional), in the `Logs/` folder of the data directory: the container's `/config` mount, else `$PLEX_MEDIA_SERVER_APPLICATION_SUPPORT_DIR`, `~/Library/Application Support` and `~/docker/plex/config` (each plus `Plex Media Server`). A name that is not there lists the logs that are; no log anywhere names every folder tried. |
| restart | | yes | A container on the user's own rootless Docker: `docker restart`, then waits up to 90 s for an answer. Elsewhere (`--url`) it refuses and prints the provider's way: Plex has no PMSS media-stack install. |
| login | | yes | The user types a Plex token at a terminal; it is checked once and stored in `local/secrets/plex`. `verified:false` with a note when Plex answers the same read without any token (an allowed network or an unclaimed server): the check then proves nothing. |

```sh
P=~/pikkumies/tools/media-servers/plex/plex.php
php $P stats --human
php $P libraries
php $P scan 1
php $P scan --all
php $P sessions
php $P end-stream 7k2m9xq4rt5b --reason "Server maintenance"
php $P recent --limit 10
php $P logs --lines 100
php $P logs --log "Plex Media Scanner"
php $P restart
php $P stats --url http://127.0.0.1:32400
```

- Library arguments are a section `id` or a `name` from `libraries`; session ids come from `sessions`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- `login` runs in the user's own terminal: `php $P login`.
