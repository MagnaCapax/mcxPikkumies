# ADR-0049: Remote backups over ssh with a restricted key; a one-time login may come from the chat (amends ADR-0019)

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted

## Context
The request of 2026-10-18, verbatim except for the example password: "the E2E through pigz + gpg making a backup of your seedbox pushed to remote place tooling + skill setting, to make it easy and convenient to say just command pikkumies "setup backing up daily to server.servername.com aaccount xyzjohn pw is [example password, elided here] but setup ssh key so it can be autoamted and scrub the original password" type of command, or one of in interactive chats "backup to " --- and also make backup only metadata + config, not actual data option, so freuqnet backups can be made".

Constraints from the operator's review of ssh keys for agents (2026-10-18): only scoped keys (`command=`, `restrict`); the encryption key must not live only on the backup machine; Pikkumies is a language model with a shell, so a prompt injection must never reach a key that opens a shell.

ADR-0019 says a password is typed "never in chat or on the command line", and `tools/backup/DOCTRINE.md` repeats it. The request asks for exactly that for one password, so this ADR amends ADR-0019 for that case only.

Facts this rests on:
- `rrsync` (rsync 3.4.1) runs only `rsync --server` and refuses anything else, and with `-wo` refuses reads, so a piped `tar | pigz | gpg | ssh` stream and a restore cannot go through it.
- `ssh-copy-id` cannot add `command=` or `restrict` to the key line it installs.
- OpenSSH 10.0's manual: `SSH_ASKPASS_REQUIRE=force` makes ssh use the askpass program whether or not `DISPLAY` is set. [UNVERIFIED] the OpenSSH release that first had it (from memory 8.4).
- A password typed in the chat is already in the session history (kept forever by default, ADR-0044), in the halt snapshot for two days (ADR-0047) and sent to the model provider; a one-shot `pikkumies "..."` also puts it in the shell history and a process's argv. None of that can be erased from here.
- PMSS's own panel backup set is `pmssCustomerBackupRelativePaths()` (PMSS `etc/skel/www/scriptsIncBackup.php`).

## Options Considered
- **restic or borg over sftp.** Already units here, but not the pipeline the operator named (pigz and gpg), and both need a login the user types at a terminal.
- **An rrsync-restricted key.** Cannot carry a stream or a restore (above); in practice it would fall back to an unrestricted key.
- **A receiver of our own as the key's forced command, setup from a password given once.** Matches the request; the key can do five things and nothing else.

## Decision
- A tool unit `tools/backup/remote/` with `setup`, `run --mode meta|full`, `list`, `restore`, `stats`, `schedule`, `passphrase`.
- **The one-time login (this amendment of ADR-0019):** `setup` takes the login of the backup machine only from `PIKKUMIES_REMOTE_PASSWORD`, set as a prefix of the command, never as an argument or in a file. It reads it first and removes it from its own environment; ssh gets it through `askpass.php` (`SSH_ASKPASS`, `SSH_ASKPASS_REQUIRE=force`), which answers only a login prompt. It is used once, to install the key, and stored nowhere. The command log removes the whole shell word of any `*PASSWORD=` value, quotes included. Everything else in ADR-0019 stands: every other password is typed at a terminal.
- **Honest scrub scope:** setup tells the user that the password already reached the model provider and the session history, and that changing the remote password now is what really scrubs it. The tool does not claim to have erased anything else.
- **The key:** a new ed25519 key whose line on the backup machine is `restrict,command="sh ~/.pikkumies-recv/recv.sh <label> <keep-days>"`, installed by one login that runs a fixed `sh -s` script (umask 077, append only). Setup then checks that the key gets the receiver's own `df` answer (`avail_kib N`), which a shell running the system `df` would not give; otherwise it deletes the key here and refuses. No unrestricted key is ever kept.
- **The host key:** recorded before any login by a connection that offers none (`accept-new` into a dedicated `known_hosts`), printed, and checked against `--fingerprint` when given. A client pins whichever key type it negotiates (in the end-to-end test OpenSSH 8.4 took the server's ECDSA key and 10.0 its ED25519 key), so a fingerprint that does not match is tried once per accepted type (ED25519, ECDSA nistp256, RSA) and the matching key is pinned. Every later connection uses `StrictHostKeyChecking=yes` and `HostKeyAlgorithms` set to the pinned type. A key that changes later is its own error (someone may be in between; check with the owner and rerun setup with `--fingerprint`); ssh's advice to delete the pinned key is never passed on. ssh always runs with `-F /dev/null`, `--` before the host, and a host and account name checked against strict patterns.
- **The receiver** (`recv.sh`, POSIX sh because the backup machine may have no PHP) accepts only `put`, `commit`, `get`, `list`, `df` and archive names of one fixed form. `commit` is a fifth verb beyond the four first designed: an interrupted upload looks like a normal end of input, so an archive counts only once the run has compared the bytes the receiver stored with the bytes it sent. Retention runs on commit: archives older than `keep-days` go, the newest 3 of each mode always stay.
- **The passphrase:** gpg `--symmetric` (AES256) with a random passphrase made at the first setup in `local/secrets/backup-remote/pass` and never replaced. No verb the agent can run prints it; `passphrase` prints it only when stdin and stdout are a terminal, and the delete guard blocks the agent from running it. The user keeps a copy away from this server and the backup machine; `stats` reports `passphrase_saved_by_user` until they confirm it.
- **What the encryption protects:** the archives against the backup machine and anyone who reaches it. It is client-side encryption on the seedbox (the design review found the same symmetric gpg with a passphrase file on the PM wiki's client-side encryption page), not zero knowledge against the seedbox's own administrators, who can read the passphrase file.
- **Approval:** `setup` and `schedule` ask the user (delete guard, `pikkumies-send:` rule key), because they decide where the account's files go from then on. Destination, key and pinned host key live under `local/secrets/`, which the guard keeps the agent from reading or writing. The guard blocks `php ... passphrase` and any command that names the tool and also holds `$`, a backtick or `eval` (apart from a single-quoted login value), because under a pseudo-terminal the passphrase verb does print.
- **The limit of that:** a pattern guard cannot stop every indirection; reading the pass file through an interpreter, for one, is out of its sight. The passphrase's real protection is that it exists only to be copied by the user once, and the archives are only as safe as this account.
- **Size and load:** `meta` (PMSS's panel set, `~/.config` without caches, top-level dotfiles, `~/.lighttpd`, Pikkumies' `local/` without secrets and logs, a gzipped file list) for frequent runs; `full` refused above 100 GiB unless `--allow-large`. Runs take a lock, run at nice 19 and idle I/O, use two pigz threads, stay on one filesystem, and check the free space on the backup machine first.
- **Schedule:** `Update::keepLine` writes the tagged crontab line `# pikkumies-backup-remote` through the same helper as the update and cron-tick lines, and records it under `local/secrets/cron/` so that `ensureScheduled` puts it back after an account move.

## Consequences
- One sentence in the chat sets up daily encrypted backups whose key can do nothing but store, list and fetch archives.
- The typed password stays in the session history and with the provider; the user is told so and told to change it.
- A shell started by the agent's terminal holds the prefix on its command line for the moment before it starts php; on a host without hidepid other accounts could read it then. [UNVERIFIED] whether every PMSS node mounts /proc with hidepid.
- A backup machine whose sshd ignores `restrict`/`command=`, or whose account has no `sh`, cannot be used: setup refuses.
- A lost seedbox without the user's saved passphrase means unreadable archives; the tool keeps saying so until the user confirms.
- The unit tests use fake ssh, gpg and pigz programs and run the real receiver locally. The whole sequence (setup, meta and full runs, list, restore with byte-identical files, schedule, host-key refusal after the server keys are rotated, early failures) was also run end to end against real OpenSSH 8.4, 9.2 and 10.0 servers in containers; a client pins whichever host key type it negotiates, and each of ed25519, ecdsa and rsa works.

## References
- [ADR-0019](0019-hosts-and-credentials.md) (amended), [ADR-0020](0020-deletes-need-the-users-approval.md) (approvals), [ADR-0025](0025-scheduled-jobs-per-minute-tick-under-no-new-privs.md) (tagged crontab lines), [ADR-0044](0044-every-shell-command-is-logged.md) (command log), [ADR-0047](0047-halt-guard.md) (halt snapshot)
- `tools/backup/remote/README.md`

## Amendment 2026-10-19: the person's SSH deny list applies ([ADR-0068](0068-ssh-connections-need-a-stated-intent-and-the-persons-allow-and-deny-lists.md))
`setup` refuses a host on `ssh.deny` (in `local/config.php`) before any program runs, and every connection of the other verbs (`RemoteDest::ssh`, so run, list, restore and the commit) refuses a recorded host that is on it now; `run` checks first, so a scheduled run refuses before any work and records the refusal as its last run. A host that cannot be read as a name or a dotted IPv4 address is refused while the deny list is not empty. The allow list does not apply here: setup is asked already and names the host.
