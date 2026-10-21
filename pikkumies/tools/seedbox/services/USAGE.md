# services

The user's other machines and what Pikkumies may do on them ([ADR-0084](../../../../docs/adr/0084-services-list-and-moving-torrents-between-boxes.md)).

| verb | arguments | changes | what it does |
|---|---|---|---|
| add | `<nick> --host H --user U [--port N] [--provider pmss\|feral\|whatbox\|bytesized\|ultra\|seedhost\|generic] [--tags a,b] [--transport pikkumies\|tunnel\|shell] [--home /home/u] [--notes "..."] [--clients JSON]` | yes | Adds one service to `local/state/services.jsonl`. The provider is found from the host name when not given (PMSS boxes: `--provider pmss`); its client endpoints are filled in only where a source names them (README.md), else left out. Reads the list back (`verified`). |
| list | `[--tag T]` | no | Every service: `nick`, `tags`, `provider`, `host`, `port`, `user`, `transport`, `clients`, `allow_delete`, `notes`, `added_at`, `set_up` (key and pinned host key present). |
| show | `<nick>` | no | One service, its `public_key`, and `memories`: every lesson (any `memory/lessons/<topic>/`), fact sheet (`memory/reference/`) and research note (`memory/research/`) whose frontmatter `service` array names this nick (`save --service`, [ADR-0104](../../../../docs/adr/0104-service-memories-get-structured-service-and-category-links.md)), each with its `categories` (or `null`); a memory saved before ADR-0104, with no `service` frontmatter, is still found through the old body-text convention (`service: <nick>`), but only in `memory/lessons/services/` and `memory/reference/` as before. |
| edit | `<nick> [add's options] [--add-tag t] [--remove-tag t] [--allow-delete\|--no-allow-delete]` | yes | Changes fields; `--tags` replaces the tags. Host, port and user cannot change once the box is set up (remove and add again). |
| remove | `<nick>` | yes | Takes the service off the list and deletes its key and pinned host key here; says which line to remove from `~/.ssh/authorized_keys` on that box. |
| setup | `<nick> [--fingerprint SHA256:...] [--allow-delete] [--allow-shell]` | yes | The one-time login only from `PIKKUMIES_REMOTE_PASSWORD='...'` before the command. Pins the host key without logging in, logs in once to append one key line (umask 077), then checks the new key: transport pikkumies must get serve's own `ping` answer, tunnel must run no command, shell must (needs `--allow-shell`). Refuses and deletes the new key here when it cannot. |
| pubkey | `<nick> [--allow-delete]` | yes | This box's public key for that service (made on first use) and the exact `accept` command to run on that box. |
| accept | `'<public key line>' --label L [--allow-delete] [--install]` | yes | On the box being reached: the `restrict,command="php ~/pikkumies/tools/seedbox/services/serve.php L"` line for a pasted public key (one ssh-ed25519, ECDSA or RSA key; a private key or several lines are refused). `--install` appends it to `~/.ssh/authorized_keys` (umask 077) and refuses when the same key already has another line. |
| test | `<nick> [--fingerprint SHA256:...]` | yes | Pins the host key when none is pinned (no login), then proves what the key does (`works`, `why`, `allow_delete`). |
| remote | `<nick>\|--all\|--tag T <category/tool> <verb> [args] [-- tool options] [--intent "<user's words>"]` | yes | Runs one whitelisted verb (README.md; not `export`, whose .torrent carries the tracker passkey) on each service through its serve, up to 4 at once, and prints that tool's records with `service`; a failed service gives one `ok: false` record and never stops the others (status `failed`). `add`, `pause`, `resume`, `recheck`, `repath`, `remove` go to one service and need `--intent`, recorded in `local/state/intents.jsonl`. `add` sends a local .torrent on stdin. |

```sh
php ~/pikkumies/tools/seedbox/services/services.php add hdd1 --host box.example.com --user xyzjohn --provider pmss --tags seedbox,backup
PIKKUMIES_REMOTE_PASSWORD='the-login' php ~/pikkumies/tools/seedbox/services/services.php setup hdd1
php ~/pikkumies/tools/seedbox/services/services.php remote --tag seedbox system/system-status account
php ~/pikkumies/tools/seedbox/services/services.php remote hdd1 torrent-clients/rtorrent list -- --limit 5 --sort size
php ~/pikkumies/tools/seedbox/services/services.php remote hdd1 torrent-clients/rtorrent pause 0123456789ABCDEF0123456789ABCDEF01234567 --intent "pause the big one on hdd1"
```

- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- `remote ... remove <id> -- --with-data --expect <size>` deletes torrent data on the other box: the safety check reviews it (doctrine/safety.md), and that box refuses it unless its key line has `--allow-delete`.
