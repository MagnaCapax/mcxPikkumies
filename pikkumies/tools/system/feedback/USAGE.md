# feedback

Feedback to Pulsed Media with the conversation that explains it (ADR-0056). `draft` saves exactly what would be sent under `~/pikkumies/local/state/feedback/<id>.json` and shows it; nothing leaves the machine. `send` locks the draft with Pulsed Media's public key (`config/feedback-public-key.txt`, a libsodium sealed box) and posts it to `https://pikkumies.mcx.fi/v1/feedback` with the Pulsed Media AI key from `~/.virsilipasKey`, which links it to the account, only after the user approves it in Pikkumies' approval prompt (hook `guard/feedback-send`).

| verb | arguments | changes | what it does |
|---|---|---|---|
| draft | `--text T \| --text-file F [--no-conversation]` | yes | Saves the draft, once, mode 0400, named by its own sha256 (the first 32 hex digits are the id): the text (at most 20,000 bytes), the current conversation from Hermes' `sessions export --redact` (its newest 1.5 MB, then Pikkumies' own redaction on top, then fitted to 1.5 MB), the session id and title, and the Pikkumies, Hermes and PMSS versions. The conversation's header also carries the session's model, provider and working directory. Common password and key forms are removed from the text and the conversation; read the draft before sending. Also removes export directories older than 10 minutes that a killed draft left behind. One record: `id`, `bytes`, `text_bytes`, `conversation`, `conversation_bytes`, `older_part_left_out`, `session_id`, `session_title`, `text_start`, `path`. |
| send | `<id>` | sends | Seals the draft and posts it. One record on success: `id`, `received_id` (Pulsed Media's id for it; quote it in a ticket to have it deleted), `received_at`, `note`; the draft becomes `<id>.sent.json` and is never sent again. On any failure the draft stays and the error says whether trying again can help (not after a 4xx other than 429); nothing retries by itself. Refuses a draft whose content no longer matches its id or carries unknown fields, a draft already sent, a key that is not a Pulsed Media `vsk-` key, and a PHP without sodium. |
| list | | no | The drafts kept here, newest first: `id`, `made` (UTC), `bytes`, `conversation`, `sent`. |

```sh
php ~/pikkumies/pikkumies/tools/system/feedback/feedback.php draft --text "The qBittorrent tool times out when I add a magnet link"
php ~/pikkumies/pikkumies/tools/system/feedback/feedback.php draft --text-file ~/feedback.txt
php ~/pikkumies/pikkumies/tools/system/feedback/feedback.php list
php ~/pikkumies/pikkumies/tools/system/feedback/feedback.php send 0123456789abcdef0123456789abcdef
php /home/USER/pikkumies/pikkumies/tools/system/feedback/feedback.php send 0123456789abcdef0123456789abcdef
```

- The send path must be absolute (as in the last line, with your own home directory) or start with `~/`, and point at this Pikkumies' own tool with no symlink in it; any other form is blocked.
- The conversation comes from the session Hermes runs this command in (`HERMES_SESSION_ID`). A command can set that variable too, so the draft and the approval prompt show the session id and title: check they are the conversation you mean. It is exported into a private directory under `local/state/feedback/` and deleted after reading.
- Drafts stay until you remove them (`rm ~/pikkumies/local/state/feedback/<id>.json`; Pikkumies asks before a delete).
- Pulsed Media keeps what is sent until you ask for it to be deleted; the Pulsed Media privacy policy says who reads it and how.
