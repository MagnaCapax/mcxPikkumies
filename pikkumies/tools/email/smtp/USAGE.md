# smtp

Sends mail as the user through their own relay, TLS only: port 465, or 587 with STARTTLS required ([ADR-0053](../../../../docs/adr/0053-email-category.md)). Shared rules: `tools/email/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| login | | yes | The user types, at a terminal: relay server, port (465 or 587), user name, password (hidden), the From address (default: the user name) and their own addresses for send-self and test (default: the From address). Tried with a NOOP, then stored in `pikkumies/local/secrets/email-smtp` (0600). |
| status | | no | The stored login: `login`, `host`, `port`, `user`, `from`, `allowlist`; never the password. No connection is made. |
| send | `--to A[,B...] --subject S --body-file F [--attach F]...` | yes (a draft file) | Checks everything and writes the draft `pikkumies/local/state/email-outbox/<draft>.json`; sends nothing. Prints `draft` (64 hex characters), `from`, `to`, `subject`, `attachments` (`path`, `bytes`), `body_bytes` and the `next` command. At most 50 recipients; body: a UTF-8 file of at most 1 MiB; 20 MiB encoded in total. |
| send-draft | `<draft>` | sends | Sends that draft. The guard first shows the user From, every recipient, the subject, each attachment's path and size and the first 1 KiB of the body, and asks. The draft and every attachment are re-hashed first; any change refuses. The draft moves to `email-outbox/sent/`. Prints `sent`, `from`, `to`, `subject`, `bytes`. |
| send-self | `--subject S --body-file F [--attach F]...` | sends | Sends to the user's own first address from the login, without a question (also from a scheduled job). The same checks as send. |
| test | | sends | Sends a short test message to the user's own first address. Asks the user first. |

```sh
php /home/you/pikkumies/tools/email/smtp/smtp.php status
php /home/you/pikkumies/tools/email/smtp/smtp.php send --to friend@example.org --subject 'Photos from Saturday' --body-file /home/you/letter.txt --attach /home/you/photos/beach.jpg
php /home/you/pikkumies/tools/email/smtp/smtp.php send-draft 6f1c0e8a3d2b4c5e9f7a1b2c3d4e5f60718293a4b5c6d7e8f9a0b1c2d3e4f5a6
php /home/you/pikkumies/tools/email/smtp/smtp.php send-self --subject 'Nightly report' --body-file /home/you/report.txt
```

- Use the script's full path, as the generated skill prints it: the guard refuses `$`, `~`, `*`, `?`, `[`, `{`, `;`, `|`, `&`, `<`, `>`, `\` and line breaks anywhere in an smtp command outside single quotes, so the command the user approves is the command that runs.
- From is always the login's From address. Addresses are plain (`name@example.org`), without a display name or `<>`.
- Every verb prints JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
