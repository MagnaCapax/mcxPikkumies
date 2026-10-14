# deluge

Controls the user's own Deluge 2.x torrent client: list, inspect, add, remove, pause and resume torrents, read stats and traffic, and restart it on PMSS. It talks to deluge-web's JSON-RPC on 127.0.0.1 with the user's own localclient secret, as the user, without root. It never runs Python programs such as deluge-console.

## Where Deluge is found

1. **Host.** PMSS when `/etc/seedbox/config` exists, else the provider from the hostname, else generic (`lib/Host.php`).
2. **deluge-web port.**
   - PMSS: `~/.delugeWebPort`, then `port` in `~/.config/deluge/web.conf`, then `~/.delugePort`. PMSS writes the web port to the first two (PMSS `scripts/lib/user/deluge.php:99-118, 184-187`); `~/.delugePort` is the daemon port (`deluge.php:157, 187`) and is read last only because PMSS itself falls back to it (`scripts/lib/user/torrentPort.php:75-78`).
   - Other hosts: `port` in `~/.config/deluge/web.conf`. That file is two JSON objects, a `{file, format}` header and the config (PMSS `etc/seedbox/config/template.deluge.web.conf`, `scripts/lib/user/delugeManagedConfig.php:75`).
3. **Owner check.** The port must be this user's listener in `/proc/net/tcp(6)`; another account's port is never contacted (ADR-0019, `lib/Host.php`, `lib/Http.php`).
4. **Secret.** The `localclient:<secret>:<level>` line of `~/.config/deluge/auth`, matched as PMSS does (`scripts/lib/user/delugePasswords.php:44-54`). PMSS sets the deluge-web password hash from the same secret (`delugePasswords.php:118-145, 150-200`). The secret goes only into the login request; it is never printed, logged or put on a command line.
5. **Daemon.** deluge-web starts disconnected on PMSS (`default_daemon` is empty in the template). The tool calls `web.connected`, then `web.get_hosts` and `web.connect` with the `localclient` entry on 127.0.0.1 or localhost (PMSS `delugeManagedConfig.php:187-193`), preferring the one on the daemon port (`~/.delugePort` on PMSS, else `daemon_port` in `~/.config/deluge/core.conf`). Before `web.connect` (`json_api.py:454-468`), which makes deluge-web log in to that port with the stored secret, the port must be this user's listener. When deluge-web is already connected (the web UI, or an earlier call), deluge-web itself, not the daemon, says which host it is connected to: `web.get_host_status` (`json_api.py:784-797`) answers `Connected` for that host (`hostlist.py:218-229`) and probes the others without logging in (`:230-236`); only hosts on 127.0.0.1 or localhost are asked, so deluge-web never probes a remote host. That host's port must be this user's listener. When no local host answers `Connected` (deluge-web is connected to a remote daemon, or to a `localhost` entry: `hostlist.py` compares the resolved address with the name used), the tool calls `web.disconnect` (`json_api.py:479-490`) and connects to the localclient entry as above.

Errors name the cause: Deluge not installed (no `deluged` in `/usr/bin`, `/usr/local/bin` or `PATH`), not enabled (PMSS without `~/.delugeEnable`: the user starts Deluge in the PMSS panel), not running (nothing listens on the port), busy (no answer within 10 s: state unknown, not down), refused, the port belongs to another account, the secret was refused, or deluge-web cannot reach the daemon.

## Deluge calls (Deluge 2.0.3 source)

| verb | call | source |
|---|---|---|
| all | `POST /json`, `Content-Type: application/json` exactly; `{"method","params","id"}` → `{"result","error","id"}` | `deluge/ui/web/json_api.py:133-253`; `/json` is served at `/` as well as at the base path, `server.py:687-689` |
| login | `auth.login([secret])`; the `_session_id` cookie (path `<base>json`) is sent back by hand | `deluge/ui/web/auth.py:90-111, 243-256` |
| list | `core.get_torrents_status([{}, keys])` | `deluge/core/core.py:772`; keys `deluge/core/torrent.py:1035-1140` |
| show, files | `core.get_torrent_status([id, keys])`; file paths are relative to `download_location` | `core.py:758`; `torrent.py:93-123, 962-965` |
| shared-file check | `core.get_torrents_status([{}, ['files', 'download_location']])`: every torrent's files in one call | `core.py:771-790`; `torrent.py:759-770, 1128` |
| add | `core.add_torrent_magnet`, `core.add_torrent_url`, `core.add_torrent_file` (base64), options `add_paused`, `download_location` | `core.py:465-571`; `torrent.py:134-169` |
| remove | `core.remove_torrent([id, remove_data])` per id | `core.py:574-589`; `torrentmanager.py` (2.1.1) `:708-775` |
| pause, resume | `core.pause_torrents([[ids]])`, `core.resume_torrents([[ids]])`, never with an empty list (it means every torrent) | `core.py:673-680, 724-730` |
| stats | `daemon.get_version`, `core.get_libtorrent_version`, `core.get_listen_port` (free space comes from the quota, `lib/Quota.php`) | `deluge/core/daemon.py:147-148, 185-188` and `rpcserver.py:428-436` (registered as `daemon.*`); `core.py:831, 1266` |
| traffic | `core.get_session_status` (`payload_*_rate`, `total_payload_*`), `core.get_config_values` (`max_*_speed` in KiB/s, below 0 = no limit) | `core.py:67-112, 628-657, 817`; `deluge/core/preferencesmanager.py:72-73, 328-336` |

States map as `Downloading` → downloading, `Seeding` → seeding, `Paused` → paused, `Checking` and `Allocating` → checking, `Queued` → queued, `Error` → error; `Moving` has no shared word and is `unknown` (`torrent.py:51-60, 632-655`). Progress is divided by 100 (Deluge reports 0..100, `torrent.py:945-971`); a ratio of -1 (nothing downloaded yet, `torrent.py:747-757`) prints as 0. `message` is Deluge's status message unless it is the default `OK` (`torrent.py:676-687`), else a tracker status that starts with Error or Warning.

## Deleting data

`remove --with-data` runs the shared plan first (`lib/DeletePlan.php`: the exact `--expect` byte total and the shared-file check against the other torrents in this Deluge, because Deluge deletes shared files without asking; torrents of another client are not seen). Deluge then has libtorrent delete the torrent's files; there is no trash, so the disposal is `deleted`. The tool reads back that each torrent left the list, then `lib/DeleteRun.php` waits up to 5 s for the files to be gone: `freed_bytes` counts only files that are gone, a torrent with files still there becomes `partial`, and any error ends the call with `ok: false`.

## Restart

- **PMSS**, as the panel's restart does (`etc/skel/www/deluge.php:6-12`; `etc/skel/www/scriptsInc.php:1178-1191, 1762-1781`): refused unless `~/.delugeEnable` exists. SIGTERM to this user's own `deluged` and `deluge-web`, up to 3 s, SIGKILL to survivors, then the panel's start command `nohup deluged -l ~/.delugeLog -L info … & nohup deluge-web -l ~/.delugeWebLog -L info … &`, run from `$HOME` like the watchdog (`scripts/cron/checkDelugeInstances.php:18-24`). Processes are matched by owner uid, mount namespace (as PMSS does in `scriptsInc.php:1136-1175`, so a container's process of the same name is left alone) and exact process name (PMSS's watchdog uses `pgrep -u <user> <name>`, `scripts/lib/user/watchdog.php:44-47`); never `pkill -f`. Then the tool polls `daemon.get_version` for up to 30 s and reports `answered`.
- **Other hosts**: refused, with the provider's own way from its public documentation (fetched 2026-10-14): Feral restarts deluged by itself within five minutes and users cannot restart the web UI (feralhosting.com/wiki/software/deluge); Ultra.cc users run `app-deluge restart` (docs.ultra.cc/applications/deluge); Whatbox uses its Manage page (whatbox.ca/wiki/bittorrent_clients).

## Tests

`tests/Deluge*Test.php` (helpers in `tests/fixtures/deluge/helpers.php`) run the unit against a fake deluge-web (`tests/fixtures/deluge/router.php`, `php -S`), with HOME in a temporary directory. Two environment variables exist for the tests only: `PIKKUMIES_PMSS_DIR` (the directory whose existence means PMSS, default `/etc/seedbox/config`) and `PIKKUMIES_TORRENT_TIMEOUT` (seconds, default 10). The owner checks of another account's port run in-process on fixed answers (`DelugeLocate::find`'s `$tables`, `DelugeRpc::open`'s `$owns`), so they never depend on what else listens on the test machine.

## Unverified

- [UNVERIFIED] Process name: that `/proc/<pid>/comm` of a running deluged and deluge-web is exactly `deluged` / `deluge-web`. Inferred from PMSS's watchdog matching them by process name with `pgrep -u` (`watchdog.php:44-47`); not observed on a live host.
- [UNVERIFIED] Uptime assumes 100 clock ticks per second in `/proc/<pid>/stat` (USER_HZ); not read from a source this session.
- [UNVERIFIED] Other providers: whether deluge-web listens on 127.0.0.1 and whether its password equals the localclient secret. Feral's page says the daemon login is not the web UI login, so login may fail there; the tool then says so and stops (one attempt per call).
- [UNVERIFIED] Deluge 2.1.x: method names and keys were read in 2.0.3; 2.1.1 `json_api.py` was diffed against 2.0.3: it renames the session-status keys `web.update_ui` uses (this tool does not call it), accepts parameters after the content type and no longer escapes file paths; `core.py` 2.1.1 was not read in full.
- `deluge/ui/hostlist.py` (`get_hosts_info`, `get_host_status`, `connect_host`) was read from the Deluge repository at tag `deluge-2.0.3` (github.com/deluge-torrent/deluge, fetched 2026-10-14); it is not among the research notes' source copies.
- [UNVERIFIED] `label` is always empty: the Label plugin's status key was not checked, so it is not requested.
- [UNVERIFIED] deluge-web with `https` on is refused rather than tried, because lib/Http.php verifies certificates and deluge-web's is self-signed.
- Connection timeout: lib/Http.php fixes the connect timeout at 10 s; the tool sets the whole request to 10 s (the brief asked for 5 s connect).
