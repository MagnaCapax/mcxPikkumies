# ADR-0020: Deleting data needs the user's approval in the chat

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. Changes the guard hook from block-only to block or approve, and supersedes the ADR-0005 guard bullet "Escalation is not available to shell hooks in this version" (true of Hermes 0.19.0, not of the pinned v2026.9.24).

## Context
The new tools can delete data the user cannot get back: a torrent's files, an rclone sync or move to a remote, a restic or borg prune. The agent reads text written by others (torrent names, *arr titles, media metadata, documentation), and such text can try to steer it. A `--yes` flag does not stop that, because the model would pass the flag itself.

Facts checked 2026-10-14 in Hermes v2026.9.24:
- A `pre_tool_call` hook may answer `{"action": "approve", "message", "rule_key"}`; Hermes then asks the user through its human-approval gate (`agent/shell_hooks.py`, `tools/approval.py` `request_tool_approval`). The model cannot answer the prompt, and the smart (model-judged) approval step is not used for it.
- "session" and "always" answers are remembered by `rule_key`; without one Hermes uses the tool name, so one answer would approve every later delete (`hermes_cli/plugins.py`).
- The prompt shows the hook's message, not the command.
- In cron, single-query (`-q`) and unattended runs the gate refuses by default (`approvals.*_mode: deny`); with yolo or `approvals.mode: off` it lets the call through.

Today `guard-destructive.php` only blocks, and the user has to run the command themselves.

## Options Considered
- **Block every delete; the user runs it.** Safe, but the agent cannot finish a task the user asked for.
- **Trust `--yes`.** The model can be talked into it.
- **Ask the user through Hermes' approval gate.** The user sees the exact command and decides; the model cannot answer for them.

## Decision
- `guard-destructive.php` answers `approve`, with the whole command in the message and `rule_key` = `pikkumies-delete:` + a hash of the exact command, for the cases below. Hermes shows the user only that message, so a command longer than 4,000 characters is blocked instead of shown in part. The patterns read the whole command: a quote, `;` or `&` inside an argument cannot hide the deleting part, and a false match only means one extra question.
  - tool-unit calls that delete data, by one convention: the `--with-data` flag, or a verb named `prune`, `forget` or `purge`;
  - the same operations run directly, also in quoted or list form: `rclone sync|bisync|move|purge|delete`, `restic forget|prune`, `borg prune|delete|compact`, `kopia snapshot expire`, `duplicacy prune`;
  - HTTP calls to this machine that delete data, such as qBittorrent `deleteFiles=true`, *arr `deleteFiles`, Transmission `delete-local-data`, `curl -X|--request DELETE` and `requests.delete(...)`.
- It inspects the `terminal` command and the code passed to `execute_code`, and it keeps blocking what it blocks today.
- A regex engine error answers `approve` (or `block` when the command is too long to show), never "allow". The hook is registered with `fail_closed: true`, so a crash or timeout of the hook blocks the call instead of letting it run.
- `--yes` stays as the confirmation a person types when running a tool by hand.

## Consequences
- The agent can do what the user asks, and every irreversible step it is caught on is shown to the user, command included. A user who turned approvals off (yolo, `approvals.mode: off`) has chosen to skip this; cron and other unattended runs cannot delete through these patterns at all.
- This is a safety prompt, not a security boundary. The user owns the hook and the config, and the agent could reach an API another way; the real limit is that the agent can only touch what the user's account can touch.
- Many small deletes mean many approvals. The tools should offer one call for a batch, so the user approves once and sees the whole list.

## References
- ADR-0005 (Hermes wiring), ADR-0015, ADR-0019
