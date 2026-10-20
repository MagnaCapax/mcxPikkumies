# backup-safety

Whether this account's backups work, and a copy of exactly the files a change is about to remove ([ADR-0099](../../../../docs/adr/0099-backup-safety-layer.md)).

| verb | arguments | changes | what it does |
|---|---|---|---|
| status | `[--refresh] [--latest N]` | no | The state (`working`, `stale`, `broken`, `none`, `unknown`), one record per method (`kind`, `where`, `state`, `why`, `latest_at`, `latest_bytes`, `verified_at`) and the N latest backups across methods (default 10: `kind`, `where`, `name`, `time`, `bytes`). The status line adds `table` (markdown, for the user), `storage_box` and `e2e_guide` (from the documentation sources), `suggestion` when not working and `custody` (one line, also at the end of `table`) while the backup remote passphrase is not confirmed saved. Reads the cache; `--refresh` rebuilds it now (lists every repository, a metadata check when the last is over 7 days old; up to a few minutes). |
| snap | `<path>...` or `--package <name>` | yes | Copies exactly these files or folders (`cp -a --reflink=auto`) to `~/backups/<UTC time>/`, keeping their path relative to the home; a path outside the home goes to `/backups/<UTC time>/` with root (uid 0, or passwordless sudo through `sudo -n`), else to `~/backups` as far as this account can read it. `--package` copies a package's config files (needs root or sudo). Folders 0700, files owner-only. Refuses over min(2 GiB, half the free quota or disk) and copies nothing. Reports each copy (`path`, `to`, `bytes`, `verified`) and `total_bytes`. |

```sh
php ~/pikkumies/tools/backup/backup-safety/backup-safety.php status --human
php ~/pikkumies/tools/backup/backup-safety/backup-safety.php status --refresh
php ~/pikkumies/tools/backup/backup-safety/backup-safety.php snap ~/.config/rtorrent/rtorrent.rc ~/.lighttpd
php ~/pikkumies/tools/backup/backup-safety/backup-safety.php snap --package nginx
```

- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
