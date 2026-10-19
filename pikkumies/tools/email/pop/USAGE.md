# pop

The user's own POP3 mailbox over TLS, port 995 ([ADR-0053](../../../../docs/adr/0053-email-category.md)). Shared rules: `tools/email/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| login | | yes | The user types the server, user name and password (hidden) at a terminal. The login is tried, then stored in `pikkumies/local/secrets/email-pop` (0600). |
| status | | no | The stored login: `login` (bool), `host`, `port`, `user`; never the password. No connection is made. |
| list | | no | One record per message: `n`, `size_bytes`, `uidl`. |
| fetch | `--to MAILDIR [--limit N]` | yes (new files) | Downloads up to N messages (default 20, at most 1000; `--limit 0` means 1000) not fetched before, by UIDL, into the Maildir `MAILDIR` (created when missing; inside the home, not under a dot-directory, `~/www`, `~/bin` or `pikkumies/`). Never deletes on the server. Stops before less than 10% of the disk or file quota would be free (`stopped`); messages over 50 MiB are skipped and listed. One record: `dir`, `fetched` (messages downloaded now; one already in the Maildir is not counted), `bytes`, `skipped_too_large`, `left`. |
| delete | `<n>... --expect UIDL[,UIDL...]` | deletes | Deletes exactly these messages after checking that each still has the UIDL list showed (same order). Runs when the user already asked for exactly this; otherwise blocked until the user says yes in the chat ([ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)). Status `verified` (read back on a new connection). |

```sh
P=~/pikkumies/tools/email/pop/pop.php
php $P status
php $P list
php $P fetch --to ~/mail-pop --limit 50
php $P delete 3 --expect 000001a64e2f3c11
```

- Every verb prints JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
