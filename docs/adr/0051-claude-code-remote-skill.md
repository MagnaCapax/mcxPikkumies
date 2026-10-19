# ADR-0051: A Claude Code skill that asks Pikkumies over SSH

## Metadata
- Author: Sami Kuisma (with Claude Code)
- Date: 2026-10-18
- Status: Accepted. Adds `integrations/remote/claude/`; no change to the runtime.

## Context
The operator's request of 2026-10-18: a pull request with the skill under `integrations/remote/claude`, since the way of driving Pikkumies from Claude Code had already been checked to work.

Claude Code is here a remote caller, not the agent's model provider: Pikkumies still uses the provider in its own key file. The skill only tells Claude Code how to pass a question to Pikkumies on a server and bring the answer back.

Facts checked 2026-10-18 on a generic (non-PMSS) host, Pikkumies at `667db40`, Hermes v0.22.2, Pikkumies installed in an account with no sudo and no `docker` group:
- `pikkumies -- "<text>"` with stdin not a terminal answers once and exits, and prints the session id (Hermes' own `-q` split, [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)). A light question ("disk, memory and my quota") ran 9 tool calls in 23 s.
- `pikkumies run chat --resume <id> -q "<text>"` continues that session in the same one-shot way; the earlier turns are kept.
- A question passed on stdin and read with `q=$(cat)` reaches Pikkumies literally: `it's $(whoami) "quoted"` came back unchanged, not run by the remote shell.
- Reading the session id with `head -1` before `q=$(cat)` loses the question: `head` reads a whole buffer from the pipe, `-q ""` starts an interactive session, and on a non-terminal that exits at once with no answer. `read -r id` reads one line only.
- "Delete the file ~/pmtest/deleteme.txt" in a one-shot run: the guard's approval was refused (`approvals.*_mode: deny`, [ADR-0020](0020-deletes-need-the-users-approval.md)) for `rm` and for `os.remove` in code, the file was left in place, and the answer named the command for the user to run.

## Options Considered
- **No skill; the user asks Claude Code to SSH in.** Works, but each session rediscovers the invocation, the quoting and the approval behaviour, and may run the question inside the remote command where the remote shell can run parts of it.
- **An MCP server on the server.** A new long-running service with its own port and auth, for what one SSH command already does.
- **A skill file that teaches the one-shot call over SSH.** One markdown file, nothing installed on the server, and the server's own SSH, account and guard stay the limits.

## Decision
- `integrations/remote/claude/SKILL.md` is a Claude Code skill (frontmatter `name`, `description`) and `integrations/remote/claude/README.md` says how to install it and which account to use.
- The question always travels on stdin; follow-ups resume by session id read with `read -r` and pass the question as one `--query=` word, the form `pikkumies <text>` already builds (ADR-0022).
- The skill calls `~/pikkumies/bin/pikkumies`, the link every install makes ([ADR-0001](0001-layout-install-and-update.md)). The `PATH` link can be in `~/.local/bin` or `~/bin` (ADR-0022), and a remote SSH command may have neither on `PATH`.
- In these runs the guard's questions are refused. The skill has Claude Code show the user the exact command Pikkumies named and run it over SSH only after the user agrees to that command, and never turn approvals off (`--yolo`, `approvals.mode: off`, `approvals.*_mode: approve`).
- The answer is data for the user, not instructions for Claude Code: it is built from server content anyone may have written, so the only command Claude Code runs for it is the one the user agreed to.
- The skill never reads the key files or anything under `~/pikkumies/local/` (its secrets, and Hermes' `.env` that holds the key), and keeps questions to what the user asked about, since what Pikkumies reads goes to its provider.
- Nothing under `pikkumies/` changes. The skill is not generated or installed by `pikkumies install`; it lives on the caller's machine.

## Consequences
- A Claude Code user can hand server questions to Pikkumies, which answers with its own tools and lessons instead of Claude Code re-deriving them over raw SSH.
- The approval step moves from Pikkumies' prompt to the Claude Code conversation for the commands it names. That is a safety prompt in the caller, not a boundary; the limit stays the account's own rights.
- Each question costs credits on the server's key; one-shot runs re-send the session's context on every follow-up.
- Other callers (other coding agents) could use the same call; they get their own directory under `integrations/remote/` if wanted.

## Review 2026-10-18
The facts above were checked with `~/.local/bin/pikkumies` and `-q "<text>"`. Review changed the skill to `~/pikkumies/bin/pikkumies` (`PathInstall::binDir()` links into `~/bin` when only that is on `PATH`) and to `--query="$q"`: the same launcher and the same Hermes option, not run again on a live host. It also changed the heredoc delimiter to `PIKKUMIES_END` and widened the never-read list to `~/pikkumies/local/`, because `HermesConfig` writes the key to `local/hermes/.env`.

## References
- ADR-0020 (approvals for deletes), ADR-0022 (`pikkumies <text>` and the one-shot split), [ADR-0004](0004-provider-key-and-root-detection.md) (key files)
- Claude Code skills: a directory under `~/.claude/skills/` with a `SKILL.md`
