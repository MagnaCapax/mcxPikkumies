# qbittorrent

The user's own qBittorrent through its WebUI API v2 on 127.0.0.1 (port from `~/.qbittorrentPort` on PMSS, else `WebUI\Port` in `~/.config/qBittorrent/qBittorrent.conf`). Same verbs, ids and records as the other torrent clients (`tools/torrent-clients/README.md`), plus `login`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--state <state>] [--search <text>] [--limit N]` | no | The torrents, one record each: `id`, `name`, `state`, `progress` (0..1), `size`, `done` (bytes), `ratio`, `down_rate`, `up_rate` (bytes/s), `added_at`, `label` (the category), `path` (the torrent's file or root folder), `message` (errors and missing files). |
| show | `<id>... [--files]` | no | The list record plus sizes, pieces, seeds and peers, totals, times, comment, `infohash_v1`/`infohash_v2` (qBittorrent 5) and the trackers (passkeys removed). `--files` adds every file and the delete preview (`expect`, `freed_bytes`, `refused`). |
| add | `<magnet\|url\|file.torrent>... [--paused] [--dir <path>]` | yes | Adds each item and prints its id and `result` (`added` once listed, `already in the client`, `sent` while a URL is still fetched, or `error: ...`). `--dir` must be an existing folder inside your home. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes torrents out of qBittorrent and keeps the files. With `--with-data` qBittorrent deletes the files: `disposal: trashed` when its setting is "move to trash" (the qBittorrent 5 default, no space freed), else `deleted`. The tool first checks the exact size against `--expect` and that no other torrent in this qBittorrent uses the files, then that the torrents and files are gone (`partial` and `ok: false` when files stay). |
| pause | `<id>...` | yes | Pauses (qBittorrent 5: stops) and prints each torrent's state read back (`verified`). |
| resume | `<id>...` | yes | Resumes (qBittorrent 5: starts) and prints each torrent's state read back. |
| stats | | no | qBittorrent and WebAPI versions, pid and uptime, WebUI and listen ports, torrent counts by state, default save path, the user's free space from the quota (`quota_free_bytes`; `fs_free_bytes` is the whole filesystem), `delete_mode` (`trash` or `delete`), a `warning` if the WebUI needs no login, and the traffic fields. |
| traffic | | no | `down_rate`, `up_rate` (bytes/s), `down_total`, `up_total` (bytes since qBittorrent started), `down_limit`, `up_limit` (bytes/s, 0 = no limit), `connection`, `dht_nodes`. |
| restart | | yes | PMSS, only while `~/.qbittorrentEnable` exists: stops this user's own `qbittorrent-nox` (SIGTERM, SIGKILL only after 10 s), starts `qbittorrent-nox -d` from the home folder as the panel does, waits up to 30 s for an answer. Ultra.cc: `app-qbittorrent restart`. Elsewhere it refuses and prints the provider's way. |
| login | | yes | Run by the user in a terminal: reads the WebUI password without echo, tries it once, and keeps it for this tool in `pikkumies/local/secrets/qbittorrent` (mode 0600). Nothing is kept when qBittorrent rejects it. Refuses when not run in a terminal. |

```sh
Q=~/pikkumies/tools/torrent-clients/qbittorrent/qbittorrent.php
php $Q login                                 # the user, once, in a terminal
php $Q list --state downloading
php $Q list --search "ubuntu"
php $Q show 3f2a9c1b --human
php $Q add "magnet:?xt=urn:btih:..." --dir ~/data/linux
php $Q add ~/new.torrent --paused
php $Q pause 3f2a9c1b 77d0e2aa
php $Q show 3f2a9c1b0d6e... --files          # show the user the files and the expect value
php $Q remove 3f2a9c1b0d6e... --with-data --expect 41.2GiB:44236814336
php $Q stats --human
```

- Ids are the 40-character ids `list` prints: qBittorrent's own id, which for v2 and hybrid torrents is the v2 info-hash cut to 40 characters. To find one by name: `list --search <text>`.
- One login per call. Once qBittorrent rejects the stored login, every call refuses until the user runs `login` again (5 failures ban the address for an hour).
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
