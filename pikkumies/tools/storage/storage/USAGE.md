# storage

Disk space and quota of this account. No verb deletes anything.

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | | no | No scan. The quota (`quota_used_bytes`, `quota_limit_bytes`, `quota_hard_bytes`, `quota_free_bytes`, `over_quota`, `quota_used_percent`, `quota_source`), `quota_state` (`ok`, `over_soft`, `over_hard`, `off_on_host`, `no_data`, `unavailable`, `none`, `unknown`) with a `hint`, `live_quota`, and the filesystem as a second fact (`fs_free_bytes`, `fs_total_bytes`, `fs_full`). |
| topdirs | `[--path P] [--depth 1-3] [--inodes] [--limit N] [--fresh]` | no | Biggest folders under the home (or `--path`): `path`, `bytes` (or `files` with `--inodes`), `share` of the total. One bounded `du` scan; cached 10 minutes. |
| bigfiles | `[--path P] [--min-size 1G] [--limit N] [--fresh]` | no | Biggest files of at least `--min-size`: `path`, `bytes`, `links`, `modified_at`, `frees_space` (false when other hard links keep the data). One bounded `find` scan; cached 10 minutes. |

```sh
php ~/pikkumies/tools/storage/storage/storage.php stats --human
php ~/pikkumies/tools/storage/storage/storage.php topdirs --depth 2 --limit 10 --human
php ~/pikkumies/tools/storage/storage/storage.php topdirs --inodes --limit 10
php ~/pikkumies/tools/storage/storage/storage.php bigfiles --min-size 5G --limit 20
php ~/pikkumies/tools/storage/storage/storage.php bigfiles --path ~/data --fresh
```

- Scans run as `nice -n 19 ionice -c3 timeout 300`, stay on one filesystem (`du -x`, `find -xdev`: FUSE mounts are not walked) and stop after 200,000 lines. The status line says `partial` and why (`stopped_by`) when a scan did not finish.
- `--path` outside the home is allowed when named and owned by this account; never `/`, a system directory, or another account's folder (`/home`, `/tmp`, `/mnt`, another home).
- `du` counts every file under a path whoever owns it, and a hard-linked file once; the quota counts only this account's files. The two can differ.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
