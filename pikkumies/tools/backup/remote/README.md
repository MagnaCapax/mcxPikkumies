# backup remote

Encrypted archives of this account on another machine the user has an ssh login on ([ADR-0049](../../../../docs/adr/0049-backup-remote-over-ssh.md)). The whole chain is `tar --one-file-system | pigz -p 2 | gpg --symmetric --cipher-algo AES256 | ssh`, streamed: nothing is staged on this server's disk.

## How it is set up

1. `setup <host> <user>` reads the remote login from `PIKKUMIES_REMOTE_PASSWORD` and removes it from its own environment at once. ssh gets it through `askpass.php` (`SSH_ASKPASS`, `SSH_ASKPASS_REQUIRE=force`), never on a command line or in a file.
2. It records the host key with a connection that offers no login at all (`StrictHostKeyChecking=accept-new` into its own `known_hosts`) and prints the SHA256 fingerprint. With `--fingerprint`, a key that does not match is looked up again once per accepted type (ED25519, ECDSA nistp256, RSA), because a client pins whichever type it negotiates; no match stops setup before the login is used. Every later connection uses `StrictHostKeyChecking=yes` and the pinned key type. A key that changes later stops every verb with its own error: check with the backup host's owner and run setup again with `--fingerprint`.
3. One login runs a fixed `sh -s` script on the backup machine: it writes `~/.pikkumies-recv/recv.sh` and appends `restrict,command="sh ~/.pikkumies-recv/recv.sh <label> <keep-days>" ssh-ed25519 ...` to `~/.ssh/authorized_keys` (umask 077, existing lines untouched). `ssh-copy-id` is not used because it cannot add those options.
4. The new key must log in and get the receiver's own answer to `df` (`avail_kib N`); a shell would run the system `df` instead, so that answer proves the forced command is in effect. Otherwise the key is deleted here and setup refuses: Pikkumies never keeps a key that opens a shell.

Every ssh call runs with `-F /dev/null` (the user's `~/.ssh/config` is not read) and `--` before the host; the host and the account name are checked against a strict pattern, so a value like `-oProxyCommand=...` is refused.

## The receiver

`recv.sh` is POSIX sh because the backup machine may have no PHP. It accepts only `put <name>`, `commit <name> <bytes>`, `get <name>`, `list` and `df` in `SSH_ORIGINAL_COMMAND`, and only names like `20261018T043000Z.meta.tgz.gpg`. Archives live in `~/pikkumies-backups/<label>/` (label: this host and account). `put` writes `<name>.part` (never over an existing file) and reports the bytes; the run compares them with the bytes it sent and only then sends `commit`, which links the archive to its name and prunes: archives older than `--keep-days` (default 30) go, but the newest 3 of each mode always stay, so junk uploads cannot push good archives out early. `.part` files older than a day are removed.

## What a run holds

- `meta`: PMSS's panel backup set (`pmssCustomerBackupRelativePaths()`, PMSS `etc/skel/www/scriptsIncBackup.php`: rTorrent, Deluge and qBittorrent settings and resume state, ruTorrent users and share), `~/.config` without cache directories, top-level dotfiles, `~/.lighttpd`, Pikkumies' `local/` without `secrets/` and `logs/`, and `pikkumies-file-list.tsv.gz` (mode, owner, bytes, mtime and path of every file in the home, at most 10 minutes of `find`).
- `full`: the whole home on its own filesystem (no FUSE or other mounts), refused above 100 GiB unless `--allow-large`.
- Never: `~/.cache`, `~/.bin`, shell history files (they may hold a typed password), the AI key files, Pikkumies' `local/secrets/` and `local/logs/`.
- Before sending, the run measures the size (`du`, at most 2 minutes) and asks the receiver for free space; it refuses when the archive cannot fit.

## The passphrase

`setup` makes a random passphrase in `local/secrets/backup-remote/pass` (0600) and keeps it across later setups, so older archives stay readable. It is never printed by a verb the agent can run. The user runs `passphrase` in their own terminal and stores it away from this server and the backup machine, then `passphrase --confirm-saved`; until then `stats` says `passphrase_saved_by_user: false`. Without that copy, a lost server means unreadable backups.

The encryption protects the archives from the backup machine and anyone who reaches it. It does not hide anything from this server's own administrators, who could read the passphrase file; that is the client-side encryption trade-off, not zero knowledge.

## Files

`local/secrets/backup-remote/`: `key`, `key.pub`, `pass`, `dest.json`, `state.json`, `known_hosts` (directory 0700, files 0600). The schedule line is also recorded in `local/secrets/cron/backup-remote.cron`, so a session start puts it back after an account move.
