# imap

The user's own IMAP mailbox over TLS, port 993 ([ADR-0053](../../../../docs/adr/0053-email-category.md)). Shared rules: `tools/email/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| login | | yes | The user types the server, user name and password (hidden) at a terminal. The login is tried, then stored in `pikkumies/local/secrets/email-imap` (0600): `login`, `host`, `port`, `user`, `stored`. |
| status | | no | The stored login: `login` (bool), `host`, `port`, `user`; never the password. No connection is made. |
| folders | | no | One record per folder: `name` (pass it exactly as printed), `delimiter`, `flags` (for example `\Junk`, `\Noselect`). |
| list | `[--folder F] [--unseen] [--limit N]` | no | Headers of the newest N messages (default 20, at most 100; `--limit 0` means 100), newest first: `uid`, `folder`, `from`, `to`, `subject`, `date`, `size_bytes`, `seen`, `flagged`. Status: `matched` (how many messages matched, also those not shown), `uidvalidity`. Unseen messages stay unseen. |
| show | `<uid> [--folder F]` | no | One message: headers, `message_id`, `attachments` (`name`, `type`, `bytes`), `untrusted_text` (the body as text, at most 8000 bytes; `text_truncated`). Over 25 MiB: use fetch. |
| search | `<words> [--folder F] [--limit N]` | no | Like list, for messages containing the words (printable ASCII; sent as one quoted IMAP TEXT search). |
| fetch | `<uid> [--folder F] [--to DIR]` | yes (new files) | Saves the attachments into a new directory: `--to` must not exist; default `~/mail-attachments/<folder>-<uid>-<time>`. One record per file: `path`, `original_name`, `type`, `bytes`. Checks the message size (at most 50 MiB) against the free quota first. |
| move | `<uid>... --to FOLDER [--folder F]` | yes | Moves the messages (MOVE, or COPY and `UID EXPUNGE` of those uids; refused when the server has neither MOVE nor UIDPLUS). Status `verified`. |
| flag | `<uid>... --seen\|--unseen\|--flagged [--folder F]` | yes | Sets or clears the flag. Status `verified`. |
| delete | `<uid>... --expect UIDVALIDITY [--folder F]` | deletes | Removes exactly these messages: `\Deleted`, then `UID EXPUNGE` of the same uids. `--expect` is the folder's uidvalidity from list; a different one refuses. Refused when the server lacks UIDPLUS. Runs when what the user asked for covers it; otherwise the safety check refuses it ([ADR-0101](../../../../docs/adr/0101-refuse-and-report-never-ask.md)). Status `verified`. |
| archive | `[--folder F \| --all] --to DIR` | yes (new files) | Copies messages into `DIR/<folder>-<uidvalidity>/` (Maildir; the whole folder path, `/` as `.`, plus 8 hex of its hash when the name had to change), newest uids since the last run, at most 2000 per call (`more` in the status). Never deletes on the server. Stops before less than 10% of the disk or file quota would be free (`stopped`). Messages over 50 MiB are skipped and listed. One record per folder: `archived`, `bytes`, `skipped_too_large`, `left`, `last_uid`, and `previous_dir` when a folder archived under the older name (its last path part only) starts again from uid 0 in its new directory; the old directory is left as it is. |

```sh
I=~/pikkumies/tools/email/imap/imap.php
php $I status
php $I folders
php $I list --unseen --limit 10
php $I search invoice
php $I show 4123
php $I fetch 4123
php $I move 4123 4124 --to Archive
php $I flag 4123 --seen
php $I delete 4123 --expect 1700000000
php $I archive --all --to ~/mail-archive
```

- Folder names are printable ASCII, exactly as `folders` prints them (non-ASCII names come in IMAP's modified UTF-7).
- Every verb prints JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
