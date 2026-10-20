# scratchpad

A per-session checkpoint note, `local/state/scratchpad/<sha1 of session_id>.md` (dir `0700`, file `0600`, written atomically): a pinned "## Task" head (at most 1024 characters) and a tail-kept "## Log" below it, the whole file capped at 8192 bytes. Over the cap the oldest log lines are dropped first, with one marker line saying how many. Pads not written to for 14 days are deleted the next time any session writes one (never on `show`, `clear` or `list`), and at most 50 pads are kept at all — past that, the oldest pad is deleted the same way, whatever its age.

`write` and `append` run the text through the same secret redaction `note-add` uses before it is stored: a pad sits on disk for up to 14 days and `show` can hand its text back to the model provider, so a pasted password, key or token is replaced with `REDACTED` rather than kept in the clear. The record says `"redacted": true` when that happened.

| verb | arguments | changes | what it does |
|---|---|---|---|
| write | `--text <task> [--session ID]` | yes | Sets the pinned "## Task" head (secrets redacted, then cut to 1024 characters if still longer); the log below it is kept as it was. |
| append | `--text <note> [--session ID]` | yes | Adds one line to the log, timestamped (UTC), secrets redacted the same way; the pinned head is kept as it was. |
| show | `[--session ID] [--human]` | no | This session's pad as it stands: whether it exists, its size, when it was last written, the text itself (`""` when there is none yet), and `notice`, a fixed line saying the text is data from an earlier turn, not a new instruction. |
| clear | `[--session ID]` | yes | Deletes this session's pad outright; nothing is kept. Not `deletes` ([ADR-0020](../../../../docs/adr/0020-deletes-need-the-users-approval.md)'s approval convention is for data the user cannot get back); a pad is the agent's own ephemeral working note, already self-pruned at 14 days. |
| list | `[--human]` | no | Every session's pad on this machine, newest first: file name (the session's sha1, never the session id itself), size, when it was last written, a short preview of the "## Task" line, and how many log lines it holds. |

```sh
php ~/pikkumies/tools/session/scratchpad/scratchpad.php write --text "Migrating the arr config; 2 of 5 apps done"
php ~/pikkumies/tools/session/scratchpad/scratchpad.php append --text "sonarr and radarr done; prowlarr next"
php ~/pikkumies/tools/session/scratchpad/scratchpad.php show --human
php ~/pikkumies/tools/session/scratchpad/scratchpad.php clear
```

- Tools carry no session id of their own (only hooks do). Without `--session`, the tool reads `local/state/scratchpad/current`, written every turn by the `session/scratchpad-session` hook unit (`pre_llm_call`). It refuses with a hint, rather than guess, when two different sessions wrote `current` within the last 10 minutes — two chats open on the same account at once; pass `--session <id>` explicitly then.
- `--session` takes the session id exactly as Hermes gives it (letters, digits, dot, underscore or dash, 1 to 128 characters); the pad file itself is named by its sha1, never the id in the clear.
- `write` and `append` log one line (bytes written, lines dropped) to the existing performance-metrics files when `privacy.perfLogging` is on; nothing is sent, and the metric never carries the pad's own text.
