# jellyfin

Controls the user's own Jellyfin media server: status, libraries and scans, who is watching and ending a stream, recently added items, logs, and a restart the way the host runs it. It talks to Jellyfin's HTTP API on this machine's loopback, as the user, with the user's own credential. It never deletes anything, never creates an API key, never changes a setting and never calls Jellyfin's own restart.

## Where Jellyfin is found

1. **`--url http://127.0.0.1:<port>[/base path]`**, when given (loopback only; `lib/MediaHttp.php`).
2. **PMSS media-stack install** (host is PMSS when `/etc/seedbox/config` exists, `lib/Host.php`):
   - port: `~/.media-stack-port-jellyfin`, which PMSS writes root-side (PMSS `scripts/lib/mediaStackPorts.php:81`) and the installer reads first, only as a plain file (`etc/skel/install-media-stack.sh:1434-1448`); then `InternalHttpPort` or `PublicHttpPort` in `~/.config/jellyfin/config/network.xml` (`install-media-stack.sh:2252-2253`, config dir `:1224`);
   - base path: `BaseUrl` from `network.xml`, else `system.xml` (`/public-<user>/jellyfin`, `install-media-stack.sh:2519, 2528`). Jellyfin 12 serves the API only under it, 10.11 also at the bare path, so the tool tries the prefixed path, then the bare one, as the installer does (`install-media-stack.sh:1816-1838`). When neither answers (stopped, or starting: Jellyfin 12 answers 302 on the bare path and 503 on the prefix, `:1816-1818`) it uses the prefixed path, and `restart` waits on both;
   - logs: `~/.config/jellyfin/log` (`install-media-stack.sh:1226`), read from disk.
3. **A container on the user's own rootless Docker**: only the socket `/run/user/<uid>/docker.sock` the user owns (PMSS `docs/docker-help.md:17`), a running container whose image is `jellyfin` (official `jellyfin/jellyfin`, or `lscr.io/linuxserver/jellyfin` as PMSS `docker-install-lsio` runs it, `scripts/lib/user/dockerInstallLsio.php:155, 229-234`), at the host port it publishes for 8096. No base path.
4. **The default config directory** `~/.config/jellyfin` (`network.xml` there or in `config/`) [UNVERIFIED: Jellyfin's default layout and the Whatbox layout come from the research file, not read this session].

Every request goes only to a listener this user's uid owns in `/proc/net/tcp(6)` (ADR-0019); another account's port is refused before anything is sent.

## Credentials

1. An API key the user stored with `login` (typed at a terminal, checked once, `local/secrets/jellyfin`, 0700/0600).
2. On PMSS: `JELLYFIN_USERNAME` and `JELLYFIN_PASSWORD` from the user's own `~/.media-stack-credentials.txt`, parsed as PMSS parses it (`KEY = value`; a value starting with `[` is a placeholder; `install-media-stack.sh:1470-1484`, written mode 600 at `:2018-2066`). One `POST /Users/AuthenticateByName`, as the installer itself makes (`install-media-stack.sh:1901`); the session token is kept in `local/secrets/jellyfin` and reused. ADR-0019 allows reading credentials from the user's own files; a login session is not a new API key.
3. Otherwise the error's hint is `login`.

A refused key or password is marked and never tried again until `login` (the account could be locked). A refused session is cleared and the next call logs in once more. Jellyfin keeps one token per device id (live 12.2.0: a second login with the same device id made the first token answer 401), so the tool uses its own stable device id and does not end the installer's or the user's sessions.

## Jellyfin calls

Paths, methods, parameters and policies from the Jellyfin OpenAPI spec 12.2.0 (`https://api.jellyfin.org/openapi/jellyfin-openapi-stable.json`, downloaded 2026-10-14, sha256 `bd31bb19...`), and checked live against `jellyfin/jellyfin:latest` (12.2.0, image of 2026-10-05) in a throwaway local container on 2026-10-14.

| verb | calls |
|---|---|
| all | header `Authorization: MediaBrowser Client="pikkumies", Device="pikkumies", DeviceId="pikkumies-<hex>", Version="1", Token="<token>"` (live: 200; `X-Emby-Token` 401 on 12.2.0) |
| stats | `GET /System/Info` (Version, ServerName, HasPendingRestart, HasUpdateAvailable), `GET /Items/Counts`, `GET /System/Configuration/encoding` (`HardwareAccelerationType`; live: `"none"`), `GET /ScheduledTasks` (State), `GET /Sessions` |
| libraries | `GET /Library/VirtualFolders` (Name, Locations, CollectionType, ItemId, RefreshProgress, RefreshStatus; live: `Idle`), `GET /Items?parentId&recursive&mediaTypes&limit=0` (TotalRecordCount) |
| scan | `POST /Items/{ItemId}/Refresh?metadataRefreshMode=Default&imageRefreshMode=Default` (the spec defaults both to `None`; live, both a bare and a `Default` refresh found newly added files) |
| sessions | `GET /Sessions`: only sessions with a `NowPlayingItem`; PlayState.PlayMethod (Transcode, DirectStream, DirectPlay), TranscodingInfo (Bitrate, HardwareAccelerationType, TranscodeReasons), RemoteEndPoint |
| end-stream | `POST /Sessions/{id}/Message` (MessageCommand body, with `--reason`), `POST /Sessions/{id}/Playing/Stop` (live: 204; unknown id 404) |
| recent | `GET /Users` (first administrator), `GET /Items/Latest?userId&parentId&limit&fields=DateCreated` per library |
| logs | the newest `*.log` on disk (PMSS), else `GET /System/Logs` and `GET /System/Logs/Log?name=` (live: `log_YYYYMMDD.log`; it contained IPv4 addresses, which the tool replaces) |
| restart | `GET /System/Info/Public` (no credential) until it answers |

Never sent (not in `Mediabrowser::ALLOW`): any DELETE (the spec's `DELETE /Items` "Deletes items from the library and filesystem"), `/Auth/Keys`, `/System/Restart` (policy `LocalAccessOrRequiresElevation`), `/System/Shutdown`, `/Startup/*`, configuration or user writes.

## Restart

- **PMSS install**, the panel's own way (PMSS `etc/skel/www/mediaStack.php:70-104`): refused while `~/.jellyfinDisable` exists or is not a plain file (`:80-86`; the installer's `--start-app` would start it anyway, `install-media-stack.sh:299`), or when `~/install-media-stack.sh` or `~/.bashrc.custom` is missing or a link (`userMediaStackPanel.php:155-160`). Then `cd ~ && HOME=~ /bin/bash ~/install-media-stack.sh --stop-app=jellyfin` and `--start-app=jellyfin` (`install-media-stack.sh:171-177, 320-330`; these modes skip the installer's self-update, `:45`). Between the two it waits up to 15 s for Jellyfin to stop answering; if it still answers, the result is `stop not confirmed` and `--start-app` is not run. Then it waits for `/System/Info/Public` on every base path. A slow start is reported as "not answering (unknown, not down)". No credential is loaded or sent, so a restart works with the server down and no session stored.
- **Rootless Docker**: `docker restart <container>` on the user's own socket.
- **Elsewhere**: refused with the provider's way (Ultra.cc `app-jellyfin restart`, Whatbox's Settings page) [UNVERIFIED: from the research file's reading of their docs, not re-read this session].
- A plain or piped re-run of the installer is never used: with an existing `~/.config/jellyfin` it asks on stdin and then deletes Jellyfin's users, metadata and watch state (`install-media-stack.sh:1294-1301`).

## Tests

`tests/MediaTest.php`, `MediaRedactTest.php`, `MediaJellyfinTest.php`, `MediaJellyfinRestartTest.php` and `MediaUnitsTest.php` run the unit against a fake Jellyfin (`tests/fixtures/media/mediabrowser.php`, `php -S`) shaped on the 12.2.0 spec and the live answers above.

## Unverified

- [UNVERIFIED] `LoginAttemptsBeforeLockout`: a fresh 12.2.0 administrator shows `-1` (live); how many failures lock an account was not checked, so the tool simply never retries.
- [UNVERIFIED] `RefreshStatus` values other than `Idle` (only `Idle` was seen live); any other value, or a non-null `RefreshProgress`, counts as scanning.
- [UNVERIFIED] Behind the PMSS proxy Jellyfin may see every viewer as 127.0.0.1, so `remote: false` is not proof that a viewer is local.
- [UNVERIFIED] Whether an API key (not a login session) needs `userId` on `/Items/Latest`; the tool always sends the first administrator's id.
- [UNVERIFIED] The dashboard menu label for API keys on 12.x.
