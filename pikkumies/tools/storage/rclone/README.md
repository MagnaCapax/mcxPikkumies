# rclone

Works with the user's own rclone: lists remotes and folders, previews and runs copies, syncs and moves, and unmounts the user's FUSE mounts. It runs `rclone` as the user, from PATH, and never installs it. It never reads or prints remote settings (passwords, keys, tokens).

## Where rclone is found

- **Binary**: `rclone` in PATH. PMSS installs rclone 1.69.1 to `/usr/bin/rclone` on every server (PMSS `scripts/lib/update/apps/rclone.php:36, 69`). Debian's own package is older: 1.53.3 in bullseye, 1.60.1 in bookworm and trixie (sources.debian.org/api/src/rclone, read 2026-10-14). Without it every verb says "not installed" (exit 1).
- **Config**: whatever `rclone config file` prints on its last line (rclone 1.69.1 `fs/config/ui.go:639-650`); by default `~/.config/rclone/rclone.conf` (rclone 1.69.1 `docs/content/docs.md`, `--config`). The file is opened only to see whether it is encrypted: the first line that is not empty or a comment starts with `RCLONE_ENCRYPT_V` (`fs/config/crypt.go:68-77`). Then every verb but `stats` stops: the user runs rclone in their own terminal. Every call also passes `--ask-password=false`, so rclone fails instead of prompting (`docs.md`, "Configuration Encryption").
- **WebUI (PMSS)**: the panel toggle writes `~/.rcloneEnable`, and the watchdog runs `rclone rcd --rc-web-gui --rc-addr 127.0.0.1:<~/.rclonePort>` (PMSS `scripts/cron/checkRcloneInstances.php:13-21`, `etc/skel/www/scriptsInc.php:1122`). `stats` reports it; `running` is true only when the port's listener belongs to this account (`lib/Host.php`). The tool has no verb to start or stop it: the panel and the watchdog own it.
- **Mounts**: FUSE mounts in `/proc/self/mountinfo` whose `user_id=` is this account (`pikkumies/lib/HomePath.php`).

## Calls

| verb | rclone command | source |
|---|---|---|
| list | `listremotes --long`: `name: type description` per line | `cmd/listremotes/listremotes.go:224` (1.69.1) |
| show | `lsf --max-depth 1 --format tsp --separator <tab>` with `TZ=UTC0`: lsf prints times in rclone's local zone without saying which (rclone 1.75.0 printed 12:00 UTC as 15:00 under `TZ=Europe/Helsinki`, as 12:00 under `TZ=UTC0`; container run 2026-10-14), so `modified` is given as UTC; read up to offset + limit + 1 entries so `--offset` pages; `--size` adds `size --json` (`count`, `bytes`) and `about --json` (`total`, `used`, `free`; not every backend) | `docs/content/commands/rclone_lsf.md`, `cmd/size/size.go:56-58`, `rclone_about.md` |
| stats | `version` (first line `rclone v1.69.1`), `config file` | `rclone_version.md`, `fs/config/ui.go:639-650` |
| check | rclone 1.66+: `sync --dry-run --retries 1 --combined - <src> <dst>`, so the preview is decided by the same rule (size and modification time) and code path the real sync and move use (`fs/sync/sync.go:379` NeedTransfer; move runs the same `runSyncCopyMove`, `:1376-1377`). Older rclone: `check --combined - [--one-way]` (size and hash), shown without `expect` values. Lines: `=` identical, `-` only on the destination, `+` only in the source, `*` different, `!` error; directories are not listed | `rclone_sync.md` "Logger Flags" (in v1.66.0, absent at v1.65.2), `cmd/sync/sync.go:36-66`, `rclone_check.md` |
| copy | `copy [--ignore-existing] <src> <dst>`, then the one-way plan to read back | `rclone_copy.md`; `docs.md` `--ignore-existing` |
| sync | the plan, then `sync --max-delete <planned deletes> --retries 1 --combined - <src> <dst>`, stopped at the first delete or replace beyond the plan, then the plan again | `rclone_sync.md`; `docs.md` `--max-delete=N` ("a fatal error will be generated"), `--inplace` (uploads land under a `.partial` name first) |
| move | the plan, then `move <src> <dst>`, then `size --json <src>` (0 files left, or exit 3/4: gone) | `rclone_move.md`; `docs.md` "List of exit codes" |
| unmount | `fusermount3 -u <path>` (else `fusermount -u`) | PM wiki `tiered-media-storage-with-rclone-and-mergerfs`:137 (only `fusermount -u`, never kill), :187 (fuse3 ships), :191 (never delete the VFS cache) |

All docs are rclone v1.69.1 (`github.com/rclone/rclone/tree/v1.69.1`). The arguments are an argv quoted word by word, with `--` before the places (`pikkumies/lib/ToolProc.php`).

## Changing files

- `check` is the preview. Its `expect_sync` is the number of files sync would delete or replace on the destination; `expect_move` the number of source files move would remove. On rclone 1.66+ the plan is a dry-run sync, so the files counted are the files the real sync and move change: `rclone check` compares size and hash and, with no hash in common (a crypt remote), size only (`fs/operations/check.go:276-290`, `operations.go:59-64`), while sync compares size and modification time (`operations.go:239-286`), so a `check`-based count could miss files sync then replaces (review 2026-10-14, finding 1). On older rclone `check` still previews but gives no `expect` value, and sync and move refuse; copy works.
- The plan runs at nice 19, idle I/O class and a one-hour time limit; a plan that is cut short, or where rclone could not read a file, is never used. It reads listings and modification times; rclone hashes a file only when its size matches and its time does not. Transfers run at nice 19 and idle I/O with no time limit.
- `sync` and `move` rebuild the plan and refuse when the count differs from `--expect`, or when rclone reported unreadable files. `sync` then also stops itself: `--max-delete` caps deletions, and the tool stops rclone at the first `-` or `*` line beyond the plan (no rclone flag caps replacements; a stopped upload leaves the old file, `docs.md` `--inplace`). `move` has no `--combined` in 1.69.1 (`cmd/sync/sync.go:31`), so only the fresh plan bounds what it replaces. The delete guard sends either to the safety check before it runs (`pikkumies/hooks/guard/guard-destructive/guard-destructive.php`: `rclone ... sync|move`).
- A config rclone cannot decrypt is reported as the encrypted-config error even when the file check did not see it (rclone 1.53.3 may load the config before `config file` answers; `fs/config/config.go:360-361` there).
- `copy` passes `--ignore-existing` unless `--replace` is given, so it never overwrites. `--replace` sends it to the safety check.
- Local places must be inside the home (`realpath`); a source must exist. Connection strings are refused.
- Copying to an outside remote spends the account's outgoing traffic allowance (PM wiki `pulsed-media-seedbox-and-storage-features`:659: past the allowance outgoing traffic is held at 100 Mbit/s).

## Not here, on purpose

- No `mount`, no `serve` (opens the user's files to the network; the PM wiki recipe binds it to localhost and runs it by hand, `rclone-serve-s3-on-pmss`:44-47), no `config` verbs.
- No `cryptcheck`. On rclone 1.66+ `check` uses sync's own rule, so for crypt remotes it says what sync would do; it is not a content check. Before 1.66 `rclone check` on a crypt remote compares sizes only (no hash in common, `operations.go:59-64`).

## Tests

`tests/StorageRclone*Test.php` run the unit against a fake `rclone` (`tests/fixtures/storage/bin/rclone`, a PHP script) that prints the output formats above and logs every argv, so the tests assert exactly what was sent: no sync or move without a matching `--expect`, `--ignore-existing` unless `--replace`, `--ask-password=false` always, and that an encrypted config stops before any remote call. The fake scripts both comparison rules (`check` by hash, sync by time) and records what a real sync applies, so `StorageRclonePlanTest` checks that the approved count covers every file the sync changes, and that a sync finding more is stopped.

## Unverified

- `listremotes --long` on 1.60.1 and 1.53.3 prints no description column; the parser copes (smoke run 2026-10-14).
- [UNVERIFIED] Whether `fusermount -u` waits for rclone's VFS cache to finish uploading. The verb reports what `fusermount` says; the skill tells the agent never to kill rclone or delete the cache.
- A smoke run against real rclone 1.75.0, 1.69.1, 1.60.1 and 1.53.3 (official images, 2026-10-14) ran list, show, stats, check and copy; sync and move were not run against real rclone. Other formats come from the 1.69.1 source and docs cited above. That a dry-run sync writes its `--combined` lines like the real one rests on the source (the logger is called while deciding, `fs/sync/sync.go:1034-1047, 1229`, `operations.go:244-261`; the copy and delete are skipped later), not on a run.
- [UNVERIFIED] How fast rclone stops on the terminate signal while transfers run in parallel: a transfer that was already decided within the plan may finish; the one beyond it is aborted mid-upload.
