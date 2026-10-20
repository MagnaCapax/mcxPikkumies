# borg

Archives in the user's own borg repository. Same verbs, ids and records as `restic` (`tools/backup/README.md`); the id of an archive is its name.

| verb | arguments | changes | what it does |
|---|---|---|---|
| login | `--repo <repository>` | yes | Reads the repository passphrase at a terminal prompt, stores it in `local/secrets/borg` (0600) and the repository in `local/backup/borg.json`, checked with `borg info` (`verified`). The typed password is written beside the stored one and checked first: a refused password, or one that cannot be checked while a login is stored, changes nothing; an accepted one replaces the stored login and a different old password is kept as `<file>.prev-<time>` (0600). |
| list | `[--path P] [--limit N]` | no | Archives, newest first: `id` (the name), `time`, `snapshot_id` (archive id). borg's list has no paths, so `--path` matches nothing. |
| show | `<id> [--files [path]] [--prune-plan --keep-... ]` | no | One archive (`borg info`: `host`, `end` (unix), `duration`, `stats`, `username`; never its command line); `--files [path]` lists up to `--limit` files (`path`, `type`: `file`/`dir`/`symlink`/..., `bytes`, `modified_at`; the second name of a hard link has `bytes` null, `hardlink: true` and `link_to`), paged with `--offset`; `--prune-plan` lists the archives `prune` would remove, with `expect`. |
| stats | | no | `version`, `compact_supported` (borg 1.2+), `repo`, `borgmatic_configs` (paths only), `logged_in`, `reachable`, `archives`, `latest_at`. |
| check | `[--read-data] [--read-data-subset P]` | no | `borg check`; `--read-data` adds `--verify-data` (reads everything). borg has no sampled check, so `--read-data-subset` is refused. Runs at nice 19 and idle I/O. `errors_found` is borg exit 1 (the check found problems); exit 2 is `finished` false (lock, connection, key: nothing said about damage). Never `--repair`. |
| backup | `<path>...` | yes | `borg create ::<host>-<UTC time>Z` (e.g. `myhost-2026-10-14T12:25:58.123Z`: to the millisecond, so two runs in one second never collide) at nice 19 and idle I/O; refuses while a restic or borg job of this account runs. Reports the archive name and its stats. |
| restore | `<id> --target <new-dir> [--include /path]` | yes | `borg extract` run inside a new or empty directory inside the home; files land under their stored path. Reads back how many entries arrived (`verified`). |
| prune | `--keep-... --expect <n>` | deletes | Rebuilds the plan (`borg prune --dry-run --list`), refuses unless `n` matches, then `borg delete` of exactly the planned archives and `borg compact` on 1.2+. The safety check decides (doctrine/safety.md); reads back that they are gone (`verified`). |

```sh
php ~/pikkumies/tools/backup/borg/borg.php login --repo ssh://me@storagebox/./borg-repo
php ~/pikkumies/tools/backup/borg/borg.php stats --human
php ~/pikkumies/tools/backup/borg/borg.php list --limit 10 --human
php ~/pikkumies/tools/backup/borg/borg.php show myhost-2026-10-01T03:00:00 --files /home/me/data
php ~/pikkumies/tools/backup/borg/borg.php check
php ~/pikkumies/tools/backup/borg/borg.php backup ~/data/photos
php ~/pikkumies/tools/backup/borg/borg.php restore myhost-2026-10-01T03:00:00 --target ~/restore-1014 --include /home/me/data/photos
php ~/pikkumies/tools/backup/borg/borg.php show --prune-plan --keep-daily 7 --keep-weekly 4
php ~/pikkumies/tools/backup/borg/borg.php prune --keep-daily 7 --keep-weekly 4 --expect 2
```

- `login` must be run by the user in a terminal; from the agent it refuses (stdin is not a terminal).
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
