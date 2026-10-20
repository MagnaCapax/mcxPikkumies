# smtp

Sends mail as the user through their own SMTP relay over TLS ([ADR-0053](../../../../docs/adr/0053-email-category.md)). It works with any provider that offers SMTP on port 465 (TLS) or 587 (STARTTLS); port 25 is never used. The user types the relay at `login`.

## Why sending takes two steps

An agent that reads mail can be talked into sending mail. So the step that sends to someone else runs only when what the user asked for covers exactly this message; otherwise the safety check refuses it ([ADR-0101](../../../../docs/adr/0101-refuse-and-report-never-ask.md)), and what the check reviewed is what is sent:

1. `send` checks the addresses (plain addresses only, no control bytes or `<>`), the subject (one line, no control bytes), the body file and each attachment (`tools/email/lib/MailPath.php`: regular files inside the home, not in a dot-directory such as `~/.ssh`, not in `local/secrets/`, not named or shaped like a key), and writes one JSON draft, `pikkumies/local/state/email-outbox/<sha256>.json`. Each attachment is recorded with its real path, size and sha256.
2. `send-draft <sha256>` is the verb the guard checks (`hooks/guard/guard-destructive/GuardMail.php`). The guard reads that draft and builds a message with From, every recipient, the subject, each attachment's path and size and the first 1 KiB of the body; the rule key is `pikkumies-mail:<sha256>`. The orchestrator's ask policy runs the call when the user's own words cover it, and otherwise refuses it with a fixed message, reported, never asked ([ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md), ADR-0101). The unit then re-hashes the draft and every attachment; any change refuses. The envelope is built only from the checked recipient list.
3. The guard refuses shell expansion and chaining in any smtp command, so no variable, glob or second command changes what runs after the check.

`send-self` and `test` go only to the user's own addresses, stored inside the login secret, which only `login` can change. `send-self` passes without the safety check, so a scheduled job can mail the user a report. `test` sends to the user's own first address too, but still goes through the ask policy; a scheduled job is checked against what the user asked when it was made, and one with no record of their words is refused.

## The message

`smtp/SmtpMime.php` builds it with CRLF line ends: From (the login's address), To, Subject (RFC 2047 when not ASCII), Date, Message-ID; text as quoted-printable UTF-8; attachments base64 with a plain filename and an RFC 2231 `filename*` for other names. curl dot-stuffs the body. 20 MiB encoded at most (base64 makes attachments about 1.37 times larger).

## Tests

`tests/EmailSmtpTest.php` against a scripted fake transport (`tests/fixtures/email/`), and `tests/EmailTransportTest.php`, which also checks against a plaintext fake server on 127.0.0.1 that no login is sent without TLS.
