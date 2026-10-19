# antispam

The user's own spam rules over their IMAP mailbox ([ADR-0053](../../../../docs/adr/0053-email-category.md)). It uses the `imap` login and `tools/email/lib/MailImap.php`; it has no login of its own and runs no filter on the server.

- **What the provider said.** Only the first `Authentication-Results` header is read: the receiving server adds it on top, and any lower one may have been written by the sender. SPF, DKIM and DMARC results come from it; `X-Spam-Flag` and `X-Spam-Status` give the provider's own spam verdict when it sets them.
- **The user's rules.** Senders, domains (subdomains included) and subject words, in `pikkumies/local/state/email-rules.json`. Rules are added or removed only by the verbs.
- **Score.** SPF fail or softfail 1, DKIM fail 1, DMARC fail 2, the provider says spam 3, each matching rule 5; a message scoring 3 or more is spam. Deterministic, no model call.
- **Apply moves, never deletes.** Spam goes to the Junk folder with MOVE, or COPY plus `UID EXPUNGE` of exactly those uids; a server with neither MOVE nor UIDPLUS is refused. Scanning reads only headers and sets unseen messages back to unseen.

## Tests

`tests/EmailAntispamTest.php` and `tests/EmailReadTest.php`, against a scripted fake transport (`tests/fixtures/email/`); no network.
