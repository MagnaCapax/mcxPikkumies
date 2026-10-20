# backup-safety

Whether this account's backups work, and a copy of exactly what a change is about to remove ([ADR-0099](../../../../docs/adr/0099-backup-safety-layer.md)). It runs no backup of its own: restic, borg and `backup remote` do that. Not a snapshot repository: its verbs are its own (`tools/backup/README.md`).

## The status

`status --refresh` finds every method and judges it (`pikkumies/lib/BackupStatus.php`):

- **restic and borg** (`local/backup/<tool>.json`): the snapshots and their sizes (borg `info` for the latest two, restic's own snapshot summary on 0.17 and newer), and a metadata `check` when the last one is over 7 days old. A repository whose password is not stored is reported, never logged into.
- **backup remote**: the receiver's listing (names, sizes), the last run, the schedule, and its own `check` (the newest archive decrypted and listed, run again when the last is over 7 days old). Only `check` verifies: a run whose byte count matched proves the upload, not that the archive decrypts. With only full archives over 10 GiB there is nothing to check, so it stays unknown.
- **custody**: while `backup remote` is set up and its passphrase not confirmed saved (typed back with `passphrase --confirm-saved`, for the passphrase file that is there now), the table, `status` (`custody`) and each start carry one line saying the archives cannot be decrypted if this server is lost. It is never part of the verdict or the delete guard: the key stays on this server through a delete, so the backups still restore.
- **other tools**: a line of this account's crontab that runs restic, borg, borgmatic, rsync, rclone, duplicity, rsnapshot, kopia or duplicacy, and the borgmatic and duplicacy config files in the home. The system crontabs (`/etc/crontab`, `/etc/cron.d/`) and config files under `/etc` count only when this account has root (uid 0, or passwordless sudo): on a shared server they are the provider's jobs. Pikkumies cannot check these, so they count as unknown.

A method is **working** when its latest backup is at most 48 hours old (or twice its schedule), verified within 7 days, not empty and within 50 % of the size of the one before; otherwise broken, stale or unknown, with a `why`. The whole state is working when one method works.

The result is `local/state/backup-status.json` (0600). The hooks read only this file. An interactive start refreshes it in the background when it is older than a day (one at a time, at most 120 seconds, its output in `local/logs/backup-status/last.log`) and says one line when the state is not working.

## The table

`status` lists the 10 latest backups across methods (`--latest N`) and prints them as a markdown `table` (made, age, size, where, name) in the status line. The delete guard shows the same table to the agent before a dangerous delete, for the user.

## snap

`snap <path>...` copies exactly those files or folders with `cp -a --reflink=auto` into `~/backups/<UTC time>/`, under their path relative to the home. A path outside the home goes to `/backups/<UTC time>/` when this account manages the whole system: with passwordless sudo cp runs through `sudo -n`; as uid 0 every copy goes to `/backups`, so root never writes inside a home. The home is `$HOME` only when this account owns that folder, else its passwd entry (`HomePath::home`). Without root it goes to `~/backups/<UTC time>/<full path>`, if this account can read it. `--package <name>` copies the config files dpkg lists for that package (root or sudo only).

- `~/backups`, `/backups` and the time folder are made 0700 before anything is copied in, and refused when they are a symlink, a file or another account's. Copies keep their mode for the owner only.
- The whole copy is measured first; over min(2 GiB, half the free quota, or half the free disk) nothing is copied and the hint points at real backups.
- Each copy is read back (`verified`: the same file bytes); only verified copies count for the delete guard, and a copy that grew past the cap after it was measured is reported as `over_cap`. Each snap is logged to `local/logs/backup-snap/YYYYMMDD.jsonl` (time, destinations, sources, bytes, how) and its sources go to `local/state/backup-snap/recent.json`, which lets the delete guard pass a removal of exactly those files for 30 minutes.
- A snap lives on the same disk as the original: it undoes a bad change, it does not survive a lost disk. Nothing removes it; the user does once the change is confirmed.

## Setting up real backups

The storage box and the guide to zero-knowledge (encrypted before it leaves) backups are the `pm-storage-boxes` and `pm-zero-knowledge-backups` sources in `pikkumies/config/documentationSources.md`; `status` prints their addresses as `storage_box` and `e2e_guide`. `backup remote` ([remote](../remote/README.md)) sets up GPG end-to-end encrypted archives to a storage box or any ssh machine; its `passphrase --confirm-saved` has the user type the key back before it counts as kept.
