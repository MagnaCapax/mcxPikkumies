# torrent-clients

Tools for the user's own torrent clients: `rtorrent`, `qbittorrent` and `deluge`, one unit each (ADR-0015). Every unit has the same verbs, arguments and record fields, so the agent learns them once; `lib/` holds the shared code and the contract test checks every unit against the list below.

## Verbs (the same in every unit)

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--state <state>] [--search <text>] [--sort <field> [--reverse]] [--limit N]` | no | The client's torrents, one record each. `--sort` before the limit: `name`, `size`, `progress`, `ratio`, `added_at`, `completed_at`, `uploaded`, `state` (numbers and times largest first, name and state A to Z; `--reverse` flips). |
| show | `<id>... [--files] [--trackers]` | no | One torrent's details; `--files` adds its files and the delete preview (`expect`, `freed_bytes`, `shared_with`, `skip`, `same_as`, `unchecked`); `--trackers` adds one record per tracker (host only, see Trackers). |
| add | `<magnet\|url\|file.torrent>... [--paused] [--dir <path>]` | yes | Adds torrents and reports their ids. `--dir` must be an existing folder inside the home, for every client. |
| remove | `<id>... [--with-data --expect <size>]` | yes | Takes torrents out of the client and keeps the files. With `--with-data` the files are deleted too (asks the user first). |
| pause | `<id>...` | yes | Pauses (qBittorrent 5: stops) torrents. |
| resume | `<id>...` | yes | Resumes (qBittorrent 5: starts) torrents. |
| stats | | no | Everything about the client: version, process, port, torrent counts by state, traffic, and the user's free space from the quota (`quota_free_bytes`; `fs_free_bytes` is the whole filesystem). |
| traffic | | no | Current rates, totals since start, speed limits. |
| restart | | yes | Restarts the client the way this provider does it, then checks it answers. |
| recheck | `<id>...` | yes | Asks the client to hash-check the torrents' data again. Deletes nothing; data that is gone becomes missing and downloads again (`done_before` says what was there). |
| reannounce | `<id>...` | yes | Asks the client to contact the torrents' trackers now (after a tracker error or outage). |
| limit | `[--down <rate>] [--up <rate>]` | yes | No option: the global speed limits (`down_limit`, `up_limit`, bytes/s, 0 = none, the same fields as `traffic`). With options: sets them and reads them back (`was`, `asked`, `verified`). A rate is bytes/s or `512KiB/s`, `10MiB/s`; `0` = no limit. |

The eight functions the operator named (list, details, add, remove, pause, full stats, traffic, restart) map to `list, show, add, remove, pause, stats, traffic, restart`; `resume` is the first addition (the undo of pause). `recheck`, `reannounce`, `limit`, `show --trackers` and `list --sort` came from a review of 100 candidate verbs as the most common needs; none deletes data, so none asks the user first.

## Ids

- An id is the client's own 40-character hex id, exactly as `list` prints it (lower case). It is not a shared info-hash: for v2 and hybrid torrents qBittorrent uses a truncated v2 hash.
- `show`, `pause`, `resume`, `recheck`, `reannounce`: a full id or a unique prefix of at least 8 characters. `remove`: full ids only.
- Several ids per call. Every id is resolved against the live list first; one unknown, ambiguous or malformed id fails the whole call and nothing changes.
- Names are never ids. To find a torrent by name: `list --search <text>`.
- No "all": a verb never acts on every torrent; the client's own "all" value is never sent.

## Records

`list` and `show` records start with `id`, then `name`, `state`, `progress` (0..1), `size` (bytes), `done` (bytes), `ratio`, `down_rate` and `up_rate` (bytes/s), `added_at` (unix time), `label`, `path` (where the data is), `message` (tracker or error text, when any), `completed_at` (unix time the download finished), `seeding_time` (seconds seeded), `private` (from a private tracker), `uploaded` (bytes, all time). A field the client does not give is `null`, never a guess: rTorrent has no seeding-time counter, so its `seeding_time` is always `null` (time since finishing would count days it was stopped); qBittorrent before 5.0 has no `private` in its list, and before API 2.8.1 no `seeding_time`.

`state` is one of: `downloading`, `seeding`, `paused`, `checking`, `queued`, `error`, `stalled`, `unknown`. Every client's own words map to these (qBittorrent `pausedUP` and `stoppedUP`, Deluge `Paused`, rTorrent closed or stopped: all `paused`).

Passkeys and tokens in tracker URLs are removed from all output (lib/Cli.php).

## Trackers

`show <id>... --trackers` adds one record per tracker: `torrent` (its id), `tracker`, `tier`, `status`, `message`, `seeds`, `leechers` (peers still downloading).

- `tracker` is only the scheme, host and port (`https://tracker.example:443`), never the path or the query. Private trackers put the passkey there under many names, and a list of names to remove can always miss one; a host label that looks like a key (16 or more letters and digits with a digit) is removed too (`lib/TorrentTrackers.php`). The plain `show` prints no tracker URLs at all.
- `status` is one of `working`, `failing`, `not contacted`, `updating`, `disabled`, or `null` when the client gives none per tracker (Deluge).
- `message` is per tracker in qBittorrent only; a word in it that looks like a key (16 or more letters and digits with a digit) is removed, since a tracker may echo the passkey in prose. rTorrent and Deluge keep one message per torrent: the torrent record's `message` (and Deluge's `tracker_status` in `show`). `seeds` and `leechers` are what the tracker reported, `null` when unknown.

## Speed limits

`limit` changes the client's own global limits, which the user can also change in its web UI. It makes one change and reads it back; it never repeats it. The record has the limits read back, `was` (to undo the change), `asked`, and per client: rTorrent `persists: false` (the running client only; a restart brings back the rc file's limits) and a `note`, which on PMSS says the up limit can be the account's upload cap that the PMSS watchdog sets again; qBittorrent `alt_speed` (when alternative limits are on, those are the ones changed). All three clients keep whole KiB/s, so the read-back can differ from the request by less than 1 KiB/s; `verified` allows that. A non-zero rate under 1 B/s is refused (it would round to 0, no limit), and so is 1 to 1023 B/s on rTorrent, which would read it as 0. `limit` takes options only: `limit 10MiB/s` is refused, never read as a request. A provider may set its own upload cap again later; `limit` reports what the client says now.

## Deleting data

1. `show <id>... --files` lists the files, their sizes, which other torrents in the same client share them, and the `--expect` value: the total as people read it and the exact byte count, e.g. `41.2GiB:44236814336`. A file the unit will not delete (rTorrent: behind a symlinked folder) is listed with `skip` and left out of the totals; a torrent the delete would refuse is refused here too. A second path of the batch to a file already listed (a hard link, or the same file through another torrent of the batch) is listed with `same_as`: it is deleted too and counted once.
2. `remove <id>... --with-data --expect <that value>` deletes, in one call and one approval: the tool recomputes the plan and refuses when the exact byte total differs (a bare rounded size like `41.2GiB` is refused too), or when a file is shared with a torrent outside the batch in the same client. A file is the same file when it has the same device and inode: another spelling, a symlinked folder, a symlink or a hard link to it all count. Every other torrent in the client is compared, wherever its folder is, so a cross-seed in another folder whose files are symlinks or hard links to these is found. Their file lists are read in as few calls as the client allows: one call for all of them in Deluge, one per torrent in rTorrent and qBittorrent, whose APIs have no call for several torrents' files. When the client does not list some torrent's files, `show` names it in `unchecked` and `--with-data` is refused until it can be listed. Records say `disposal` (`deleted`, `trashed`, or `partial` when planned files are still on disk; qBittorrent 5 moves to the trash by default) and `freed_bytes` (only files that are gone, counted once per inode: a file whose hard links are all in the batch frees its space once; one with a hard link outside the batch frees nothing). Any error, such as a torrent still in the client or files left behind, ends the call with `ok: false` and a message that says what happened to each torrent.

The shared-file check sees only the torrents of the client being called: when two clients seed the same files (for example rTorrent and qBittorrent pointed at the same `~/data` folder), check the other client before deleting with data.

## Where each client is found

| client | PMSS | other providers |
|---|---|---|
| rtorrent | SCGI socket `~/.rtorrent.socket` | the `network.scgi.open_local` or `scgi_local` / `scgi_port` line in the rtorrent rc file |
| qbittorrent | WebUI on 127.0.0.1, port in `~/.qbittorrentPort`; password set once by the user with `login` | `WebUI\Port` in `~/.config/qBittorrent/qBittorrent.conf` |
| deluge | deluge-web on 127.0.0.1, port in `~/.delugeWebPort`; secret from `~/.config/deluge/auth` | `port` in `~/.config/deluge/web.conf` |

A client on a port owned by another account is never contacted (ADR-0019). When a client cannot be reached, the error says which: not installed, not enabled, not running, busy (no answer in time: unknown, not down), refused, or the port belongs to another account.
