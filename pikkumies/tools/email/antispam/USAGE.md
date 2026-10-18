# antispam

The user's own spam rules over their IMAP mailbox, with the `imap` login (ADR-0053). Shared rules: `tools/email/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| rules | | no | One record per rule: `kind` (`from`, `domain`, `subject-word`), `value`. Stored in `pikkumies/local/state/email-rules.json`. |
| rule-add | `--from A \| --domain D \| --subject-word W` | yes | Adds one rule (lower case). A domain also matches its subdomains; a subject word matches anywhere in the subject. Status `verified`. |
| rule-remove | `--from A \| --domain D \| --subject-word W` | yes | Removes one rule. Status `verified`. |
| scan | `[--folder F] [--limit N]` | no | The newest N messages (default 20, at most 200; `--limit 0` means 200), newest first: `uid`, `from`, `subject`, `spf`, `dkim`, `dmarc` (from the first Authentication-Results header), `provider_spam` (X-Spam-Flag / X-Spam-Status, or null), `rules` (matching), `score`, `spam`. Status `spam` (count). Unseen messages stay unseen. |
| apply | `[--folder F] [--limit N] [--to FOLDER]` | yes | Runs scan and moves the messages it calls spam to `--to` (default `Junk`, which must exist). Never deletes. Records: the moved messages. Status `moved`, `to`, `verified`. |

Score: SPF fail or softfail 1, DKIM fail 1, DMARC fail 2, the provider says spam 3, each matching rule 5. Spam from 3.

```sh
A=~/pikkumies/tools/email/antispam/antispam.php
php $A rules
php $A rule-add --domain promo.example
php $A scan --limit 50
php $A apply --limit 50 --to Junk
```

- Every verb prints JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
