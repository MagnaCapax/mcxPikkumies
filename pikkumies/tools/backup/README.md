# backup

Tools for snapshot backups in the user's own repository: `restic` and `borg`, one unit each (ADR-0015). Both have the same verbs, arguments and record fields, so the agent learns them once; `lib/` holds the shared code and the contract test checks every unit against the table below.

## Verbs (the same in every unit)

| verb | arguments | changes | what it does |
|---|---|---|---|
| login | `--repo <repository>` | yes | The user types the repository password at a terminal prompt; it is stored in `local/secrets/<tool>` (0600) and the repository in `local/backup/<tool>.json`. Never in chat or on a command line. It is checked against the repository before it replaces anything: a refused or uncheckable login leaves the stored one as it was, and a replaced password is kept as `<tool>.prev-<time>`. |
| list | `[--path P] [--limit N]` | no | Snapshots, newest first: `id`, `time`, `host`, `paths`, `tags`, `snapshot_id`. |
| show | `<id> [--files [path]] [--prune-plan --keep-... ]` | no | One snapshot's details; `--files` lists its files (bounded); `--prune-plan` lists what `prune` with those keep options would remove and the exact `expect`. |
| stats | | no | Binary and version, repository, logged in, reachable, number of snapshots, latest time, plus the unit's own facts. |
| check | `[--read-data] [--read-data-subset P]` | no | Repository check, metadata only unless `--read-data`, at the lowest CPU and disk priority. `errors_found` only when the program reports damage; `finished: false` when the check stopped early (lock, network, login, an option the version lacks) and so says nothing about damage. Never repairs. |
| backup | `<path>...` | yes | A new snapshot of paths inside the home, at the lowest CPU and disk priority, one job per account at a time. |
| restore | `<id> --target <new-dir> [--include /path]` | yes | Restores into a new or empty directory inside the home, never into a FUSE mount, never over existing files. |
| prune | `--keep-... --expect <n>` | deletes | Removes the snapshots the keep policy no longer keeps and frees their space. Refuses unless `n` equals the count of a fresh plan. Asks the user first. |

Keep options: `--keep-last N`, `--keep-hourly N`, `--keep-daily N`, `--keep-weekly N`, `--keep-monthly N`, `--keep-yearly N`, `--keep-within 30d` (H, d, w, m, y). At least one is required.

## Ids

- restic: `id` is the 8-character short id `list` prints (restic also accepts `latest`); `snapshot_id` is the full id.
- borg: `id` is the archive name (borg addresses archives by name); `snapshot_id` is the archive id.
- `show`, `restore`: one id. `prune` takes no ids: it removes exactly the snapshots of the plan it rebuilds.
- `backup` returns the new snapshot's `id` in the same form (restic: the short id also when restic prints the full one).

## Records

- Times (`time`, `end`, `modified_at`) are unix seconds. borg prints its times in local time without a zone, so the borg unit runs it with `TZ=UTC0` and reads them as UTC; restic's times carry their zone.
- `show <id> --files` records are `path`, `type`, `bytes`, `modified_at`. `type` uses restic's words in both units: `file`, `dir`, `symlink`, `dev`, `chardev`, `fifo`, `socket`. `bytes` is null for folders, and for the second name of a hard link in borg, which has `hardlink: true` and `link_to` (the name that holds the data, counted once there; borg lists the second name with size 0). restic gives each name of a hard link its full size.
- `--files` pages like every list: `--limit 0` lists all (up to 10,000 entries per call), `--offset N` continues where `next_offset` says.

## Removing snapshots

1. `show --prune-plan --keep-daily 7 --keep-weekly 4` lists the snapshots that would go and the `expect` value. It changes nothing (it runs `restic forget --dry-run` or `borg prune --dry-run`).
2. `prune --keep-daily 7 --keep-weekly 4 --expect <n>` rebuilds the plan, refuses when the count differs, and removes exactly the planned snapshots by id (restic `forget <ids>` then `prune`; borg `delete :: <names>` then `compact` on 1.2+). The delete guard asks the user once for the whole batch (`prune` after `php`, ADR-0020).
3. The records name what was removed; `verified` says none of them is listed any more.

## Restoring

`restore <id> --target <dir>` creates `<dir>` (or uses it when it exists and is empty). It refuses a path outside the home, a symlink, an existing non-empty directory, and anything inside a FUSE mount (`pikkumies/lib/HomePath.php`). Restored files keep their stored path inside the target. To put a file back in place, the user moves it with `mv -n` (never over an existing file).

## Passwords and repositories

- The password is typed once at a terminal (`login`, ADR-0019) and handed to the program as a file: restic `RESTIC_PASSWORD_FILE`, borg `BORG_PASSCOMMAND` = `cat <file>`. It is never printed, logged or put on a command line, and the delete guard blocks the agent from reading `local/secrets/`.
- Repositories that also need cloud keys (S3, B2) read them from the user's environment as the program documents; this tool does not store them.
- restic and borg keep a local cache (`~/.cache/restic`, `~/.cache/borg`) that can grow to gigabytes and counts against the account's quota; a backup or check that fails with "disk quota exceeded" on a full account is often this. The tools never delete it. Sources: restic `internal/cache/dir.go:22` (0.11.0, under the user cache directory), `cmd_cache.go:47` (`restic cache --cleanup` removes old cache directories); borg `delete --cache-only` (`archiver.py:1293`, 1.2.4) drops its cache, which borg rebuilds. Both are the user's commands.
- restic trims spaces around a password it reads from a file (`cmd/restic/global.go:301`, `strings.TrimSpace`, 0.11.0). [UNVERIFIED] whether a password with leading or trailing spaces typed at `restic init` is trimmed the same way; if not, it would not match.

## Not here

- Kopia and Duplicacy: later, when a user has the binary (neither is shipped by PMSS). The delete guard already asks before `kopia snapshot expire|delete` and `duplicacy prune`.
- `init` of a new repository, `unlock`, `key` changes, `check --repair`: the user's own decisions, run by hand. RAID is not a backup (PM wiki features:617).
