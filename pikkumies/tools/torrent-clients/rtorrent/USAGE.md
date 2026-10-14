# rtorrent

The user's own rTorrent through its SCGI socket (XML-RPC): `~/.rtorrent.socket` on PMSS, the socket or 127.0.0.1 port in the rtorrent rc file elsewhere. Same verbs, ids and records as the other torrent clients (`tools/torrent-clients/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--state <state>] [--search <text>] [--sort <field> [--reverse]] [--limit N]` | no | The torrents, one record each: `id`, `name`, `state`, `progress` (0..1), `size`, `done` (bytes), `ratio`, `down_rate`, `up_rate` (bytes/s), `added_at`, `label` (the ruTorrent label), `path` (the torrent's file or folder), `message` (tracker or error text), `completed_at`, `seeding_time` (always `null`: rTorrent does not count it), `private`, `uploaded` (bytes). `--sort name\|size\|progress\|ratio\|added_at\|completed_at\|uploaded\|state` orders them before the limit (largest or newest first; `--reverse` flips). |
| show | `<id>... [--files] [--trackers]` | no | The list record plus creation, start and finish times, totals, peers, seeds, file count, private flag and priority. `--files` adds every file and the delete preview (`expect`, `freed_bytes`, `refused`; files the delete will leave carry `skip`; a torrent the delete refuses is refused here too). `--trackers` adds one record per tracker: `tracker` (scheme, host and port only, never the passkey), `tier`, `status` (`working`, `failing`, `not contacted`, `disabled`), `seeds`, `leechers`; rTorrent has no message per tracker (see the torrent's `message`). |
| add | `<magnet\|url\|file.torrent>... [--paused] [--dir <path>]` | yes | Adds each item and prints its id and `result` (`added`, `already in rtorrent`, or `sent` while rTorrent still loads it). A .torrent file is sent as its contents; a URL is fetched by rTorrent. `--dir` must be an existing folder inside your home. Every item is checked before anything is sent. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes torrents out of rTorrent (`d.erase`) and keeps the files. With `--with-data` the tool deletes exactly the torrent's own files after rTorrent let go of them, only inside the torrent's folder and your home, never through a symlinked folder, then removes the folders it emptied (`disposal: deleted`, or `partial` with `orphans`). The size must match `--expect` exactly and no file may be shared with another torrent in rTorrent. Any `errors` end the call with `ok: false`. |
| pause | `<id>...` | yes | Pauses (`d.stop`, ruTorrent's pause) and prints each torrent's `state`, its `message` (tracker text, kept apart) and `result` read back; `verified` comes from the raw `d.state` / `d.is_active` (0 after a pause). |
| resume | `<id>...` | yes | Resumes (`d.start`) and prints each torrent's `state`, `message` and `result` read back; `verified` when `d.state` and `d.is_active` are 1. |
| stats | | no | rTorrent and libtorrent versions, pid, uptime (PMSS), listen port, the socket or port used, torrent counts by state, the user's free space from the quota (`quota_free_bytes`; `fs_free_bytes` is the whole filesystem), and the traffic fields. |
| traffic | | no | `down_rate`, `up_rate` (bytes/s), `down_total`, `up_total` (bytes since rTorrent started), `down_limit`, `up_limit` (bytes/s, 0 = no limit). |
| restart | | yes | PMSS only: refused while `~/.rtorrentDisable` exists (the user stopped rTorrent in the panel); otherwise the PMSS restart (`~/www/.rtorrentRestart` and `~/.rtorrentRestart.php`), then waits up to 30 s for an answer. Takes about 20 s. Elsewhere it refuses and prints the provider's way. |
| recheck | `<id>...` | yes | `d.check_hash` per torrent (ruTorrent's recheck), then each state read once right after the request (the status line says `read_back`: requested; state right after request, the client finishes in the background), and `done_before` (bytes it had). Deletes nothing; data that is gone downloads again. |
| reannounce | `<id>...` | yes | `d.tracker_announce` per torrent (ruTorrent's update trackers; not the forced one), then each state read once right after the request (the status line says `read_back`: requested; state right after request, the client finishes in the background), and `message` (the torrent's tracker text, `null` when there is none). |
| limit | `[--down <rate>] [--up <rate>]` | yes | No option: `down_limit`, `up_limit` (bytes/s, 0 = none). With options: sets rTorrent's global limits in whole KiB/s (1 to 1023 B/s is refused: rTorrent would read it as no limit), reads them back (`was`, `asked`, `verified`) and says `persists: false`: a restart brings back the rc file's limits. On PMSS the up limit can be the account's upload cap, which the rc file and the PMSS watchdog set again, so a changed up limit may not stay (the record's `note`). Rates: bytes/s, `512KiB/s`, `10MiB/s`; `0` = no limit. |

```sh
R=~/pikkumies/tools/torrent-clients/rtorrent/rtorrent.php
php $R list --state downloading
php $R list --search "ubuntu"
php $R show 3f2a9c1b --human
php $R add "magnet:?xt=urn:btih:..." --dir ~/data/linux
php $R add ~/new.torrent --paused
php $R pause 3f2a9c1b 77d0e2aa
php $R show 3f2a9c1b0d6e... --files          # show the user the files and the expect value
php $R remove 3f2a9c1b0d6e... --with-data --expect 41.2GiB:44236814336
php $R stats --human
php $R list --sort size --limit 10
php $R list --sort completed_at --reverse
php $R show 3f2a9c1b --trackers
php $R recheck 3f2a9c1b
php $R reannounce 3f2a9c1b 77d0e2aa
php $R limit
php $R limit --down 0 --up 10MiB/s
```

- Ids are the 40-character ids `list` prints (rTorrent's info-hash, lower case). To find one by name: `list --search <text>`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- rTorrent's SCGI socket needs no password; the tool uses it only when the socket (or port) belongs to your own account.
