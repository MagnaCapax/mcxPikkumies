# imap

Reads and tidies the user's own IMAP mailbox over TLS (port 993), and archives it into local Maildirs ([ADR-0053](../../../../docs/adr/0053-email-category.md)). It works with any mail provider; nothing is detected on the machine. The user types the server at `login`.

## How it talks to the server

PHP's curl extension through `tools/email/lib/MailTransport.php`: TLS 1.2 or newer, certificate and host name verified, curl pinned to the address that was checked, and a port on this machine used only when this account owns it. Bodies and headers come through curl's URL form (`;UID=n;SECTION=...`), because curl drops message text from answers to typed commands. That URL form sets `\Seen`, so every verb that reads sets an unseen message back to unseen: reading never changes the mailbox. This also happens when a later read fails; if setting the flag back fails too, the read's own error is shown and its hint names the uids left marked read. The flags are read before the reads, so a message you open in another mail client while a verb runs is set back to unread: during one `list`, `search` or spam `scan`, and during that one message's read in `archive`, which sets each message back right after reading it.

## Commands that cannot be bent

libcurl URL-decodes a typed IMAP command before refusing control bytes, so `%22` would arrive as `"`. `tools/email/lib/MailImap.php` therefore checks every argument first: a uid matches `^[1-9][0-9]{0,9}$`, folder names and search words are printable ASCII, quoted strings escape `\` and `"`, and `%` is sent as `%25`. Folders in a URL are encoded part by part, so a `;UID=` inside a name stays part of the name.

## Deleting and moving

`delete` removes exactly the given uids: `\Deleted` on those uids, then `UID EXPUNGE` of the same uids (UIDPLUS). A plain `EXPUNGE` would also remove every other message someone marked deleted in that folder, so a server without UIDPLUS is refused. `move` uses MOVE when the server has it, else COPY plus the same exact expunge. Both read the folder back (`verified`). The guard asks the user before `delete` (rule `pikkumies-delete:`).

## Attachments and the archive

`fetch` saves attachments only into a new directory (`tools/email/lib/MailPath.php`); names are decoded, cut to their last part and reduced to plain characters. `archive` streams each message into `Maildir/tmp` within a 50 MiB cap and links it into `new/` (`tools/email/lib/Maildir.php`). Its progress (uidvalidity and last uid per folder) is in `pikkumies/local/state/email-archive/`; a changed uidvalidity starts a new directory, so nothing is overwritten. The directory is named from the whole folder path (`Work/2023` as `Work.2023-<8 hex>`), so two folders with the same last part never share one; a shared directory made them skip each other's messages as already archived. A folder archived before this naming starts again from uid 0 in its new directory and reports the old one as `previous_dir`, which is kept as it is: until the new directory is complete the folder takes about twice the space, and the old directory can be deleted once it is.

## Tests

`tests/EmailImapTest.php`, `tests/EmailArchiveTest.php` (fetch and archive), `tests/EmailReadTest.php` and `tests/EmailMaildirTest.php`, against a scripted fake transport (`tests/fixtures/email/`); no network.
