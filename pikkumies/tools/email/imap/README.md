# imap

Reads and tidies the user's own IMAP mailbox over TLS (port 993), and archives it into local Maildirs (ADR-0053). It works with any mail provider; nothing is detected on the machine. The user types the server at `login`.

## How it talks to the server

PHP's curl extension through `tools/email/lib/MailTransport.php`: TLS 1.2 or newer, certificate and host name verified, curl pinned to the address that was checked, and a port on this machine used only when this account owns it. Bodies and headers come through curl's URL form (`;UID=n;SECTION=...`), because curl drops message text from answers to typed commands. That URL form sets `\Seen`, so every verb that reads sets an unseen message back to unseen: reading never changes the mailbox.

## Commands that cannot be bent

libcurl URL-decodes a typed IMAP command before refusing control bytes, so `%22` would arrive as `"`. `tools/email/lib/MailImap.php` therefore checks every argument first: a uid matches `^[1-9][0-9]{0,9}$`, folder names and search words are printable ASCII, quoted strings escape `\` and `"`, and `%` is sent as `%25`. Folders in a URL are encoded part by part, so a `;UID=` inside a name stays part of the name.

## Deleting and moving

`delete` removes exactly the given uids: `\Deleted` on those uids, then `UID EXPUNGE` of the same uids (UIDPLUS). A plain `EXPUNGE` would also remove every other message someone marked deleted in that folder, so a server without UIDPLUS is refused. `move` uses MOVE when the server has it, else COPY plus the same exact expunge. Both read the folder back (`verified`). The guard asks the user before `delete` (rule `pikkumies-delete:`).

## Attachments and the archive

`fetch` saves attachments only into a new directory (`tools/email/lib/MailPath.php`); names are decoded, cut to their last part and reduced to plain characters. `archive` streams each message into `Maildir/tmp` within a 50 MiB cap and links it into `new/` (`tools/email/lib/Maildir.php`). Its progress (uidvalidity and last uid per folder) is in `pikkumies/local/state/email-archive/`; a changed uidvalidity starts a new directory, so nothing is overwritten.

## Tests

`tests/EmailImapTest.php`, `tests/EmailFetchTest.php` and `tests/EmailArchiveTest.php`, against a scripted fake transport (`tests/fixtures/email/`); no network.
