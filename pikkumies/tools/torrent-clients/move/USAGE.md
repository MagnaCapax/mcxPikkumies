# move

Moves torrents and their data from another box in the services list to this box, the box that receives them. The source is touched only after the torrent seeds here, and only on request.

| verb | arguments | changes | what it does |
|---|---|---|---|
| plan | `<id\|name>... --from <service> [--client <c>] [--dir <folder>] [--to local]` | no | Finds each torrent on the source (an id, an 8+ hex prefix, an exact name or a part of one; exactly one match, else the candidates), exports and checks its .torrent, checks its files are laid out as named and complete, picks the client here (`--client`, else the same kind, else the first that answers), the folder here (`--dir`, else the source's folder under this home; always inside a data folder of the client here, never the home itself, a dot-folder or `~/pikkumies`), refuses when any of its files already exists there, checks the free quota here is at least the data plus 5 %, and saves the move. Records: `id`, `name`, `bytes`, `from`, `to`, `target_dir`, `label`, `private`; status: `move`, `bytes`, `free_here`, `expect`. One source client per move. `--to <service>` (not this box) changes nothing and prints the command for that box. |
| run | `<move> [--free-source --ids <id,id...> --expect <size> --intent "<the user's own words>"]` | deletes | Continues the move at its first unfinished step, per torrent: `export`, `copy` (rsync pull with `-rt --partial` into the move's own staging folder, never `--delete`; the file list comes from the saved .torrent; each file is then put in place without overwriting anything that is not this move's own), `add` (paused, into the folder), `recheck` (until 100 %; less stops and keeps the source), `resume` (seeding, same id). Without the four options it stops there: seeding on both boxes. With them (`--ids` exactly the move's torrent ids, in order, as `status` and the run's `next` print them), after every torrent seeds here: the source's live total must equal `--expect`, the words go to `local/state/intents.jsonl`, this box is checked live again, then the source's own unit deletes (`remove --with-data --expect`) when its key allows deletes; otherwise nothing changes there and the record gives the command for that box. |
| status | `[<move>]` | no | Each torrent of the move (the newest without an id): `done` steps, `next` step; status: `from`, `to`, `expect`, `last` log line. |
| list | | no | Every move on this box, newest first: `move`, `created_at`, `from`, `to`, `torrents`, `bytes`, `next`. |

```sh
M=~/pikkumies/tools/torrent-clients/move/move.php
php $M plan 3f2a9c1b0d6e4f5a6b7c8d9e0f1a2b3c4d5e6f7a --from hdd1
php $M plan "Debian 13 netinst" 77d0e2aa --from hdd1 --client qbittorrent --dir ~/data/linux
php $M status 20261020-101500-a1b2c3 --human
php $M list
php $M run 20261020-101500-a1b2c3 --free-source --ids 3f2a9c1b0d6e4f5a6b7c8d9e0f1a2b3c4d5e6f7a --expect 41.2GiB:44236814336 --intent "move them to the new box and delete them from hdd1"
```

The plain run, which copies and starts the torrents here and leaves the source as it is, is `php $M run 20261020-101500-a1b2c3`; run it again after any stop.

- Run it on the receiving box. On the source box `plan --to <nick>` prints the command for the other box.
- The move state in `local/state/moves/` records what was planned and done; it never authorizes a delete: `--free-source` reads both boxes live.
- Refused: a .torrent with a file or folder name starting with a dot, and a target file that already exists (nothing is ever overwritten).
- Not yet: v2-only torrents, files renamed inside a torrent, a source without Pikkumies (third-party boxes), a Transmission source (no `export`), carrying the label or category.
