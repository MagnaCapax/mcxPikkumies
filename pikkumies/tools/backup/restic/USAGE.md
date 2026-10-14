# restic

Snapshots in the user's own restic repository. Same verbs, ids and records as `borg` (`tools/backup/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| login | `--repo <repository>` | yes | Reads the repository password at a terminal prompt, stores it in `local/secrets/restic` (0600) and the repository in `local/backup/restic.json`, checked with `restic cat config` (`verified`). The typed password is written beside the stored one and checked first: a refused password, or one that cannot be checked while a login is stored, changes nothing; an accepted one replaces the stored login and a different old password is kept as `<file>.prev-<time>` (0600). |
| list | `[--path P] [--limit N]` | no | Snapshots, newest first: `id` (short id), `time`, `host`, `paths`, `tags`, `snapshot_id`. `--path` keeps those that include exactly that path. |
| show | `<id> [--files [path]] [--prune-plan --keep-... ]` | no | One snapshot (`username`, `excludes` added); `--files [path]` lists up to `--limit` files (`path`, `type`, `bytes`, `modified_at`); `--prune-plan` lists the snapshots `prune` would remove, with `expect`. |
| stats | | no | `version`, `repo`, `logged_in`, `reachable`, `snapshots`, `latest_at`, `locks` (lock files in the repository). |
| check | `[--read-data] [--read-data-subset P]` | no | `restic check`; `--read-data-subset` (`n/m`; a percentage such as `5%` needs a newer restic: 0.14.0 takes it, 0.11.0 does not) or `--read-data` also reads stored data. Runs at nice 19 and idle I/O. `errors_found` is true only when restic reports "repository contains errors"; `finished` false means the check stopped early (a lock, the network, the login) and says nothing about damage. A percentage on restic before 0.12.0 is refused before restic runs (use `n/m`). Never `--repair`. |
| backup | `<path>...` | yes | `restic backup` at nice 19 and idle I/O; refuses while a restic or borg job of this account runs. Reports the new snapshot `id`, new and changed files, `added_bytes`, `incomplete` (some files unreadable). |
| restore | `<id> --target <new-dir> [--include /path]` | yes | `restic restore` into a new or empty directory inside the home; files land under their original full path inside it. Reads back how many entries arrived (`verified`). |
| prune | `--keep-... --expect <n>` | deletes | Rebuilds the plan, refuses unless `n` matches, then `restic forget <planned ids>` and `restic prune`. Asks the user first; reads back that the snapshots are gone (`verified`). |

```sh
php ~/pikkumies/tools/backup/restic/restic.php login --repo sftp:me@storagebox:restic-repo
php ~/pikkumies/tools/backup/restic/restic.php stats --human
php ~/pikkumies/tools/backup/restic/restic.php list --limit 10 --human
php ~/pikkumies/tools/backup/restic/restic.php show 4f1c2a9b --files /home/me/data
php ~/pikkumies/tools/backup/restic/restic.php check
php ~/pikkumies/tools/backup/restic/restic.php check --read-data-subset 5%
php ~/pikkumies/tools/backup/restic/restic.php backup ~/data/photos ~/.config
php ~/pikkumies/tools/backup/restic/restic.php restore 4f1c2a9b --target ~/restore-1014 --include /home/me/data/photos
php ~/pikkumies/tools/backup/restic/restic.php show --prune-plan --keep-daily 7 --keep-weekly 4
php ~/pikkumies/tools/backup/restic/restic.php prune --keep-daily 7 --keep-weekly 4 --expect 3
```

- `login` must be run by the user in a terminal; from the agent it refuses (stdin is not a terminal).
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
