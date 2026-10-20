# move

Moves torrents with their data from another of the user's boxes to this one, for balancing space or upload between seedboxes. The other box is a service in the services list (`tools/seedbox/services`); on it, the forced-command `serve` answers the client units' verbs and read-only rsync pulls.

## How a move runs

1. `plan` reads the source through its client units: `show` or `list --search` to find each torrent, `export <id> base64` for its .torrent (`Export.php` in each client unit; the base64 form is for this tool only, the plain `export` saves a 0600 file and prints no bytes; `lib/TorrentExport.php` recomputes the info-hash and refuses a file that does not match), `show --files` for its files and the delete preview's `expect`. Every file must sit under one folder of the source home as the .torrent names it, at its size. The plan and the .torrent files are kept in `local/state/moves/` (0700; files 0600; .torrent bytes are never printed or logged).
2. `run` works on the receiving box only, step by step, each step a line in `local/state/moves/<move>.jsonl` and marked done in `<move>.json`, so a second `run` continues:
   - **copy**: `rsync -rt --partial --files-from=- --from0 --timeout=300 -e '<the service's ssh>' <host>:. <folder>/.pikkumies-move-<move>-<n>/`, the move's own staging folder; `-rt` copies files and times, never the source's symlinks, permissions or device files. The file list comes from the saved .torrent, never from the move state. Then each file is linked into place (`link()` fails if the name exists) and the staged name removed: nothing that is not this move's own is overwritten. The target folder must be inside a data folder of the client here (first-level folders of the home where it keeps data, and `~/data`), never the home, a dot-folder or `~/pikkumies`; a name starting with a dot in the .torrent is refused. The remote side sees only `.`; each file goes over stdin as `<folder>/./<path>`, NUL-separated, so no file name is ever in an argv and the part after `/./` lands under the target folder (rsync's `--relative` dot-dir rule). Never `--delete`. After a check below 100 % the next copy adds `-c`. The free quota here must hold what is left plus 5 %.
   - **add**: the client unit's `add <file>.torrent --paused --dir <folder>`. A cross-client move works because the .torrent is the same for every client; qBittorrent lists a v2 or hybrid torrent under its cut v2 hash, which move computes.
   - **recheck**: the client unit's `recheck`, then `show` until the check is done: 100 % goes on, less stops with the source kept. The wait is 300 s plus the size at 20 MiB/s.
   - **resume**: `resume`, then up to two minutes for `seeding` at 100 % under the expected id.
3. `--free-source` (with `--ids`, `--expect` and `--intent`; `--ids` puts the torrents on the command line the delete review reads): see USAGE.md. The order is the point: the torrent seeds here before the source is touched, so a private tracker never sees a gap; and the delete is decided from live answers, because the state file can be edited by the agent's own account.

## Limits

- One source client per move. Moves within one box are the client units' job (`add`, `remove`, and `repath` where it exists).
- The label or category is reported, not set: the client units' `add` takes none.
- A qBittorrent older than 4.5 has no `torrents/export`; its saved `BT_backup` file may hold no tracker, and move refuses a private torrent without one.
- A torrent whose files were renamed in the client, or a v2-only torrent, is refused at `plan`.
