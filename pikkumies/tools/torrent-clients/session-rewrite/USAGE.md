# session-rewrite

Offline account-rename support for a STOPPED torrent client: rewrites the path prefix in its own session or
resume files, raw-preserving (`pikkumies/lib/Bencode.php`). Not one of the shared torrent-clients verbs
(`list`, `show`, ...): this tool has no client to list torrents from while it is stopped, which is the whole
point. The category contract test (`tests/ContractTest.php`) exempts this unit by name for that reason.

| verb | arguments | changes | what it does |
|---|---|---|---|
| rewrite | `--client rtorrent\|qbittorrent\|transmission --from <prefix> --to <prefix> [--apply]` | yes | Refuses while the named client is running (checked by process name, this account's own processes only, `lib/OwnProcs.php`). Without `--apply`: lists every session/resume file and key that would change (`file`, `keys`: `key`, `from`, `to`), and a file that is not a readable bencoded dictionary (`reason`). With `--apply`: backs the whole session directory up first (`local/state/backups/session-rewrite-<client>-<timestamp>.tar.gz`), then rewrites exactly the listed keys, byte for byte elsewhere; a torrent's `info` dictionary, where the file format ever carries one, is verified unchanged before anything is written. The status line adds `client`, `dir` (the resolved session directory), `files_scanned`, `files_changed`, `applied`, `backup` (the backup's path, or `null` when nothing changed or `--apply` was not given). `deluge` is refused: its on-disk state is a Python pickle, not bencode (use the live `deluge repath` instead, once Deluge is running again with the data already moved). |

## Which keys, and why only those

| client | file | keys rewritten | source |
|---|---|---|---|
| rtorrent | `<session dir>/<hash>.rtorrent` | `directory` | rakshasa/rtorrent tag v0.9.8, `src/core/download_store.cc` (`DownloadStore::save()` writes the "rtorrent" bencode sub-object to this file) and `src/core/download.cc` (`set_directory()` / `set_base_directory()`, the only path `insert_key()`). `d.directory` and `d.directory_base` are the same getter over one stored value (`CMD2_ON_FL(root_dir)` for both, `src/command_download.cc`), so there is no separate on-disk `directory_base` key. |
| qbittorrent | `BT_backup/<hash>.fastresume` | `save_path`, `qBt-savePath`, `qBt-downloadPath` (present only when the separate incomplete-downloads folder is configured) | qBittorrent master `src/base/bittorrent/bencoderesumedatastorage.cpp`, `Worker::store()`: the plain libtorrent `save_path` `lt::write_resume_data()` writes, plus qBittorrent's own two. No `info` key: that and the comment/creation-date are extracted to the separate `.torrent` file. |
| transmission | `<config>/resume/<name>.<16-hex>.resume` | `destination`, `incomplete-dir` (present only when that feature is on) | `libtransmission/resume.cc`, `save()`. No `info` or `info-hash` key in this file either. |
| deluge | refused | none | `torrents.state` is a Python pickle (`deluge/core/torrentmanager.py`), never bencode; this tool parses bencode only and never guesses at a pickle's bytes. |

A key that cannot be verified from source this way is never added to the list above and is never rewritten
-- it would be reported as a gap instead, not guessed at.

## Why this exists

Renaming a PMSS account's user name changes its home folder's path. A torrent client's OWN session state
(not the torrent data) still points at the old `/home/<old>/...` prefix until something rewrites it; without
that, the client cannot find its data again when it restarts under the new name. `rewrite` does exactly that
one thing, offline, for the files a live client would otherwise need to be running to change through its own
API -- which the client-specific `repath` verb in `rtorrent`, `qbittorrent` and `deluge` covers instead, for
a client that is already running and whose data already sits at the new path.

```sh
S=~/pikkumies/tools/torrent-clients/session-rewrite/session-rewrite.php
php $S rewrite --client rtorrent --from /home/oldname --to /home/newname
php $S rewrite --client rtorrent --from /home/oldname --to /home/newname --apply
php $S rewrite --client qbittorrent --from /home/oldname --to /home/newname --human
php $S rewrite --client transmission --from /home/oldname --to /home/newname
```

- Output is JSON Lines; the last line says `ok`, or gives `error`, `hint` and `stop`. `--human` prints the same for people.
- The client must be stopped before `--apply`; it is never stopped or started by this tool.
- `--to` is only the new path PREFIX in the session files -- it does not move any file. Copy or move the account's data to the new home first (separately), then `--apply`, then start the client again.
