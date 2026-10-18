---
name: smtp
description: "Send mail as the user through their own SMTP relay over TLS: write a draft, then send it after the user approves what Hermes shows; send to the user's own addresses."
---

# smtp

Use this when the user asks to send an email. Mail cannot be recalled, so every message to someone else is shown to the user and approved first.

1. **No login yet?** `status` says `login: false`. Tell the user to run `php <this script> login` in their own terminal: relay server, port (465 or 587), user, password (hidden; often an app password), the From address and their own addresses. Never ask for the password in the chat and never run `login` yourself.
2. **Run each smtp command on its own**, with the full script path from this skill and plain arguments. No `$`, `~`, globs, `;`, `|`, `&`, redirects or backslashes: the guard refuses them. Put a subject with spaces in single quotes; write the body to a file first.
3. **Send to someone**: `send --to a@example.org --subject '...' --body-file /full/path [--attach /full/path]...` writes a draft and prints its `draft` id. Then run `send-draft <id>`: Hermes shows the user From, every recipient, the subject, each attachment and the start of the body, and the user decides. If the user says no, do not try another way.
4. **To the user themselves** (a report, a reminder, also from a scheduled job): `send-self --subject '...' --body-file /full/path`. It goes only to the user's own first address and needs no question.
5. **`test`** sends a short message to the user's own first address, after the user approves.
6. Attach only files the user named. Files in dot-directories (such as `~/.ssh`), stored passwords and keys are refused; 20 MiB in total at most.
