# pop

Lists and downloads the user's own POP3 mail over TLS (port 995) into a local Maildir, and deletes exact messages when the user asks ([ADR-0053](../../../../docs/adr/0053-email-category.md)). It works with any mail provider; the user types the server at `login`.

- **Download.** `fetch` reads each message's UIDL and downloads only the ones not fetched before; the UIDLs done are kept in `pikkumies/local/state/email-pop/`, only those still on the server, so the file stays as large as the mailbox. It is written once per run; a message already in the Maildir (`new/` or `cur/`) is not downloaded again even when the state lost it, but one you deleted from the Maildir comes back after a run that was killed before writing the state. Each message streams into `Maildir/tmp` within a 50 MiB cap and is linked into `new/` (`tools/email/lib/Maildir.php`). It never deletes on the server and stops before less than 10% of the quota would be free.
- **Delete.** POP3 numbers change when messages are removed, so `delete` takes the UIDLs from `list` with `--expect` and refuses when a number now holds a different message. The deletes become final at QUIT; the result is read back on a new connection. It runs when the user already asked for exactly this; otherwise the guard blocks it until the user says yes in the chat (rule `pikkumies-delete:`, [ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)).
- **Transport.** `tools/email/lib/MailTransport.php`: TLS only, verified, no plaintext fallback.

## Tests

`tests/EmailArchiveTest.php` and `tests/EmailMaildirTest.php`, against a scripted fake transport (`tests/fixtures/email/`); no network.
