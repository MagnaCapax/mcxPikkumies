# storage

Tells the user how full their account is and what is using the space. It reads the quota without scanning, and on request runs one bounded scan for the biggest folders or files. It never deletes, moves or writes anything in the user's files; the only thing it writes is a small cache and a lock file under `pikkumies/local/storage/`.

## Where the figures come from

- **Quota** (`stats`): `pikkumies/lib/Quota.php`, shared with the torrent clients' `stats`. First the live `quota -s` the user can run themselves, then PMSS's `~/.quota`, which root cron writes from `quota -u <user> -v -s` every minute (PMSS `scripts/cron/updateQuotas.php:84, 95`; `etc/seedbox/config/root.cron:45`). Parsing follows the PMSS panel (`etc/skel/www/index.php:34-108`, cited in `lib/Quota.php`).
- **Why a figure is missing** (`quota_state`), checked only when the live `quota -s` gave none:
  - `off_on_host`: a non-root `quota -s` printed "Cannot open quotafile ... Permission denied". quota-tools opens the quota file itself only when kernel quota is off, so the whole filesystem is unenforced and its figures freeze, while `~/.quota` keeps being rewritten with them. Source: quota-tools 4.06 (the Debian 11 and 12 version) `quotaio.c:139-151`: the quota file is opened, and "Cannot open quotafile" printed, only when `!QIO_ENABLED(h)` (https://sourceforge.net/p/linuxquota/code/ci/v4.06/tree/quotaio.c); seen on two Pulsed Media hosts (support lessons of 2026-09-23 and 2026-10-06).
  - `no_data`: `~/.quota` holds the placeholder PMSS writes when its own quota call failed, `/dev/null 0K 0K 0K` for `(uid 0)` (PMSS `scripts/cron/updateQuotas.php:116-124`). It is never read as zero usage.
  - `unavailable`: no `quota` command and no usable `~/.quota`. PMSS installs `quota` on Debian 12 (`scripts/lib/update/dpkg/selections-debian12.txt:1384`) but its Debian 13 list has no `quota` line (`selections-debian13.txt`, grep 2026-10-14).
  - `none`: `quota -s` says no quota is set; `unknown`: anything else.
- **Hard limit**: read from the quota columns, never computed. PMSS sets the burst from the user's config and derives 1.25 x the quota only when it is missing (`scripts/lib/user/resourcesList.php:47-52`); the file-count limit is 500 per GiB, at least 15,000, plus 25 % (`resourcesList.php:53, 58`).
- **Filesystem** (`fs_free_bytes`, `fs_total_bytes`): `disk_free_space` / `disk_total_space` of `$HOME`. On a shared server this is the whole array; `fs_full` (under 1 MiB free) means a server problem (ENOSPC) that looks to the user exactly like a full quota.

## Scans

`topdirs` runs `du -x --max-depth=N -k` (or `--inodes`), `bigfiles` runs `find <path> -xdev -type f -size +Nc -printf '%s\t%n\t%T@\t%p\n'` (`%n` is the hard-link count). Both:

- run as `nice -n 19 ionice -c3 timeout 300 <command>`. PMSS's root cron re-ionices every `php` and `bash` process each minute but not `du` or `find` (`etc/seedbox/config/root.cron:23-24`), so the class is set on the scan command itself;
- stay on one filesystem, so rclone, sshfs or mergerfs mounts in the home are not walked (that would spend traffic and count remote data);
- are read from the pipe line by line and stop after 200,000 lines; only the ranked top 200 is cached, for 10 minutes;
- run one at a time per account (`flock` on `local/storage/scan.lock`);
- write nothing into the scanned tree. When the quota is full the cache or lock cannot be written; the scan still runs.

The commands are built as an argv and quoted word by word (`pikkumies/lib/ToolProc.php`), the path is resolved with `realpath` and must be inside the home unless named with `--path`, and then a directory this account owns, never `/` or a system directory (`pikkumies/lib/HomePath.php`). `du -0` and `find -printf ...\0` end each record with a NUL byte, so a file name holding a newline cannot add a made-up row.

GNU coreutils `du` prints `<size>\t<path>`, `-k` in KiB, `--inodes` as counts (`du --help`, coreutils 9.7, read on the build host 2026-10-14; `--inodes` exists since coreutils 8.22 [UNVERIFIED]).

## Other providers

Nothing here is PMSS-only. Without `quota` and `~/.quota` the state is `unavailable` and the user is told to ask their provider; the scans work wherever `du`, `find`, `nice`, `ionice` and `timeout` exist.

## Tests

`tests/StorageTest.php` runs the unit with HOME in a temporary directory and a fake bin directory first in PATH (`tests/fixtures/storage/`): fake `quota`, and wrappers for `nice`, `ionice`, `timeout`, `du` and `find` that log their argv and then run the real program, so the tests see the exact command line.

## Not done (on purpose or for later)

- File-count usage and the grace time are not in `stats`: `lib/Quota.php` does not parse the file columns yet.
- Whether a user can read their own BFQ weight (`blkio.bfq.weight`) is unverified, so disk priority is explained, not read.
- No duplicate finder (no tool is shipped and hashing reads terabytes), no "old files" or "partial files" verb (old files on a seedbox are usually still seeding; the torrent clients list unfinished downloads).
