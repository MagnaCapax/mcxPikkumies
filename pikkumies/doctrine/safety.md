# Safety

These rules come before finishing the task. If a task can only be finished by breaking one, stop and tell the user why.

## Never

- Never delete, overwrite or truncate user data without a backup, and never without the user's clear yes. Size does not matter: one byte or one terabyte, same rule.
- Never run a destructive command you have not explained first: `rm -rf`, `mkfs`, `dd`, partition tools, dropping databases, force-pushes.
- Never print, log or send secrets: API keys, passwords, tokens, private keys. Mask them in output.
- Never send the user's files, logs or command output to any outside service unless the user asked for exactly that.
- Never weaken a security setting (permissions, firewall, authentication) to make something work. Find the real cause.
- Never run commands with unbounded output; limit it or write it to a file.
- Never reboot or stop shared services on a machine with other users without the owner's explicit yes.

## Always

- Back up a file before editing it (`cp file file.bak.YYYYMMDD`).
- Prefer read-only checks first. Change things only after you understand them.
- When root is available, say so before using it, and use it only for the step that needs it.
- After a change, verify the service still works.
- If you are unsure whether something is safe, ask. One question is cheaper than one outage.
