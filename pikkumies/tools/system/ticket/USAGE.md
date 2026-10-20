# ticket

One Pulsed Media support ticket, opened with the Pulsed Media AI key ([ADR-0089](../../../../docs/adr/0089-support-tickets-with-the-ai-key.md)). `draft` saves exactly what would be posted under `~/pikkumies/local/state/ticket/<id>.json` and shows it; nothing leaves the machine. `send` posts it as JSON to `https://pulsedmedia.com/remote/ticket.php` with the key from `~/.virsilipasKey` as the Bearer token, only after the user says yes in the chat (hook `guard/ticket-send`, the orchestrator's ask policy, [ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)).

| verb | arguments | changes | what it does |
|---|---|---|---|
| draft | `--subject S (--text T \| --text-file F) [--email E] [--priority Low\|Medium\|High] [--service S \| --no-service] [file ...]` | yes | Saves the draft, once, mode 0400, named by its own sha256 (the first 32 hex digits are the id). Subject one line of 1-200 characters, message 1-20,000 characters; common password and key forms are removed from both first (`lib/Redact.php`). `--email`: the email of the Pulsed Media account (the owner, or a user who may open tickets), needed once and remembered in `~/pikkumies/local/state/ticket/email`; without it the remembered one is used. Priority defaults to Medium. Service: on a Pulsed Media seedbox it defaults to this Unix user; elsewhere it is left out unless `--service` names it (a service number or username); `--no-service` leaves it out. Files: at most 3, each a regular file of this account (no symlink), at most 1 MiB, of type txt, log, png, jpg, jpeg or pdf (an image or PDF must start as one); a txt or log file has the same password and key forms removed; names are cut to letters, digits, `.` `_` `-` and space. The files are read now: the draft holds what goes. One record: `id`, `to`, `email`, `subject`, `message_start`, `message_chars`, `priority`, `service`, `attachments` (`name`, `bytes`), `bytes`, `path`. |
| send | `<id>` | sends | Posts the draft. One record on success: `id`, `ticket` (the ticket number), `note`; the draft becomes `<id>.sent.json` and is never sent again. A refusal says what happened and whether sending the same draft again can help: 400 malformed (no), 401 "the key or email was not accepted" (the server answers the same whatever did not match; no), 413 too large (no), 429 too many (later), 503 unavailable (later); no answer or another 5xx: it may or may not have been opened, so check for the new-ticket email first. Nothing retries by itself. Refuses a draft whose content no longer matches its id or carries unknown fields, a draft already sent or being sent, a key that is not a Pulsed Media `vsk-` key, and an address that is not https. |
| list | | no | The drafts kept here, newest first: `id`, `made` (UTC), `subject`, `files`, `sent`. |

```sh
php ~/pikkumies/pikkumies/tools/system/ticket/ticket.php draft --subject "rTorrent stops overnight" --text "Since Monday rTorrent stops around 3am; restarting it helps until the next night." --email you@example.com
php ~/pikkumies/pikkumies/tools/system/ticket/ticket.php draft --subject "Web UI login fails" --text-file ~/ticket.txt --priority High ~/rtorrent-error.log
php ~/pikkumies/pikkumies/tools/system/ticket/ticket.php list
php ~/pikkumies/pikkumies/tools/system/ticket/ticket.php send 0123456789abcdef0123456789abcdef
php /home/USER/pikkumies/pikkumies/tools/system/ticket/ticket.php send 0123456789abcdef0123456789abcdef
```

- The send path must be absolute (as in the last line, with your own home directory) or start with `~/`, and point at this Pikkumies' own tool with no symlink in it; any other form is blocked.
- With approvals off (`HERMES_YOLO_MODE`, set by `--yolo`) or at `PIKKUMIES_DEPTH` 2 or more, the agent's `send` is blocked: nobody would be asked. Run the send yourself in your own terminal. A question that would not fit what the ask review can show in full (a very long email or file names) is blocked the same way.
- A ticket opened this way carries no authority for account changes: for a cancellation, refund, transfer, or a change of the account email or owner, support asks for a client-area login.
- Drafts stay until you remove them (`rm ~/pikkumies/local/state/ticket/<id>.json`). The remembered email: `~/pikkumies/local/state/ticket/email`; pass `--email` again to change it.
