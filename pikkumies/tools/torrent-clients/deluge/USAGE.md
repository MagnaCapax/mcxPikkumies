# deluge

The user's own Deluge 2.x through deluge-web's JSON-RPC on 127.0.0.1. Same verbs, ids and records as the other torrent clients (`tools/torrent-clients/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--state <state>] [--search <text>] [--limit N]` | no | The torrents, one record each: `id`, `name`, `state`, `progress` (0..1), `size`, `done` (bytes), `ratio`, `down_rate`, `up_rate` (bytes/s), `added_at`, `label` (always empty), `path` (the download folder), `message`. |
| show | `<id>... [--files]` | no | The list record plus tracker, seeds, peers, eta, uploaded bytes and Deluge's own state word (`client_state`). `--files` adds every file and the delete preview (`expect`, `freed_bytes`, `refused`). |
| add | `<magnet\|url\|file.torrent>... [--paused] [--dir <path>]` | yes | Adds each item and prints its id, or `result: failed` with the reason. A URL is downloaded by Deluge itself. `--dir` must be an existing folder inside your home. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes torrents out of Deluge and keeps the files. With `--with-data` Deluge deletes the files for good (`disposal: deleted`); the tool first checks the exact size against `--expect` and that no other torrent in this Deluge uses the files, then checks the files are gone (`partial` and `ok: false` when files stay). |
| pause | `<id>...` | yes | Pauses the torrents and reads their state back (`verified`). |
| resume | `<id>...` | yes | Resumes them and reads their state back. |
| stats | | no | Deluge and libtorrent versions, daemon pid and uptime, listen port, deluge-web port and where it was found, torrent counts by state, the user's free space from the quota (`quota_free_bytes`; `fs_free_bytes` is the whole filesystem), and the traffic fields. |
| traffic | | no | `down_rate`, `up_rate` (payload, bytes/s), `down_total`, `up_total` (bytes since the daemon started), `down_limit`, `up_limit` (bytes/s, 0 = no limit). |
| restart | | yes | PMSS only, and only while Deluge is enabled: stops this user's own `deluged` and `deluge-web` (SIGTERM, SIGKILL after 3 s), starts them the way the PMSS panel does, waits up to 30 s for an answer. Elsewhere it refuses and prints the provider's way. |

```sh
D=~/pikkumies/tools/torrent-clients/deluge/deluge.php
php $D list --state downloading
php $D list --search "ubuntu"
php $D show 3f2a9c1b --human
php $D add "magnet:?xt=urn:btih:..." --dir ~/data/linux
php $D pause 3f2a9c1b 77d0e2aa
php $D show 3f2a9c1b0d6e... --files          # show the user the files and the expect value
php $D remove 3f2a9c1b0d6e... --with-data --expect 41.2GiB:44236814336
php $D stats --human
```

- Ids are the 40-character ids `list` prints. To find one by name: `list --search <text>`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The deluge-web secret is read from `~/.config/deluge/auth` and never printed.
