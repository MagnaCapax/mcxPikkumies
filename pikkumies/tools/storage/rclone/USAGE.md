# rclone

The user's own rclone remotes and mounts. Places are `remote:path` (a remote from `list`) or a full local path inside the home.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | | no | The remotes: `remote` (name with colon), `type`, `description`; the status line names the config file. Never the remotes' settings. |
| show | `<remote:path> [--size] [--limit N]` | no | One folder level: `name`, `is_dir`, `bytes`, `modified`. `--size` adds the total file count and bytes (a full listing: slow and API calls on big remotes) and the remote's `total`/`used`/`free` where the backend reports them. |
| stats | | no | `version`, `config` path, `config_exists`, `config_encrypted`, on PMSS the WebUI (`enabled`, `port`, `running`), this account's FUSE `mounts`, and `running` rclone processes (pid and subcommand). |
| check | `<src> <dst> [--one-way]` | no | The preview: one record per file to change (`change`: `extra_on_dst` = deleted by sync, `replace`, `copy`, `error`) and in the status line the counts, `copy_bytes` (local sources), `compared_by`, `expect_sync` and `expect_move`. On rclone 1.66+ it is a dry-run sync, so it counts what sync and move will really change; on older rclone it has no expect values (sync and move refuse there). |
| copy | `<src> <dst> [--replace]` | yes | Copies new files and never deletes. Files already on the destination are left as they are unless `--replace` (asks the user first). Reads back what was not copied (`verified`). |
| sync | `<src> <dst> --expect <n>` | deletes | Makes the destination like the source: deletes the files only on the destination and replaces changed ones. Refuses unless `n` equals `expect_sync` from a fresh check; stops rclone as soon as it would delete or replace more than the plan. Asks the user first. Needs rclone 1.66+. |
| move | `<src> <dst> --expect <n>` | deletes | Copies, then removes the files from the source. Refuses unless `n` equals `expect_move` from a fresh check. Asks the user first. Needs rclone 1.66+. |
| unmount | `<path>` | yes | `fusermount -u` on a FUSE mount of this account inside the home; reads back that it is gone. Never kills a process. |

```sh
php ~/pikkumies/tools/storage/rclone/rclone.php list
php ~/pikkumies/tools/storage/rclone/rclone.php show gdrive:media --size --human
php ~/pikkumies/tools/storage/rclone/rclone.php stats --human
php ~/pikkumies/tools/storage/rclone/rclone.php check ~/data/tv gdrive:tv
php ~/pikkumies/tools/storage/rclone/rclone.php check ~/data/tv gdrive:tv --one-way
php ~/pikkumies/tools/storage/rclone/rclone.php copy ~/data/tv gdrive:tv
php ~/pikkumies/tools/storage/rclone/rclone.php copy gdrive:tv/Show ~/restore-show --replace
php ~/pikkumies/tools/storage/rclone/rclone.php sync ~/data/tv gdrive:tv --expect 12
php ~/pikkumies/tools/storage/rclone/rclone.php move ~/data/done gdrive:archive --expect 40
php ~/pikkumies/tools/storage/rclone/rclone.php unmount ~/mnt/gdrive
```

- Connection strings (`:sftp,host=...:path`) are refused: they put passwords on the command line.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
