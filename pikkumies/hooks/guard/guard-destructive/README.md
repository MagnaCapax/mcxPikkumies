# guard-destructive

The delete guard (ADR-0020). Before each `terminal` or `execute_code` call it checks the command:

- **Blocked**, with a message telling the agent to show the user the command: recursive force deletes, filesystem formats, raw device writes, partition changes, world-writable permissions, fork bombs, force pushes, a delete verb renamed through an alias or a variable, base64-decoded text run by a shell, and reading stored passwords (`local/secrets/`, `rclone config show|dump`). Quotes and backslashes inside a word do not hide a verb (`r"m"` is `rm`).
- **Asks the user** (`approve`, with the whole command in the message and a `rule_key` of that exact command): deletes the user may want, such as backup prunes, `rclone sync|move|purge`, torrent removes with data, `*arr` and media server deletes, plain `rm`, emptying a file (`: > f`, `cp /dev/null f`, `mv f /dev/null`, `xargs rm`), and the Pikkumies tool verbs that carry `--with-data`, `--expect` or `--replace`. A command longer than 4000 characters is blocked instead, because the user must see all of it.
- Anything else: no answer, and the call runs.

It fails closed: when it cannot answer, or cannot read the tool call it was given, the call is blocked. This is a safety prompt for the agent's own mistakes, not a security boundary; real limits come from the OS.
