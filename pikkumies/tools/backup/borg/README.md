# borg

Works with the user's own BorgBackup repository: lists and inspects archives, checks the repository, makes new archives, restores into new directories and removes archives the user agreed to remove. It runs `borg` as the user, from PATH, and never installs it.

## Where borg is found

- **Binary**: `borg` in PATH. PMSS installs borgbackup and borgmatic on Debian 10, 11 and 12 (`scripts/lib/update/dpkg/selections-debian12.txt:34, 36`; the Debian 13 list has neither, grep 2026-10-14). Debian ships borg 1.1.16 in bullseye, 1.2.4 in bookworm, 1.4.0 in trixie; borgmatic 1.5.12, 1.7.7 and 1.9.14 (sources.debian.org, read 2026-10-14).
- **Repository and passphrase**: set once by the user with `login --repo <repository>` (`tools/backup/lib/BackupStore.php`, ADR-0019). Every call sets `BORG_REPO` (archives become `::name`) and `BORG_PASSCOMMAND` = `cat <stored file>`, whose output borg uses with trailing newlines stripped (`docs/usage/general/environment.rst.inc:7-10, 16-18`, 1.1.16). `BORG_PASSPHRASE` and `BORG_PASSPHRASE_FD` are removed, because borg reads `BORG_PASSPHRASE` first and splits the command with `shlex` (`src/borg/crypto/key.py:425-445`, 1.2.4).
- **Times**: borg prints `start`, `end`, `mtime` and the "Would prune" times in local time without a zone (a host in EET shifted every time by +10800 s in the 2026-10-14 smoke run), so every call sets `TZ=UTC0` and the unit reads them as UTC (`BorgRepo::utc`).
- **borgmatic**: `stats` lists which of borgmatic's default config paths exist (`/etc/borgmatic/config.yaml`, `/etc/borgmatic.d`, `~/.config/borgmatic/config.yaml`, `~/.config/borgmatic.d`; borgmatic 1.7.7 `borgmatic/config/collect.py:12-20`). It never runs borgmatic: with no action it runs prune, compact, create and check (`borgmatic/commands/arguments.py:71-72`, 1.7.7).
- **PM**: storage boxes advertise BorgBackup over SFTP (PM wiki `storage-boxes`:39) with 500 MB of memory (features:941).

## Calls (borg 1.1.16 and 1.2.4)

| verb | borg command | source |
|---|---|---|
| login | `info --json` (exit 0 = accepted; "passphrase ... incorrect" = refused) | |
| list, stats | `list --json`: `archives` with `id`, `name`, `start` | `docs/internals/frontends.rst` (1.2.4) |
| stats | `--version` ("borg 1.2.4") | `src/borg/archiver.py:3018` (1.2.4) |
| show | `info --json ::<name>` (the archive's `command_line` is dropped); `--files`: `list --json-lines ::<name> [path]` | `frontends.rst`; `docs/usage/list.rst.inc` |
| show --prune-plan | `prune --dry-run --list <keep options>`; the "Would prune:" lines on stderr name each archive as `name time [id]` | `archiver.py:1597-1616` (1.2.4), `:1660` (1.1.16); `helpers/parseformat.py:287-292`; `helpers/time.py:92-96` |
| prune | `delete :: <planned names>`, then `compact` on 1.2+ | `docs/usage/delete.rst.inc:9`; `compact.rst.inc:56-60` (1.2.4: "only compaction will really free repository space"); no `compact.rst.inc` at tag 1.1.16 |
| check | `nice -n 19 ionice -c3 borg check [--verify-data]`; rc 1 = problems found (`do_check` returns EXIT_WARNING only for a failed repository or archive check, `src/borg/archiver.py:343-351` in 1.1.16 and 1.2.4), rc 2 = did not finish | `docs/usage/check.rst.inc` (1.1.16); `return-codes.rst.inc` |
| backup | `nice -n 19 ionice -c3 borg create --json ::<host>-<UTC time to the ms>Z -- <paths>`; exit 1 = warnings. The name is made by the tool: borg's `{hostname}-{now}` is to the second, and a second backup in the same second failed with "Archive ... already exists" (smoke run 2026-10-14, borg 1.2.4) | `frontends.rst`; `docs/usage/general/return-codes.rst.inc` (1.2.4) |
| restore | `nice -n 19 ionice -c3 borg extract ::<name> [path]` (a path starting with `-` after the leading `/` is refused, so it cannot become an option) run with the new directory as working directory; stored paths are relative | `docs/usage/extract.rst.inc:120-121`; `src/borg/helpers/fs.py:159-162` (1.2.4) |

Sources: `github.com/borgbackup/borg/tree/1.1.16` and `/tree/1.2.4`, read 2026-10-14.

## Version gate

`compact` exists from borg 1.2. On 1.1 (Debian 11) `prune` deletes the archives and says `compacted: false` with a note; `stats` reports `compact_supported`. [UNVERIFIED] Whether borg 1.1 frees the space during delete without compact.

## Memory

borg keeps the repository index in the memory of the account that serves the repository (about 40 bytes per chunk), and `check` needs the whole index. A Pulsed Media storage box has 500 MB (features:941), and a check of a large repository has run an account out of memory (PM support lesson of 2026-09-14). `check` says so in its record; the skill tells the agent to check only when asked.

## Tests

`tests/BackupBorgTest.php` runs the unit with a fake `borg` (`tests/fixtures/backup/bin/borg`, a PHP script) that prints the formats above, runs `BORG_PASSCOMMAND` like borg would, and logs every argv.

## Unverified

- A smoke run against real borg 1.2.4 and 1.4.0 (Debian packages in containers, 2026-10-14) ran the read verbs, `login`, `check` and `backup`; prune, delete and restore were not run against real borg. Its findings (time zone, hard links, archive names, error tails) are fixed and tested (`tests/BackupBorgTimeTest.php`, `tests/BackupFilesTest.php`).
- A refused passphrase is recognised by "passphrase ... incorrect" in stderr: borg's `PassphraseWrong` reads "passphrase supplied in BORG_PASSPHRASE, by BORG_PASSCOMMAND or via BORG_PASSPHRASE_FD is incorrect." (`src/borg/crypto/key.py:38-39`, 1.2.4) [UNVERIFIED for 1.1.16].
- Not done: `stats` reports no stale lock for borg (the spec asks for one; restic's `stats` has `locks`). A lock left by a killed borg shows as a failed verb whose error says the repository is locked.
- [UNVERIFIED] The "about 40 bytes per chunk" figure comes from the support lesson, not from the borg source.
