# services

The user's other machines (seedboxes, backup boxes, a NAS, a workstation) in one list, each reached with its own key that the other box limits to what Pikkumies needs ([ADR-0084](../../../../docs/adr/0084-services-list-and-moving-torrents-between-boxes.md)). Statistics and torrent lists from those boxes come back in the same records as local ones, with a `service` field.

## The list

`~/pikkumies/local/state/services.jsonl`, one service per line: `nick`, `tags` (free words, several per box), `provider`, `host`, `port`, `user`, `home`, `transport` (`pikkumies`, `tunnel` or `shell`), `clients` (endpoints), `allow_delete`, `notes`, `added_at`, and after setup `host_key`, `host_key_alg`, `setup_at`. Keys live in `~/pikkumies/local/secrets/services/<nick>/` (`key`, `key.pub`, `known_hosts`; 0700 and 0600). `lib/Services.php` is the one reader and writer; other tools (find, move, balance) use it.

## How a box is reached

- **transport pikkumies** (Pikkumies runs there too): the key line on that box is `restrict,command="php ~/pikkumies/tools/seedbox/services/serve.php <label> [--allow-delete]" ssh-ed25519 ... pikkumies-<label>`. Whatever this box asks, sshd runs `serve.php`, which allows only the verbs below.
- **transport tunnel** (a third-party box without Pikkumies): `restrict,port-forwarding,command="/bin/false"`. The key opens port forwards (for example to that box's rTorrent socket) and runs nothing.
- **transport shell**: an unrestricted key, only with `setup --allow-shell` when the user asked for it (a GPU box that must run ffmpeg). The forced command cannot run arbitrary programs, so this is its own decision.

`setup` follows [backup remote](../../backup/remote/README.md): the login only from `PIKKUMIES_REMOTE_PASSWORD` (through that unit's `askpass.php`), the host key pinned by a connection with no login and its fingerprint shown (`--fingerprint` checks it), one login appends the key line with umask 077 (and, for transport pikkumies, stops when Pikkumies is not installed there), then the new key must prove its limit: serve's own `ping` answer, which a shell would not give. Otherwise the key is deleted here. Without a password, `pubkey` prints the line to paste into `accept --install` on the other box, and `test` pins the host key and proves the key.

Every ssh call is an argv with `-F /dev/null`, `IdentitiesOnly=yes`, `BatchMode=yes`, `StrictHostKeyChecking=yes` with the service's own `known_hosts`, and `--` before a host checked against a strict pattern. The SSH deny list refuses a box before any connection, and with `ssh.blockUnknown` so does a box not on the allow list ([docs/ssh.md](../../../../docs/ssh.md)).

## What serve allows

`serve.php` splits `SSH_ORIGINAL_COMMAND` on spaces with no shell; a quote, `; | & $ \` < >`, a backslash, a control character or a non-ASCII byte refuses the request. Then exactly:

| request | allowed |
|---|---|
| `ping` | `{"serve":"pikkumies","version":1,"peer":...,"allow_delete":...}` |
| `tool torrent-clients/<rtorrent\|qbittorrent\|deluge\|transmission> <verb>` | `list show stats traffic add pause resume recheck repath remove export` |
| `tool torrent-clients/find find` | find on that box only (`--all` and `--service` are refused, so a box never reaches further boxes) |
| `tool system/system-status <verb>` | `show account health` |
| `tool system/telemetry <verb>` | `series list` |
| `rsync --server --sender ...` | a read-only pull from ONE data root: `/usr/bin/rrsync -ro -munge <root>` when installed (the request rewritten with paths relative to the root), else serve's own check run in that root (no deletes or removes, no `-s`, no link-following `-L`/`-k`/`-K`, `--files-from` only `-`) |

**Data roots.** Every folder a peer names (rsync paths, `--dir`, `--to`) must be an existing folder inside a data root of this box: `~/data`; each installed client's configured save folder, read from its own config file: rTorrent's `directory.default.set` (`~/.rtorrent.rc` or `~/.config/rtorrent/rtorrent.rc`), qBittorrent's `[BitTorrent] Session\DefaultSavePath` and `[Preferences] Downloads\SavePath` (`~/.config/qBittorrent/qBittorrent.conf`), Deluge's `download_location` and `move_completed_path` (`~/.config/deluge/core.conf`), Transmission's `download-dir` (`~/.config/transmission-daemon/settings.json`); and the entries of `services.serveRoots` in `~/pikkumies/local/config.php` (a list, set by hand, for any other folder). Never the home itself, any path part starting with a dot (`~/.ssh`, `~/.config`, `data/.x`), or `~/pikkumies` (keys in `local/secrets`, the code); a root that is one of those is dropped, and a link out of a root is judged by where it points. So a peer can never pull this box's keys to other boxes, nor drop a file into `~/.ssh`.

Options: `--dir` and `--to` (an existing folder inside a data root), `--expect`, `--state`, `--search`, `--sort`, `--limit`, `--offset`, `--fields`, `--days`, `--bucket`, `--paused`, `--files`, `--trackers`, `--reverse`, and `--with-data` only for `remove` and only when the key line has `--allow-delete`. Other arguments are ids and words (no path, no leading dash). `add` takes only `-` with the .torrent on stdin (at most 16 MiB), stored in `local/state/tmp/` for the call and removed after: no URL, magnet or path comes from another box, and a torrent whose name or any file path part is empty, starts with a dot or holds a slash is refused. Copies INTO the box (`rsync --server` without `--sender`) are refused: data moves are pulls, run by the box that receives the data. The tool runs as `PHP_BINARY <fixed unit path> <verb> <args>`, every word escaped.

Every request, allowed or refused, is one line in `~/pikkumies/local/state/services-serve.jsonl`: time, peer label, request kind, tool and verb, argument count, decision, exit code, and for a refusal its reason (which may quote the refused word). Never the .torrent, and never the arguments or paths of an allowed call.

## Providers

Client endpoints are filled in only from a source: PMSS `~/.rtorrent.socket` and `~/data` (PMSS `etc/seedbox/config/template.rtorrent.rc` lines 22 and 28) and `~/.lighttpd/transmission.sock` (`scripts/lib/user/transmissionSettings.php` line 51); Feral's rTorrent socket `~/private/rtorrent/.socket` ([Feral path reference](https://www.feralhosting.com/wiki/path-reference); that page names no data directory). Whatbox, Ultra, Bytesized and Seedhost are recognised from the host name (`lib/Host.php`) with no endpoints: give them with `--clients`.

## Limits

- On a third-party box there is no serve: a tunnel reaches the client's own API, and rTorrent's API has no call that deletes data, so removing data there is the provider's panel or the user's shell.
- serve confines the paths it is given to the data roots, not the account: inside a root, a symlink that points outside is followed by the kernel when it is not itself named (also with rrsync), and the entries of a `--files-from` list (sent over the rsync protocol, so never seen by serve) are only cleaned of `..` by rsync. Keep links to private files out of the data roots.
- `setup` and `accept --install` write `~/.ssh/authorized_keys` on the box being reached; run them only when the user asks.
- Passwords and API keys of other boxes are not stored here (a separate decision).
