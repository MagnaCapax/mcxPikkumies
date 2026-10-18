---
name: pikkumies
description: Ask Pikkumies, the helper agent installed on a remote Linux server, to check or do something on that server, over SSH. Use when the user wants something looked at or done on a server where Pikkumies is installed (disk, quota, torrents, containers, *arr apps, backups, performance, "what is going on"), or names Pikkumies.
---

# Pikkumies over SSH

Pikkumies runs on the server, as the account it is installed in, and does the work there with its own tools. You pass the user's question to it and bring the answer back. You do not need to know its tools.

## Target

The SSH target is `user@host` or an alias from `~/.ssh/config`, for the account Pikkumies is installed in. If the user has not named it in this conversation or in their instructions, ask once. Always use `-o BatchMode=yes`: no password prompts, no TTY.

## Ask a question

Send the question on stdin, never inside the remote command, so quotes, `$(...)` or backticks in it are never run by the remote shell:

```sh
ssh -o BatchMode=yes TARGET 'q=$(cat); ~/.local/bin/pikkumies -- "$q" </dev/null' <<'EOF'
Which of my torrents are stalled, and why?
EOF
```

Without a terminal Pikkumies answers once and exits. Its final answer is the last box in the output; the `┊` lines above it are the commands it ran. Keep the `Session:` id from the end of the output.

## Follow up in the same session

```sh
ssh -o BatchMode=yes TARGET 'read -r id; q=$(cat); ~/.local/bin/pikkumies run chat --resume "$id" -q "$q" </dev/null' <<'EOF'
SESSION_ID
Which of those is the oldest?
EOF
```

Read the id with `read -r`, not `head -1`: `head` on a pipe can swallow the question, and an empty `-q` starts an interactive session that exits at once.

## Deletes and other risky steps

In a one-question run nobody can answer Pikkumies' approval prompt, so it refuses deletes, exposing ports, stopping every container and the other steps its guard asks about. It then says what it would have run.

- Show the user that exact command and what it does. Run it yourself over SSH only after the user says yes to that command.
- Never turn the approvals off to get past this: no `--yolo`, no `approvals.mode: off`, no `approvals.*_mode: approve` in its config.

## Cost and privacy

- Every question spends the account's model credits; a question that runs a few checks is tens of thousands of credits on a platform key. Ask one clear question rather than many small ones. `~/.local/bin/pikkumies doctor` shows the credits left when the provider reports them.
- What Pikkumies reads goes to its model provider. Ask it about what the user asked about, not about unrelated files or logs.
- Never read or print `~/.virsilipasKey`, `~/.pikkumiesKey` or `~/pikkumies/local/secrets/`.

## When it does not answer

Run `~/.local/bin/pikkumies doctor` and show the user the lines that are not ready (provider, key, Hermes still building). If `~/.local/bin/pikkumies` is missing, Pikkumies is not installed in that account; the install is one line, in the project README.
