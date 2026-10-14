# restic

Works with the user's own restic repository: lists and inspects snapshots, checks the repository, makes new snapshots, restores into new directories and removes snapshots the user agreed to remove. It runs `restic` as the user, from PATH, and never installs it.

## Where restic is found

- **Binary**: `restic` in PATH. PMSS installs it on Debian 10, 11 and 12 (`scripts/lib/update/dpkg/selections-debian12.txt:1389`; the Debian 13 list has no `restic` line, grep 2026-10-14). Debian ships 0.11.0 in bullseye, 0.14.0 in bookworm, 0.18.0 in trixie (sources.debian.org/api/src/restic, read 2026-10-14). Without it every verb says "not installed" (exit 1); `login` checks before asking for the password.
- **Repository and password**: set once by the user with `login --repo <repository>` (`tools/backup/lib/BackupStore.php`, ADR-0019). Every call sets `RESTIC_REPOSITORY` and `RESTIC_PASSWORD_FILE` (doc/040_backup.rst, "Environment Variables", 0.11.0 and 0.14.0) and removes `RESTIC_PASSWORD` and `RESTIC_PASSWORD_COMMAND`: restic takes `RESTIC_PASSWORD` before the file and refuses a file together with a command (`cmd/restic/global.go:281, 338-340`, 0.11.0). Repository URLs that carry `user:password@` are redacted from output: `lib/Cli.php` stops at the first `@`, so the unit also redacts up to the last `@` of the host part, as restic's URL parser splits it (`ToolProc::redact`; `internal/backend/rest/config.go:36`, Go `net/url/url.go:577`). `RESTIC_REPOSITORY_FILE` is removed too: restic refuses it together with a repository (`global.go:395`, 0.11.0).
- **PM wiki**: "Restic S3 backup to your storage box on PMSS" covers setting up a repository behind `rclone serve s3`; this unit starts after that.

## Calls (restic 0.11.0 and 0.14.0 source)

| verb | restic command | source |
|---|---|---|
| login | `cat config` (exit 0 = password accepted; "wrong password" in stderr = refused) | `cmd/restic/global.go` |
| list, stats | `snapshots --json`: `id`, `short_id`, `time`, `paths`, `hostname`, `username`, `tags` | `internal/restic/snapshot.go:18-28`, `cmd/restic/cmd_snapshots.go:303-304` (0.14.0) |
| stats | `version` ("restic 0.14.0 compiled with ..."), `list locks --no-lock` | `cmd/restic/cmd_version.go:24`, `global.go:111` (0.11.0) |
| show | `snapshots --json -- <id>`; `--files`: `ls --json -- <id> [path]`, a snapshot line then one node per line (`struct_type` `node`: `name`, `type`, `path`, `size`, `mtime`) | `cmd/restic/cmd_ls.go:72-88` (0.11.0) |
| show --prune-plan | `forget --dry-run --json <keep options>`: groups with `keep`, `remove`, `reasons` | `cmd/restic/cmd_forget.go:216-221` (0.11.0), `:258-263` (0.14.0); `--dry-run` at `:79` / `:100` |
| prune | `forget -- <planned full ids>`, then `prune` | `cmd_forget.go:13` ("forget [flags] [snapshot ID] [...]") |
| check | `nice -n 19 ionice -c3 restic check [--read-data \| --read-data-subset n/m]`; a percentage needs 0.12.0 (`cmd_check.go:57` there; 0.11.0 `:56` takes only `n/m`), so on older restic it is refused before restic runs. `errors_found` only for "Fatal: repository contains errors" (`cmd_check.go:273` 0.11.0, `:374` 0.14.0); any other exit 1 (`main.go:119-128`) is `finished: false` | `cmd/restic/cmd_check.go` |
| backup | `nice -n 19 ionice -c3 restic backup --json -- <paths>`; the `summary` message has `snapshot_id`, `files_new`, `files_changed`, `data_added`; exit 3 = snapshot made but some files unreadable. `snapshot_id` is the short id on 0.14.0 and the full id on 0.19.1 (smoke run 2026-10-14), so the record's `id` is cut to the short id and `snapshot_id` is the full id or null | `internal/ui/json/backup.go:443-456`, `cmd/restic/main.go:123-124` (0.11.0) |
| restore | `nice -n 19 ionice -c3 restic restore --target <new dir> [--include <path>] -- <id>`; files land under their original full path | `cmd/restic/cmd_restore.go:57-59` (0.11.0); PM wiki `restic-s3-backup-to-your-storage-box-on-pmss`:64 |

Sources: `github.com/restic/restic/tree/v0.11.0` and `/tree/v0.14.0`, read 2026-10-14. Keep options map one to one; `--keep-within` is converted to restic's units (y, m, d, h; `internal/restic/duration.go`, 0.11.0), so `2w` becomes `14d` and `12H` becomes `12h`.

## Safety

- `prune` removes exactly the snapshots of the plan the user saw, by full id; the policy is not re-run between the check and the removal.
- `restore` never writes into an existing non-empty directory, outside the home, or into a FUSE mount. The delete guard also asks the user before the unit's `restore` (its pattern matches `restic ... restore`), one extra question.
- `check` never repairs; `init`, `unlock`, `key` and `repair` are not verbs.

## Tests

`tests/BackupTest.php` runs the unit with a fake `restic` (`tests/fixtures/backup/bin/restic`, a PHP script) that prints the formats above, checks the password file like restic would, and logs every argv and the password-related environment (never the password itself).

## Unverified

- A smoke run against real restic 0.14.0 and 0.19.1 (official images, 2026-10-14) ran the read verbs, `login`, `check` and `backup`; prune and restore were not run against real restic.
- The tool recognises a refused password by "wrong password" in stderr: restic's error is "wrong password or no key found" (`internal/repository/key.go:21`, 0.14.0) [UNVERIFIED for other versions].
