# storage

Tools for the space the user has and where their files go: `storage` (disk space and quota) and `rclone` (cloud and SFTP remotes, transfers, mounts). The two share no verbs, so this category has no verb table and no shared contract (ADR-0015); each unit's USAGE.md lists its own. Snapshot backups (restic, borg) are the `backup` category.

`lib/` holds what both units, and the backup units, use: running a program from PATH with a quoted argv and reading its output line by line (`ToolProc.php`), the error text and listing size taken from that output (`ToolText.php`, `ToolList.php`), and deciding which paths a verb may touch (`HomePath.php`). They should move to `pikkumies/lib/` once a third category needs them.

## Safety rules (in the code and in the skills)

1. No storage verb deletes. Freeing space goes through the tool that owns the data (`remove <id> --with-data` of a torrent client); the agent never `rm`s a listed file to free space.
2. Every scan runs as `nice -n 19 ionice -c3 timeout 300`, on one filesystem (`du -x`, `find -xdev`), read from the pipe with a row cap, one at a time per account (a lock), cached for 10 minutes. Nothing is written into the user's quota but a small cache whose write may fail. No content hashing.
3. Quota truth comes from `quota` / `~/.quota` (`pikkumies/lib/Quota.php`), never from the filesystem's free space. "Cannot open quotafile ... Permission denied" means quota is off on the host: tell support.
4. Previews are read verbs (`rclone check`, `show --prune-plan` in the backup units). A verb that deletes or replaces needs `--expect` (recomputed, refused when different) or `--replace`, and the delete guard asks the user once.
5. Restores go to a new or empty directory inside the home, never into a FUSE mount, never over existing files.
6. Secrets: never read or print config values or password files. Passwords only through a terminal prompt into `pikkumies/local/secrets/`. An encrypted rclone config stops the tool.
7. Backup `check` reads metadata by default; never `--repair`; reading the data only when asked (`--read-data`).
8. No `serve` and no `mount` verb, no starting cloudplow.

Every program is looked up in PATH; when it is missing the verb says "not installed" with a hint and exits 1. Nothing here installs anything.

## Feature coverage

What the Pulsed Media feature list and wiki offer for storage and backup, and where it lives here. Line numbers are in the PM wiki pages as cached on 2026-10-14 (`features` = `pulsed-media-seedbox-and-storage-features`).

| feature (source) | unit / verb | no unit, here is why |
|---|---|---|
| Base, burst and bonus quota (features:633-645; `seedbox-storage-quota`) | `storage stats` | |
| File-count (inode) limit (`seedbox-storage-quota`:39-40) | `storage topdirs --inodes` | `stats` does not show file counts yet: `lib/Quota.php` does not parse the file columns |
| ncdu, "find what is eating your quota" (features:381-382) | `storage topdirs`, `storage bigfiles` | ncdu stays the user's interactive tool; the skill names `ncdu -x -rr` |
| hddquota ruTorrent plugin (features:144, :186) | | Reads the same `~/.quota` as `storage stats` |
| Usage alert email at 90 % of the disk quota (features:572) | | A panel setting; the skill can tell the user it exists |
| Torrent configuration backup download and restore (features:572) | | A panel button |
| Rclone (features:280-281) | `rclone list, show, check, copy, sync, move` | |
| Rclone WebUI (features:586, :699-700) | `rclone stats` (enabled, port, running) | No start or stop verb: the panel toggle and the watchdog own it |
| rclone mount, rclone union and mergerfs tiering (`tiered-media-storage-with-rclone-and-mergerfs`, mergerfs "not part of the seedbox image" :127) | `rclone stats` (mounts), `rclone unmount` | `mount` later, when asked; mergerfs is not shipped |
| rclone serve s3 (`rclone-serve-s3-on-pmss`) | | Opens a listener with the user's files; a user procedure |
| s3fs and sshfs mounts (features:289-293) | `rclone stats` lists them among this account's FUSE mounts | |
| Restic (features:277-278; `restic-s3-backup-to-your-storage-box-on-pmss`) | `restic` (backup category) | |
| BorgBackup (features:271-272) | `borg` (backup category) | |
| borgmatic (features:274-275) | `borg stats` names its config files | Bare `borgmatic` runs prune, compact, create and check (borgmatic 1.7.7, the Debian 12 version, `borgmatic/commands/arguments.py:71-72`); the guard asks before it runs |
| Kopia, Duplicacy (features:955; `kopia-s3-...`, `duplicacy-s3-...`) | | Later, on demand: not shipped by PMSS; their deleting commands are already asked by the guard |
| Cloudplow (features:219-221) | | Later, on demand, read-only first: it moves data on its own |
| Syncthing (features:283-284) | | Own web UI; continuous sync spreads deletes; not asked for |
| Resilio Sync (features:286-287) | | Own app and UI |
| S3 servers you run yourself (features:955; `s3-object-storage-on-pmss`) | | One-time installs with their own consoles |
| Client-side encryption (`client-side-encryption-for-your-seedbox-or-storage-box`) | crypt remotes show as `type: crypt` in `rclone list` | gocryptfs and gpg are user procedures |
| Proxmox backups to a storage box (features:955; `backup-proxmox-with-pulsed-media-storage-box`) | | Runs on the customer's Proxmox host, not here |
| SFTP, FTP, SCP, rsync, WebDAV, SSHFS access (features:497-526) | | Used from the user's own computer |
| lftp (features:326) | | Used directly; the guard asks before `--Remove-source-files` and `mirror --delete` |
| Archive tools (features:295-316) | | File handling, not storage |
| RAID per plan, "RAID ... is not a backup" (features:617) | | The backup skills say it |
| Storage boxes: 500 MB of RAM (features:941) | | `borg check` and backups warn about memory |
| Disk priority and resource tiers (`seedbox-and-storage-box-disk-priority`, `seedbox-and-storage-box-resource-tiers`) | | Explained, not read: whether a user can read their BFQ weight is unverified |
| Extra Disk add-on (features:820) | | Billing: the skill points to the client area and never states a price |
| quotaSnapshot, storageHealthSnapshot, iopsLimits (features:732, :750-760) | | Root-only data |
| Web File Manager (features:583) | | Panel |
