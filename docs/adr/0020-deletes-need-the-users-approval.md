# ADR-0020: Deleting data needs the user's approval in the chat

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. Changes the guard hook from block-only to block or approve, and supersedes the [ADR-0005](0005-hermes-wiring-verified-against-source.md) guard bullet "Escalation is not available to shell hooks in this version" (true of Hermes 0.19.0, not of the pinned v2026.9.24).

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
- ADR-0005 (Hermes wiring), [ADR-0015](0015-tool-units-one-skill-per-tool.md), [ADR-0019](0019-hosts-and-credentials.md)

## Amendment 2026-10-14: the user's own files, stored passwords, and Hermes' smart mode
Facts checked in the pinned Hermes v2026.9.24: the default `approvals.mode` is `smart` (`hermes_cli/config_defaults.py:1656`), where a second model decides whether a command on Hermes' dangerous list runs and is told to approve "safe file operations" (`tools/approval_smart.py:24-25`). Hermes' list catches `rm -r` and `rm /` but not a plain `rm <file>` (`tools/approval_detection.py:210-217`). The generated config set no `approvals` key, so deleting the user's files was decided by a model or not asked at all. Two reviews also found that the tool rule needed a literal `.php` before `--with-data`, so the form the USAGE files teach (`php $Q remove <id> --with-data`) was never asked.
- The generated `config.yaml` sets `approvals.mode: manual`. Hermes deep-merges config.yaml over its defaults (`hermes_cli/config.py:1508-1520`), so `timeout` and `cron_mode: deny` stay.
- The guard asks (`approve`) for: `--with-data`, `--delete-files` or `--expect` anywhere in a command (every deleting tool verb takes `--expect`, and `php $R move ... --expect` names no rclone); `prune`, `forget` or `purge` after `php`; plain `rm`, `unlink`, `shred`, `truncate`; `find -delete` and `find -exec rm`; file deletes in `execute_code` (`os.remove`, `shutil.rmtree`, `.unlink(`); Deluge `core.remove_torrent(s)`; rTorrent `execute*` with `rm`; and running the media installer with anything other than stopping or starting one app (PMSS `install-media-stack.sh` asks on stdin before deleting an app's settings, and a pipe can answer it).
- The guard blocks any command on `local/secrets/`: what the agent reads goes to the model, and the agent never needs a stored password (the tools read them themselves).
- `rm -rf` stays blocked. More questions are the accepted cost; the tools offer batch calls so one approval covers a whole list.
- Storage and backup forms that passed unasked (79-command test, storage review 2026-10-14) are asked too: rclone remote-control deletes (`rclone rc operations/purge|delete|deletefile|rmdir(s)|cleanup` and the same paths over HTTP to this machine), bare `borgmatic` and its prune/compact runs, `restic key remove`, `restic repair snapshots --forget`, direct restores (`restic restore`, `borg extract`, `kopia snapshot restore`, `duplicacy -overwrite`), `borg check --repair`, rsync/lftp source or destination deletes, `rclone serve` on a non-loopback address, and the tool flag `--replace`. `rclone config show|dump` is blocked like `local/secrets/`.
- Media servers: a DELETE to `/Items` or `/Items/{id}` (Jellyfin 12.2 and Emby 4.1 specs: "deletes an item from the library and filesystem"), to Plex `/library/metadata/{id}`, or to `/Auth/Keys/{key}` is asked from any host, also through the public proxy path. The media units send no DELETE at all.
- *arr queue: removing a queue item with `DELETE /api/vN/queue/{id}` deletes the downloaded files in the torrent client unless `removeFromClient=false` is sent, because that parameter defaults to true (Sonarr `QueueController` `RemoveAction` and `RemoveMany`, `removeFromClient = true`; Radarr and Lidarr alike). The guard already asks for any DELETE to an *arr `/api/vN/queue` path; the *arr units offer no queue removal in v1 and send every boolean parameter explicitly.

## Amendment 2026-10-17: Docker deletes, exposure and stopping every container ([ADR-0034](0034-containers-category.md))
The guard already asks for one command that deletes nothing: `rclone serve` on a non-loopback address (amendment above), because it exposes the user's files. The containers category (ADR-0034) widens the scope of this ADR the same way, from "deletes" to "deletes, exposure, and stopping every container", and the guard's message says which of the three a question is about.
- Deletes, asked: `docker|podman|nerdctl rmi`; `(image|container|volume|network|system|builder) prune`; `system reset`; `compose ... down` with `-v`, `--volumes` or `--rmi`; `(container|image|volume|network) rm|remove`; Engine API calls over `curl --unix-socket` with DELETE or `/prune`; the docker unit's `remove` and `prune` (they take `--expect`). Not asked: `run --rm`, a plain `compose down`, `images --prune-plan`.
- Exposure, asked: publishing a port without a loopback address (`-p 8080:80` binds every interface), `-P`/`--publish-all`, `--net`/`--network host`, `--privileged`, `--cap-add`, `--device`, mounts naming a socket, `.ssh`, `pikkumies/local` or the home itself, and the docker unit's `--public` and `--accept-shared-exposure`. Not asked: publishing on `127.0.0.1:` or `[::1]:`.
- Stopping every container, asked: `pkill`, `killall` or `kill` naming `rootlesskit`, `dockerd` or `containerd`.
- Blocked, like `rclone config show|dump`: reads of `~/.docker/config.json` and `docker login` with a password on the command line. An approved read would still put the registry credentials in front of the model, and ADR-0019 keeps passwords off the command line.

## Amendment 2026-10-18: an agent starting another agent with approvals, hooks or rules off
The agent can skip the questions above itself, by starting a second agent that nobody is asked about. Facts checked in Hermes v0.22.2 (`d923a5eb`), the stable tag installs get (HermesInstall::STABLE_TAG_RE); the facts above were read in an older date tag:
- `--yolo` sets `HERMES_YOLO_MODE=1` before startup, and approvals read it once at import (`tools/approval.py:46`). The approval gate lets every question through under yolo or `approvals.mode: off` (`tools/approval.py:967`); a guard `block` still stops the call (`hermes_cli/plugins.py:2051` `_resolve_block_from_details`).
- The top-level `-z`/`--oneshot PROMPT` (`hermes_cli/_parser.py:148`) sets `HERMES_YOLO_MODE=1` and `HERMES_ACCEPT_HOOKS=1` by itself (`hermes_cli/oneshot.py:276-277`). The `chat` subcommand has no `-z`, and its own `--oneshot` only answers and exits (`_parser.py:243`, `dest="oneshot_exit"`). Top-level flags that take a value (`-m`, `-t`, `-s`, `-r`, `--usage-file`, `--in`, `--provider`, `--reasoning`, `-c`; `_parser.py` `top_level_value_flag_sets`) can hold the word `chat`, so `hermes -m chat -z x` is a top-level one-shot.
- `--safe-mode` sets `HERMES_SAFE_MODE`, and then no shell hook is registered, this guard included (`agent/shell_hooks.py:147-148`). `--ignore-rules` leaves SOUL.md, AGENTS.md and memory out of the prompt. `--accept-hooks` registers hooks nobody consented to. `--ignore-user-config` does not remove the hooks or approvals (only `HERMES_IGNORE_USER_CONFIG=1` exactly, read by the CLI display config and the non-interactive config check, `hermes_cli/config.py:442`, `cli_config_load.py:265`); it is listed because it drops the user's settings.
- `--profile <name>`, `--profile=<name>` and `-p <name>`, also after a subcommand, move `HERMES_HOME` to `<root>/profiles/<name>` before anything else runs (`hermes_cli/main.py:477` `_scan_profile_flag`, `:595` `_apply_profile_override`), so the second agent loads no Pikkumies config or hooks. `hermes profile use <name>` writes `active_profile` in the Pikkumies Hermes home, and every later start, the user's too, then reads that profile (`main.py:618-622`, `hermes_constants.py:219-236`).
- `hermes config set approvals.mode off` writes the mode (`hermes_cli/config.py:3577`). In a session, `/approvals off|smart` writes `approvals.mode` for the whole profile (`hermes_cli/approval_mode.py:51`), and `/yolo` switches approvals off for that session, kept with the session and switched on again when it is resumed (`hermes_cli/cli_session_mixin.py:169-174`; `commands.py:217-221`).
- Hermes accepts any unambiguous prefix of a long flag (`--yo`, `--saf`; no `allow_abbrev` in `hermes_cli/`).
- Terminal commands get Hermes' environment without credentials (`tools/environments/local.py:310`). Shell hooks get it unchanged, credentials included, unless several profiles are served at once (`agent/shell_hooks.py:331-341`, `scrub_secrets=is_multiplex_active()`). A variable the launcher sets reaches both.
- An agent can start a second agent on a pseudo-terminal (`terminal` with `pty=true`, `tools/terminal_tool.py:1368`) and type into its approval panel (`process_manage submit`, `tools/process_registry.py:2719`): in a nested agent the one answering a question is the parent agent, not the user.

Decision:
- The guard blocks, in a `terminal`, `execute_code` or background-shell (`process_manage`) input:
  - a command that starts `pikkumies run`, `hermes` or `hermes_cli` (also `python -m hermes_cli`, the venv path, quoted and list forms, `env -i`) with `--yolo`, `--safe-mode`, `--ignore-rules`, `--ignore-user-config`, `--accept-hooks`, `--profile` or `-p` in any accepted spelling; with `-z` anywhere, attached values included (`-zgo`); with `--oneshot` before the subcommand, read the way Hermes reads it (the value of a top-level value flag is skipped); or `profile use`. A plain `pikkumies <words>` is a prompt (`Launch::route`), so only `pikkumies run` counts;
  - a command that sets `HERMES_YOLO_MODE`, `HERMES_SAFE_MODE`, `HERMES_IGNORE_RULES`, `HERMES_IGNORE_USER_CONFIG` or `HERMES_ACCEPT_HOOKS`, or sets or removes `HERMES_HOME` or `PIKKUMIES_DEPTH` (a shell assignment where the shell reads one, `export`, `env`, `unset`, `env -u`, and the `os.environ` forms). Reading them (`grep -r HERMES_HOME= .`, `env | grep ^PIKKUMIES_DEPTH=`) is not blocked;
  - `config set approvals.…` and the dotted YAML spelling `approvals.mode`; `/yolo` and `/approvals off|smart` typed into a session. A `sed` or `printf` edit of `config.yaml` is not caught: Hermes leaves `config.yaml` writable on purpose (`agent/file_safety.py:191-194`), and the guard checks file tools only against the password and key rules.
  The message tells the agent to show the user the command; the user runs it if they want it.
- The launcher exports `PIKKUMIES_DEPTH` = its own depth + 1 to the Hermes it starts (`run` and `cron-tick`), so a command an agent runs sees 1 or more and the user's shell sees none (`lib/NestedAgent.php`). This part does not depend on how the launcher was spelled:
  - in an agent at depth 2 or more, every guard-destructive question (delete, exposure, schedule, mail) becomes a block, with yolo or without: the one answering would be the parent agent, or nobody under yolo or `approvals.mode: off`;
  - `pikkumies run` started at depth 1 or more refuses the same flags and Hermes commands, and says how the user, typing in their own terminal, can go ahead (`unset PIKKUMIES_DEPTH`).
- **This amends [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md) (Decision, "`argv[1]` is `run` → the Hermes passthrough, all words kept") and [ADR-0052](0052-launcher-model-flag-and-configure.md) (Decision, "`pikkumies run chat …` stays the full passthrough"):** `run` stays the full passthrough for the user, and inside an agent (depth 1 or more) it refuses the words above.
- The user's own runs are untouched: `pikkumies run --yolo` typed in their shell starts as before (Consequences above), and in that session (depth 1) yolo still lets the guard's questions through, as before.

Consequences: this is a safety prompt, not security, aimed at the spelling an agent reaches for (AGENTS.md: "Hooks are safety prompts, not security"). The second agent runs as the same account with the same files, so nothing crosses a trust boundary.
- Out of the guard's sight: a launcher named through a variable (`H=$(which hermes); "$H" -z go`) or a script, `hermes` started under another name at depth 1, code that builds the command at run time, a `;` inside a quoted argument before `-z`, `-p` or `--oneshot`, and a `sed`/`printf` edit of `config.yaml`. `PIKKUMIES_DEPTH` is a variable the agent can change. The questions of the other guard units (fileops-check, feedback-send) are not turned into blocks at depth 2.
- False matches block a command that names a launcher together with one of these flags, such as prompt text containing ` -z ` or ` --yolo `, or a path ending in `/yolo` after a space.
- The depth leaks into the user's own shells when an agent started them: a tmux or screen server, or Hermes' `!` shell, first started from inside an agent keeps `PIKKUMIES_DEPTH=1`. The user's later `pikkumies run --yolo` there is refused (the message says to `unset PIKKUMIES_DEPTH`), and a session started there runs at depth 2, where every question is a block.

## Amendment 2026-10-18: settings changes ([ADR-0059](0059-settings-tiers-free-asked-and-the-persons-own.md))
A second hook unit, `config-change`, uses the same approval prompt for settings changes that loosen something, cost credits or delete history, with `rule_key` `pikkumies-config:` + a hash of the key, the old and the new value (one change per answer), and for edits of `local/config.php` past the configure tool (a hash of the command). In an agent at depth 2 or more its questions are blocks, as here. Hermes' approval mode itself stays pinned to manual and is not a setting the agent can change.

## Amendment 2026-10-19: V4A patches in the file-tool rules
The stored-password and AI-key rules for Hermes' file tools also check every file a V4A `patch` names in its `Update`/`Add`/`Delete File:` and `Move File: a -> b` headers (both sides), the same way as `path`, read by `lib/PatchPaths.php` as Hermes reads them; a regex engine error while reading them blocks (`tests/HookGuardPatchTest.php`, `tests/PatchPathsTest.php`).
