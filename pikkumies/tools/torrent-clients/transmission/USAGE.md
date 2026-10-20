# transmission

The user's own Transmission through its JSON RPC (a unix socket on PMSS, `~/.lighttpd/transmission.sock`; a TCP port elsewhere, from `~/.config/transmission-daemon/settings.json`). Same verbs, ids and records as the other torrent clients (`tools/torrent-clients/README.md`), plus `repath` and `export`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--state <state>] [--search <text>] [--sort <field> [--reverse]] [--limit N]` | no | The torrents, one record each: `id`, `name`, `state`, `progress` (0..1), `size`, `done` (bytes, `sizeWhenDone` minus `leftUntilDone`), `ratio`, `down_rate`, `up_rate` (bytes/s), `added_at`, `label` (Transmission's `labels`, joined with `,`), `path` (the download folder), `message` (set when `error` is nonzero), `completed_at`, `seeding_time` (s), `private`, `uploaded` (bytes). `--sort name\|size\|progress\|ratio\|added_at\|completed_at\|uploaded\|state` orders them before the limit (largest or newest first; `--reverse` flips). |
| show | `<id>... [--files] [--trackers]` | no | The list record plus `seeds`/`peers` (this torrent's connected peers, split by whether each reports 100% progress), `swarm_seeds`/`swarm_peers` (the trackers' own counts, `null` when none reported), `eta`, `uploaded_bytes`, `total_size`, `file_count`. `--files` adds every file and the delete preview (`expect`, `freed_bytes`, `refused`). `--trackers` adds one record per tracker: `tracker` (scheme, host and port only, never the passkey), `tier`, `status` (approximated from Transmission's own flags: see README.md), `message`, `seeds`, `leechers`. |
| add | `<magnet\|url\|file.torrent>... [--paused] [--dir <path>]` | yes | Adds each item and prints its id, name and `result` (`added` once listed, `already in the client` when Transmission has it, `sent, not listed yet`, or `failed` with the reason); with `--paused` also `paused` (read back). A URL is fetched by Transmission itself. `--dir` must be an existing folder inside your home. |
| remove | `<id>... [--with-data --expect <size>]` | deletes | Takes torrents out of Transmission and keeps the files. With `--with-data` Transmission deletes the files for good (no trash, `disposal: deleted`); the tool first checks the exact size against `--expect` and that no other torrent in this Transmission uses the files, then checks the files are gone (`partial` and `ok: false` when files stay). |
| pause | `<id>...` | yes | Stops the torrents (`torrent-stop`) and reads their state back (`verified`). |
| resume | `<id>...` | yes | Starts them (`torrent-start`) and reads their state back. |
| stats | | no | Transmission and RPC versions, pid and uptime (`null` when the process is not found), the RPC endpoint and where it was found, torrent counts by state, the user's free space from the quota (`quota_free_bytes`; `fs_free_bytes` is the whole filesystem), and the traffic fields. |
| traffic | | no | `down_rate`, `up_rate` (bytes/s), `down_total`, `up_total` (bytes since the daemon started), `down_limit`, `up_limit` (bytes/s, 0 = no limit, from the matching `speed-limit-*-enabled` flag). |
| restart | | yes | PMSS, only while a Transmission build is selected (`~/bin/transmission/default`): stops this user's own `transmission-daemon` (SIGTERM, SIGKILL after 3 s), runs the account's own launcher (`~/www/torrentClientsResolve.php transmission --launch`), waits up to 30 s for an answer. Elsewhere it refuses and prints the provider's way (none researched yet for Transmission). |
| recheck | `<id>...` | yes | `torrent-verify` for the named torrents, then each state read once right after the request (the status line says `read_back`: requested; state right after request, the client finishes in the background), and `done_before` (bytes it had). Deletes nothing; data that is gone downloads again. |
| reannounce | `<id>...` | yes | `torrent-reannounce` for the named torrents, then each state read once right after the request, and `message`: the torrent's own message, else what its trackers that are not working say (`host: text`), else `null`. |
| limit | `[--down <rate>] [--up <rate>]` | yes | No option: `down_limit`, `up_limit` (bytes/s, 0 = none). With options: sets Transmission's global limits (`session-set`, kept in KBps), reads them back (`was`, `asked`, `verified`). Rates: bytes/s, `512KiB/s`, `10MiB/s`; `0` = no limit. |
| repath | `<id>... --to <dir>` | yes | Repoints the torrent's record to data already at `<dir>` (`torrent-set-location` with `move: false`, then `torrent-verify`). Refuses, changing nothing, unless every one of the torrent's files already exists at `<dir>` with the exact size `show --files` would print. `<dir>` must be an existing folder inside your home. This never moves bytes: copy or link the files there first. |
| export | `<id>` | no | Prints the torrent's saved `.torrent` file as base64 (`torrent_base64`), read from Transmission's own on-disk copy (`torrentFile`). Refuses when there is none yet (a magnet-only torrent before its metadata arrives has no `.torrent` file to read). |
| login | | yes | Only needed when settings.json has `rpc-authentication-required: true` (PMSS's own unix socket never does). Run by the user in a terminal: reads the RPC password without echo, tries it once, and keeps it for this tool in `pikkumies/local/secrets/transmission` (mode 0600). Nothing is kept when Transmission rejects it. Refuses when not run in a terminal. |

```sh
T=~/pikkumies/tools/torrent-clients/transmission/transmission.php
php $T list --state downloading
php $T list --search "ubuntu"
php $T show 3f2a9c1b --human
php $T add "magnet:?xt=urn:btih:..." --dir ~/data/linux
php $T add ~/new.torrent --paused
php $T pause 3f2a9c1b 77d0e2aa
php $T show 3f2a9c1b0d6e... --files          # show the user the files and the expect value
php $T remove 3f2a9c1b0d6e... --with-data --expect 41.2GiB:44236814336
php $T stats --human
php $T list --sort size --limit 10
php $T list --sort completed_at --reverse
php $T show 3f2a9c1b --trackers
php $T recheck 3f2a9c1b
php $T reannounce 3f2a9c1b 77d0e2aa
php $T limit
php $T limit --down 0 --up 10MiB/s
php $T repath 0123456789abcdef0123456789abcdef01234567 --to ~/data/moved
php $T export 0123456789abcdef0123456789abcdef01234567
php $T login                                 # the user, once, in a terminal -- only if RPC authentication is on
```

- Ids are the 40-hex ids `list` prints (Transmission's own integer ids are not stable across restarts, so this unit never uses them; `hashString` is). `show`, `pause`, `resume`, `recheck`, `reannounce` take an 8+ character prefix; `remove`, `repath` and `export` take full ids only.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- Authentication is never turned off by this tool, and it never suggests turning it off: when `rpc-authentication-required` is true and no credential is stored yet, every verb refuses with a hint to run `login`, never to disable authentication. PMSS's own unix socket has authentication off by design, so `login` is never needed there.
