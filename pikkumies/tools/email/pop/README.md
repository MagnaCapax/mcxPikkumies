# pop

Lists and downloads the user's own POP3 mail over TLS (port 995) into a local Maildir, and deletes exact messages when the user asks (ADR-0053). It works with any mail provider; the user types the server at `login`.

- **Download.** `fetch` reads each message's UIDL and downloads only the ones not fetched before; the UIDLs done are kept in `pikkumies/local/state/email-pop/`. Each message streams into `Maildir/tmp` within a 50 MiB cap and is linked into `new/` (`tools/email/lib/Maildir.php`). It never deletes on the server and stops before less than 10% of the quota would be free.
- **Delete.** POP3 numbers change when messages are removed, so `delete` takes the UIDLs from `list` with `--expect` and refuses when a number now holds a different message. The deletes become final at QUIT; the result is read back on a new connection. The guard asks the user first (rule `pikkumies-delete:`).
- **Transport.** `tools/email/lib/MailTransport.php`: TLS only, verified, no plaintext fallback.

## Tests

`tests/EmailPopTest.php`, against a scripted fake transport (`tests/fixtures/email/`); no network.
