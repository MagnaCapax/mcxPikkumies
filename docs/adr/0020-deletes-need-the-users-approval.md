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

## Amendment 2026-10-14: the user's own files, stored passwords, and Hermes' smart mode
Facts checked in the pinned Hermes v2026.9.24: the default `approvals.mode` is `smart` (`hermes_cli/config_defaults.py:1656`), where a second model decides whether a command on Hermes' dangerous list runs and is told to approve "safe file operations" (`tools/approval_smart.py:24-25`). Hermes' list catches `rm -r` and `rm /` but not a plain `rm <file>` (`tools/approval_detection.py:210-217`). The generated config set no `approvals` key, so deleting the user's files was decided by a model or not asked at all. Two reviews also found that the tool rule needed a literal `.php` before `--with-data`, so the form the USAGE files teach (`php $Q remove <id> --with-data`) was never asked.
- The generated `config.yaml` sets `approvals.mode: manual`. Hermes deep-merges config.yaml over its defaults (`hermes_cli/config.py:1508-1520`), so `timeout` and `cron_mode: deny` stay.
- The guard asks (`approve`) for: `--with-data`, `--delete-files` or `--expect` anywhere in a command (every deleting tool verb takes `--expect`, and `php $R move ... --expect` names no rclone); `prune`, `forget` or `purge` after `php`; plain `rm`, `unlink`, `shred`, `truncate`; `find -delete` and `find -exec rm`; file deletes in `execute_code` (`os.remove`, `shutil.rmtree`, `.unlink(`); Deluge `core.remove_torrent(s)`; rTorrent `execute*` with `rm`; and running the media installer with anything other than stopping or starting one app (PMSS `install-media-stack.sh` asks on stdin before deleting an app's settings, and a pipe can answer it).
- The guard blocks any command on `local/secrets/`: what the agent reads goes to the model, and the agent never needs a stored password (the tools read them themselves).
- `rm -rf` stays blocked. More questions are the accepted cost; the tools offer batch calls so one approval covers a whole list.
- Storage and backup forms that passed unasked (79-command test, storage review 2026-10-14) are asked too: rclone remote-control deletes (`rclone rc operations/purge|delete|deletefile|rmdir(s)|cleanup` and the same paths over HTTP to this machine), bare `borgmatic` and its prune/compact runs, `restic key remove`, `restic repair snapshots --forget`, direct restores (`restic restore`, `borg extract`, `kopia snapshot restore`, `duplicacy -overwrite`), `borg check --repair`, rsync/lftp source or destination deletes, `rclone serve` on a non-loopback address, and the tool flag `--replace`. `rclone config show|dump` is blocked like `local/secrets/`.
- Media servers: a DELETE to `/Items` or `/Items/{id}` (Jellyfin 12.2 and Emby 4.1 specs: "deletes an item from the library and filesystem"), to Plex `/library/metadata/{id}`, or to `/Auth/Keys/{key}` is asked from any host, also through the public proxy path. The media units send no DELETE at all.
- *arr queue: removing a queue item with `DELETE /api/vN/queue/{id}` deletes the downloaded files in the torrent client unless `removeFromClient=false` is sent, because that parameter defaults to true (Sonarr `QueueController` `RemoveAction` and `RemoveMany`, `removeFromClient = true`; Radarr and Lidarr alike). The guard already asks for any DELETE to an *arr `/api/vN/queue` path; the *arr units offer no queue removal in v1 and send every boolean parameter explicitly.
