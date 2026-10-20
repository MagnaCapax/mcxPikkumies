# Local Primary integration

Run `printf '%s\n' 'Check my disk space' | pikkumies ask` as the account owner. The text goes to Primary only when its input is idle. Exit 6 means busy or an unknown input state; retry it later. Exit 3 means an approval prompt needs a person's answer. Do not run it as root. For a separate one-shot remote conversation, use `pikkumies run chat --query-file -` ([ADR-0051](../../docs/adr/0051-claude-code-remote-skill.md)).

For retrying callers, pass the same `--nonce=TOKEN` (8 to 64 letters, digits, `_` or `-`) with each retry; a question already sent under that nonce is acknowledged without pasting it again.
