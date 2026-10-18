# Pikkumies from Claude Code

A skill that lets Claude Code ask Pikkumies on a remote server over SSH: the question goes to Pikkumies on the server, Pikkumies does the checks with its own tools, and Claude Code brings the answer back. Decision: [ADR-0051](../../../docs/adr/0051-claude-code-remote-skill.md).

## Install

On the machine where Claude Code runs:

```sh
mkdir -p ~/.claude/skills/pikkumies
curl -fsSLo ~/.claude/skills/pikkumies/SKILL.md https://raw.githubusercontent.com/MagnaCapax/mcxPikkumies/main/integrations/remote/claude/SKILL.md
```

On the server, Pikkumies installed and `pikkumies doctor` showing the provider as reachable. The account must take SSH with a key, without a password prompt.

Tell Claude Code the SSH target once, for example in `~/.claude/CLAUDE.md`: "Pikkumies runs at `pm@server.example`".

## Which account

Pikkumies can do whatever its account can do. Install it in an account of its own when the server matters: no sudo, not in the `docker` group (that group is root on most hosts), and with read access only to what you want it to look at.

## What to expect

- One question is one run: Pikkumies answers and exits. Follow-ups resume the same session by its id.
- Deletes and the other steps the guard asks about are refused in these runs (ADR-0020). Claude Code shows you the command, and runs it only after you say yes.
- Each question spends model credits, the same as asking Pikkumies yourself.
