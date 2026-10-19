# backup remote

Encrypted archives of this account on another machine, over ssh ([ADR-0049](../../../../docs/adr/0049-backup-remote-over-ssh.md)). Not a snapshot repository: its verbs are its own (`tools/backup/README.md`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| setup | `<host> <user> [--port N] [--keep-days 30] [--fingerprint SHA256:...]` | yes | The one-time login comes only from `PIKKUMIES_REMOTE_PASSWORD='...'` before the command, never as an argument. Pins the host key without logging in (prints `host_key`; with `--fingerprint` a different key stops here, before the login is used), logs in once to install the receiver `~/.pikkumies-recv/recv.sh` and a `restrict,command=` key line, then checks that the new key gets the receiver's own `df` answer, which a shell would not give. Refuses, and deletes the new key here, when it cannot. Makes the gpg passphrase on first setup and keeps it on later ones. Asks the user first. |
| run | `--mode meta\|full [--allow-large]` | yes | One archive `<UTC time>.<mode>.tgz.gpg`: `tar --one-file-system \| pigz -p 2 \| gpg --symmetric \| ssh put`, at nice 19 and idle I/O, one run per account at a time. Checks free space there first. `full` above 100 GiB needs `--allow-large`. Reports `bytes`, `files_changed_while_read` (tar exit 1), `file_list_complete` (meta); `verified` when the receiver stored exactly the bytes sent and kept the archive. Every failure, also one before anything is sent, is recorded as `last_run` with `ok: false`. |
| list | | no | Archives on the backup machine, newest first: `name`, `mode`, `time` (unix), `bytes`. |
| restore | `<name> --target <new-dir>` | yes | `get \| gpg -d \| pigz -d \| tar -x` into a new or empty directory inside the home, never into a FUSE mount, never over existing files. Paths keep their place relative to the home (`./.config/...`); meta archives also hold `pikkumies-file-list.tsv.gz`. |
| stats | | no | `host`, `user`, `port`, `label`, `keep_days`, `host_key`, `schedule`, `last_run`, `passphrase_saved_by_user`. Local records only. |
| schedule | `--daily HH:MM --mode meta\|full [--allow-large]` or `--off` | yes | A tagged crontab line run by the PHP that ran this verb (`# pikkumies-backup-remote`, log `local/backup-remote.log`), put back after an account move. Asks the user first. |
| passphrase | `[--confirm-saved]` | no | Shows the gpg passphrase, only when stdin and stdout are the user's own terminal; `--confirm-saved` records that they keep a copy elsewhere. The agent is blocked from running it. |

```sh
PIKKUMIES_REMOTE_PASSWORD='the-remote-login' php ~/pikkumies/tools/backup/remote/remote.php setup backup.example.com xyzjohn
php ~/pikkumies/tools/backup/remote/remote.php schedule --daily 04:30 --mode meta
php ~/pikkumies/tools/backup/remote/remote.php run --mode meta
php ~/pikkumies/tools/backup/remote/remote.php list --human
php ~/pikkumies/tools/backup/remote/remote.php stats
php ~/pikkumies/tools/backup/remote/remote.php restore 20261018T043000Z.meta.tgz.gpg --target ~/restore-1018
```

In the user's own terminal only: `php ~/pikkumies/tools/backup/remote/remote.php passphrase`, then the same with `--confirm-saved`.

- `setup` and `schedule` ask the user before they run; `passphrase` is for the user's own terminal.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
