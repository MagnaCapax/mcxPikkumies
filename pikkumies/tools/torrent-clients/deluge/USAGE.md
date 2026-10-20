# deluge

The user's own Deluge 2.x through deluge-web's JSON-RPC on 127.0.0.1. Same verbs, ids and records as the other torrent clients (`tools/torrent-clients/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--state <state>] [--search <text>] [--sort <field> [--reverse]] [--limit N]` | no | The torrents, one record each: `id`, `name`, `state`, `progress` (0..1), `size`, `done` (bytes), `ratio`, `down_rate`, `up_rate` (bytes/s), `added_at`, `label` (always empty), `path` (the download folder), `message`, `completed_at`, `seeding_time` (s), `private`, `uploaded` (bytes). `--sort name\|size\|progress\|ratio\|added_at\|completed_at\|uploaded\|state` orders them before the limit (largest or newest first; `--reverse` flips). |
| show | `<id>... [--files] [--trackers]` | no | The list record plus tracker host, tracker status, seeds, peers, eta, uploaded bytes and Deluge's own state word (`client_state`). `--files` adds every file and the delete preview (`expect`, `freed_bytes`, `refused`). `--trackers` adds one record per tracker: `tracker` (scheme, host and port only, never the passkey) and `tier`; Deluge gives no status, message or counts per tracker (`null`). |
| add | `<magnet\|url\|file.torrent>... [--paused] [--dir <path>]` | yes | Adds each item and prints its id, the torrent's `name` as Deluge lists it, and `result` (`added` once listed, `already in the client` when Deluge has it, `sent, not listed yet`, or `failed` with the reason); with `--paused` also `paused` (read back). The status line says `verified` when every item is listed (and paused if asked). A URL is downloaded by Deluge itself. `--dir` must be an existing folder inside your home. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes torrents out of Deluge and keeps the files. With `--with-data` Deluge deletes the files for good (`disposal: deleted`); the tool first checks the exact size against `--expect` and that no other torrent in this Deluge uses the files, then checks the files are gone (`partial` and `ok: false` when files stay). |
| pause | `<id>...` | yes | Pauses the torrents and reads their state back (`verified`). |
| resume | `<id>...` | yes | Resumes them and reads their state back. |
| stats | | no | Deluge and libtorrent versions, daemon pid and uptime, listen port, deluge-web port and where it was found, torrent counts by state, the user's free space from the quota (`quota_free_bytes`; `fs_free_bytes` is the whole filesystem), and the traffic fields. |
| traffic | | no | `down_rate`, `up_rate` (payload, bytes/s), `down_total`, `up_total` (bytes since the daemon started), `down_limit`, `up_limit` (bytes/s, 0 = no limit). |
| recheck | `<id>...` | yes | `core.force_recheck` for the named torrents, then each state read once right after the request (the status line says `read_back`: requested; state right after request, the client finishes in the background), and `done_before` (bytes it had). Deletes nothing; data that is gone downloads again. |
| reannounce | `<id>...` | yes | `core.force_reannounce` for the named torrents, then each state read once right after the request (the status line says `read_back`: requested; state right after request, the client finishes in the background), and `message` (the torrent's tracker text, `null` when there is none). |
| limit | `[--down <rate>] [--up <rate>]` | yes | No option: `down_limit`, `up_limit` (bytes/s, 0 = none). With options: sets Deluge's global limits (kept in KiB/s), reads them back (`was`, `asked`, `verified`). Rates: bytes/s, `512KiB/s`, `10MiB/s`; `0` = no limit. |
| export | `<id>` | no | Saves the torrent's .torrent file as `~/pikkumies/local/state/exports/<id>.torrent` (0600) and prints only `file` (that path), `id`, `name`, `info_hash`, `bytes`, `sha256`, `private`, `trackers`, `source`. Read from Deluge's `~/.config/deluge/state/<id>.torrent`; the info-hash is computed again from the file and must equal the id, or nothing is written. The file holds the tracker URLs with their passkeys: never print or paste it. |
| restart | | yes | PMSS only, and only while Deluge is enabled: stops this user's own `deluged` and `deluge-web` (SIGTERM, SIGKILL after 3 s), starts them the way the PMSS panel does, waits up to 30 s for an answer. Elsewhere it refuses and prints the provider's way. |
| repath | `<id>... --to <dir>` | yes | Points the torrent at a folder that already holds its data (checked file by file, exact size; refused otherwise). `--to` must already be a real folder inside your home. Deluge has no "move the pointer only" call and its on-disk state is never edited, so this re-adds instead, without moving any data itself: removes the torrent keeping its files, re-adds Deluge's own saved `.torrent` copy paused at the new `download_location`, rechecks, then resumes only when it was not paused before. A label (the Label plugin, not core) is not carried in v1. |

```sh
D=~/pikkumies/tools/torrent-clients/deluge/deluge.php
php $D list --state downloading
php $D list --search "ubuntu"
php $D show 3f2a9c1b --human
php $D add "magnet:?xt=urn:btih:..." --dir ~/data/linux
php $D pause 3f2a9c1b 77d0e2aa
php $D show 3f2a9c1b0d6e... --files          # the files and the expect value, said in your message
php $D remove 3f2a9c1b0d6e... --with-data --expect 41.2GiB:44236814336
php $D stats --human
php $D list --sort size --limit 10
php $D list --sort completed_at --reverse
php $D show 3f2a9c1b --trackers
php $D recheck 3f2a9c1b
php $D reannounce 3f2a9c1b 77d0e2aa
php $D limit
php $D limit --down 0 --up 10MiB/s
php $D repath 3f2a9c1b --to ~/data/moved     # the data must already be at ~/data/moved, every file, exact size
```

- Ids are the 40-character ids `list` prints. To find one by name: `list --search <text>`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The deluge-web secret is read from `~/.config/deluge/auth` and never printed.
