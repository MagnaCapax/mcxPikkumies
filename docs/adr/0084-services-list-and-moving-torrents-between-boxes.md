# ADR-0084: A services list, and moving torrents between boxes

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Proposed. Builds on [ADR-0049](0049-backup-remote-over-ssh.md) (the restricted-key setup is reused), [ADR-0068](0068-ssh-connections-need-a-stated-intent-and-the-persons-allow-and-deny-lists.md) (the person's SSH lists apply inside these tools) and [ADR-0019](0019-hosts-and-credentials.md) (hosts and credentials).

## Context
The request of 2026-10-20: Pikkumies should know the user's other machines (seedboxes, a backup box, a NAS, a workstation), show their statistics like local ones, find a torrent on any of them, and move torrents between boxes, including boxes at other providers.

What was found:
- `backup remote` already reaches one other machine with a key whose `authorized_keys` line is `restrict,command="..."`, set up with one password login through `SSH_ASKPASS` and proven by an answer only the forced command gives (ADR-0049).
- The ssh-connect hook judges ssh commands the agent types; a tool that connects by itself is out of its sight (ADR-0068). The lists in `lib/SshLists.php` can be applied inside a tool, as backup remote does.
- On a box the agent can reach with a plain key, text it reads there (a torrent name, a tracker message, a file) can steer it. With one unscoped key per box, one prompt-injected box can act on every other box through the agent: delete data, read files, plant keys.
- Third-party seedboxes run no Pikkumies. They offer ssh and the client's own API; rTorrent's XML-RPC has no call that deletes a torrent's files (`d.erase` only drops the torrent), so a data delete there is the provider's panel or a shell.
- rrsync, shipped with rsync, confines a key to one directory and `-ro` to reading; it refuses rsync's `-s` (protect-args) because the paths would then arrive over the protocol, unchecked (rrsync(1)). rsync sanitizes every path of a `--files-from` list sent over the protocol (flist.c, `send_file_list`).
- PHP 7.3 `proc_open` takes only a string: every word goes through `escapeshellarg` with `exec` (lib/ToolProc.php).

## Options Considered
- **One full-shell key per box.** Simple, and what most guides do. Rejected: it gives every box, and any text on it, the power of the user's shell on every other box.
- **The agent types ssh commands itself.** Rejected: an LLM composing remote shell text is exactly what injected text steers; the hook sees the command but cannot judge what it does there.
- **Push data from the source box.** Rejected: a key that may write into another box is a key that may overwrite it. Reading is the smaller right.
- **Store other boxes' passwords and API keys for later.** Deferred: an encrypted secret store is its own decision.
- **A per-box forced command with a whitelist, data moves as pulls.** Chosen.

## Decision
- **The list.** `local/state/services.jsonl`, one service per line: `nick`, `tags` (free, several per box; suggested seedbox, backup, webhosting, dedicated, workstation, nas, laptop, media, other), `provider`, `host`, `port`, `user`, `home`, `transport` (`pikkumies`, `tunnel` or `shell`), `clients` (endpoints), `allow_delete`, `notes`, `added_at`. Keys in `local/secrets/services/<nick>/` (0700/0600). `lib/Services.php` is the one reader and writer, with `sshArgv`, `run`, `jsonl` and `fanout` for other tools. Client endpoints are filled in only from a cited source; others stay unknown.
- **Scoping by forced command.** transport `pikkumies`: the key line on the other box is `restrict,command="php ~/pikkumies/tools/seedbox/services/serve.php <label> [--allow-delete]"`. serve splits `SSH_ORIGINAL_COMMAND` without a shell (a quote, shell character, backslash, control character or non-ASCII byte refuses it) and allows only `ping`, a whitelist of tool verbs (torrent clients' list, show, stats, traffic, add, pause, resume, recheck, repath, remove, export; find; system-status show, account, health; telemetry series, list) with a fixed option list, and a read-only rsync pull. Every folder a peer names (rsync paths, `--dir`, `--to`) must lie inside a data root: `~/data`, each installed client's configured save folder read from its own config file (rTorrent `directory.default.set`, qBittorrent `Session\DefaultSavePath` / `Downloads\SavePath`, Deluge `download_location` / `move_completed_path`, Transmission `download-dir`), and `services.serveRoots` set by hand in `local/config.php`; never the home itself, a path part starting with a dot, or `~/pikkumies`. A peer thus never reads this box's keys to other boxes (`local/secrets`), `~/.ssh` or app settings, and never writes into them; `add` also refuses a torrent with a dot-named or slashed file. The tool runs by its fixed path. `add` takes the .torrent only on stdin: no URL, magnet or path comes from another box. find's `--all` and `--service` are refused there, so no box reaches further boxes. Every request is logged on that box: never the .torrent, never the arguments of an allowed call (a refusal's reason may quote the refused word).
- **Data moves are pulls.** The box that receives the data runs rsync; serve allows only `rsync --server --sender` from one data root (through `rrsync -ro -munge <root>` when installed, else its own check run in that root: no deletes or removes, no `-s`, no link-following options, `--files-from` only `-`). A copy into the box is refused.
- **Deletes are opt-in per key.** `remove --with-data` passes serve only when the key line carries `--allow-delete`, which the other box's owner adds at setup or accept. The delete guard asks about `--with-data` on this side as before ([ADR-0020](0020-deletes-need-the-users-approval.md)).
- **Intent gate.** A changing verb through `services remote` goes to one service and carries `--intent "<the user's own words>"`, recorded in `local/state/intents.jsonl`; freeing the source of a move needs the same and re-verifies the copy live before anything is removed (move tool).
- **Setup.** As ADR-0049: login from `PIKKUMIES_REMOTE_PASSWORD` through askpass, host key pinned with no login and shown, one login appends the line with umask 077, the new key must answer `ping` from serve or it is deleted here. Without a password: `pubkey` here, `accept --install` there (one public key line, never a private key; a second line for the same key is refused), `test` here. Every connection uses the service's own `known_hosts` with `StrictHostKeyChecking=yes`, `-F /dev/null`, `--` before a checked host, and refuses a host on `ssh.deny` (or not on `ssh.allow` with `ssh.blockUnknown`).
- **Third parties.** transport `tunnel`: `restrict,port-forwarding,command="/bin/false"`, port forwards to the client's API only. Moves off such a box pull through that API's own export where it has one; rTorrent there cannot delete data by API. transport `shell` (a full shell key) only with `setup --allow-shell` on the user's request, for programs serve cannot run (a GPU box's ffmpeg).
- **Memory.** Knowledge about one box is saved with the memory tool under `--topic services` or as a reference, with a `service: <nick>` line; `services show <nick>` lists those files ([ADR-0081](0081-tool-linked-memories-and-an-agent-facing-soft-confirm.md) memories stay as they are).

## Consequences
- One injected box can do on another only what that box's owner allowed its key: read statistics and torrent lists, add a .torrent, pause or resume, pull files, and delete only with `--allow-delete`.
- Statistics of many boxes come back as the same records as local ones, with `service`, several boxes at once; a down box gives one error record and never stops the others.
- Copies need the space on the receiving side and run where the data lands; a third-party destination without Pikkumies cannot receive a move in v1.
- serve confines the paths it is given to the data roots, not the account: inside a root, a symlink pointing out is followed by the kernel unless it is itself named, as with rrsync, and `--files-from` entries are only cleaned of `..` by rsync.
- `export` (the whole .torrent, tracker passkey included) is allowed by serve for moves, which never show it to the model; `services remote` does not run it, because its output would reach the model.
- `setup` and `accept --install` write the other box's `authorized_keys`; no hook asks about that file yet (ADR-0068 names it a separate subject), so the skill runs them only on the user's request ([ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md): the request is the yes).
- Tests: `tests/ServicesTest.php`, `tests/ServicesServeTest.php` (with a fuzz of hostile torrent-name-like words).

## References
- ADR-0019, ADR-0020, ADR-0049, ADR-0068, ADR-0073, ADR-0081; [docs/ssh.md](../ssh.md); the unit's [README](../../pikkumies/tools/seedbox/services/README.md).
- rrsync(1) (rsync 3.4.1): `-ro`, `-munge`, `short_disabled = 's'`; rsync flist.c `send_file_list` (`sanitize_path` on every `--files-from` line), https://github.com/RsyncProject/rsync/blob/master/flist.c
- OpenSSH sshd(8) AUTHORIZED_KEYS FILE FORMAT: `command=`, `restrict`, `port-forwarding`.
