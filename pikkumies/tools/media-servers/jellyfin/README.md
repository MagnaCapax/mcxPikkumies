# jellyfin

Controls the user's own Jellyfin media server: status, libraries and scans, who is watching and ending a stream, recently added items, logs, and a restart the way the host runs it. It talks to Jellyfin's HTTP API on this machine's loopback, as the user, with the user's own credential. It never deletes anything, never creates an API key, changes a setting or its login only through `config set` and `set-login`, and never calls Jellyfin's own restart.

## Where Jellyfin is found

1. **`--url http://127.0.0.1:<port>[/base path]`**, when given (loopback only; `lib/MediaHttp.php`).
2. **PMSS media-stack install** (host is PMSS when `/etc/seedbox/config` exists, `lib/Host.php`):
   - port: `~/.media-stack-port-jellyfin`, which PMSS writes root-side (PMSS `scripts/lib/mediaStackPorts.php:81`) and the installer reads first, only as a plain file (`etc/skel/install-media-stack.sh:1434-1448`); then `InternalHttpPort` or `PublicHttpPort` in `~/.config/jellyfin/config/network.xml` (`install-media-stack.sh:2252-2253`, config dir `:1224`);
   - base path: `BaseUrl` from `network.xml`, else `system.xml` (`/public-<user>/jellyfin`, `install-media-stack.sh:2519, 2528`). Jellyfin 12 serves the API only under it, 10.11 also at the bare path, so the tool tries the prefixed path, then the bare one, as the installer does (`install-media-stack.sh:1816-1838`). When neither answers (stopped, or starting: Jellyfin 12 answers 302 on the bare path and 503 on the prefix, `:1816-1818`) it uses the prefixed path, and `restart` waits on both;
   - logs: `~/.config/jellyfin/log` (`install-media-stack.sh:1226`), read from disk.
3. **A container on the user's own rootless Docker**: only a socket the user owns: a `unix://` `DOCKER_HOST`, else `/run/user/<uid>/docker.sock` (PMSS `docs/docker-help.md:17`), the same rule as the *ARR units (`lib/Host.php` `dockerSocket`); `tcp://` and a root or other account's daemon are never used, a running container whose image is `jellyfin` (official `jellyfin/jellyfin`, or `lscr.io/linuxserver/jellyfin` as PMSS `docker-install-lsio` runs it, `scripts/lib/user/dockerInstallLsio.php:155, 229-234`), at the host port it publishes for 8096. No base path.
4. **The default config directory** `~/.config/jellyfin` (`network.xml` there or in `config/`) [UNVERIFIED: Jellyfin's default layout and the Whatbox layout come from the research file, not read this session].

Every request goes only to a listener this user's uid owns in `/proc/net/tcp(6)` ([ADR-0019](../../../../docs/adr/0019-hosts-and-credentials.md)); another account's port is refused before anything is sent.

## Credentials

1. An API key the user stored with `login` (typed at a terminal, checked once, `local/secrets/jellyfin`, 0700/0600).
2. On PMSS: `JELLYFIN_USERNAME` and `JELLYFIN_PASSWORD` from the user's own `~/.media-stack-credentials.txt`, parsed as PMSS parses it (`KEY = value`; a value starting with `[` is a placeholder; `install-media-stack.sh:1470-1484`, written mode 600 at `:2018-2066`). One `POST /Users/AuthenticateByName`, as the installer itself makes (`install-media-stack.sh:1901`); the session token is kept in `local/secrets/jellyfin` and reused. ADR-0019 allows reading credentials from the user's own files; a login session is not a new API key.
3. Otherwise the error's hint is `login`.

A refused key or password is marked and never tried again until `login` (the account could be locked). A refused session is cleared and the next call logs in once more. Jellyfin keeps one token per device id (live 12.2.0: a second login with the same device id made the first token answer 401), so the tool uses its own stable device id and does not end the installer's or the user's sessions. The id is stored in `local/secrets/jellyfin` only when a request that carries it is about to be sent: a call refused before that (another account's port, nothing listening, no credential) writes nothing. With `--url`, the port's owner is checked before the credential is looked at, so another account's port is the error, not a missing credential.

## Jellyfin calls

Paths, methods, parameters and policies from the Jellyfin OpenAPI spec 12.2.0 (`https://api.jellyfin.org/openapi/jellyfin-openapi-stable.json`, downloaded 2026-10-14, sha256 `bd31bb19...`), and checked live against `jellyfin/jellyfin:latest` (12.2.0, image of 2026-10-05) in a throwaway local container on 2026-10-14.

| verb | calls |
|---|---|
| all | header `Authorization: MediaBrowser Client="pikkumies", Device="pikkumies", DeviceId="pikkumies-<hex>", Version="1", Token="<token>"` (live: 200; `X-Emby-Token` 401 on 12.2.0) |
| stats | `GET /System/Info` (Version, ServerName, HasPendingRestart, HasUpdateAvailable), `GET /Items/Counts` (the per-type counts of the spec's `ItemCounts`, MovieCount to BookCount; its `ItemCount` is not used: live, with an API key, it said 8, and 3 with a userId, for two movies), the libraries' counts as `libraries` reads them (`items`), `GET /System/Configuration/encoding` (`HardwareAccelerationType`; live: `"none"`), `GET /ScheduledTasks` (State), `GET /Sessions` |
| libraries | `GET /Library/VirtualFolders` (Name, Locations, CollectionType, ItemId, RefreshProgress, RefreshStatus; live: `Idle`), `GET /Items?parentId&recursive&mediaTypes&limit=0` (TotalRecordCount) |
| scan | `POST /Items/{ItemId}/Refresh?metadataRefreshMode=Default&imageRefreshMode=Default` (the spec defaults both to `None`; live, both a bare and a `Default` refresh found newly added files) |
| sessions | `GET /Sessions`: only sessions with a `NowPlayingItem`; PlayState.PlayMethod (Transcode, DirectStream, DirectPlay), TranscodingInfo (Bitrate, HardwareAccelerationType, TranscodeReasons), RemoteEndPoint |
| end-stream | `POST /Sessions/{id}/Message` (MessageCommand body, with `--reason`), `POST /Sessions/{id}/Playing/Stop` (live: 204; unknown id 404) |
| recent | `GET /Users` (first administrator), `GET /Items/Latest?userId&parentId&limit&fields=DateCreated` per library |
| logs | the newest `*.log` on disk (PMSS), else `GET /System/Logs` and `GET /System/Logs/Log?name=` (live: `log_YYYYMMDD.log`; it contained IPv4 addresses, which the tool replaces) |
| restart | `GET /System/Info/Public` (no credential) until it answers |

Never sent (not in `Mediabrowser::ALLOW`): any DELETE (the spec's `DELETE /Items` "Deletes items from the library and filesystem"), `/Auth/Keys`, `/System/Restart` (policy `LocalAccessOrRequiresElevation`), `/System/Shutdown`, `/Startup/*`, configuration or user writes (`POST /Users/Password` and `POST /System/Configuration` only in `JellyfinLogin::ALLOW`, for `set-login` and `config`; "Login and settings").

## Login and settings

`login-check`, `set-login` and `config` (`JellyfinLogin.php`, `JellyfinSettings.php`), under the rules of the *ARR login verbs ([ADR-0078](../../../../docs/adr/0078-changing-the-login-of-the-users-own-apps.md), amendment 2026-10-19; `tools/arr/README.md` "Login of the other apps"). The login is `JELLYFIN_USERNAME` and `JELLYFIN_PASSWORD` in `~/.media-stack-credentials.txt`, the keys the PMSS installer writes (`install-media-stack.sh:1704-1705`, `media_stack_credentials_app_write` `:2213-2228`). Only these verbs build the client with `JellyfinLogin::ALLOW` on top of `Mediabrowser::ALLOW`; every other verb still cannot send a write.

| verb | calls | source (Jellyfin tag `v12.2`; the same routes in `v10.10.7` and `v10.11.0`) |
|---|---|---|
| login-check | `GET /System/Info/Public` (`StartupWizardCompleted`), then one `POST /Users/AuthenticateByName` with the file's pair; the session it gets is kept, as every verb's | `Jellyfin.Api/Controllers/UserController.cs:209-212` |
| set-login | `POST /Users/AuthenticateByName` with the current pair; the new one is written to the file; `POST /Users/Password?userId=<own id>` with `{"CurrentPw", "NewPw"}` (204; with `userId` equal to the session's own user Jellyfin checks the current one even for an administrator, `:297-306`, then revokes the user's other tokens, `:313-315`); then `POST /Users/AuthenticateByName` with the new pair as the check | `UserController.cs:270-319`; OpenAPI 12.2.0 `UpdateUserPassword` |
| config | `GET /System/Configuration`; `set` posts the whole object back to `POST /System/Configuration` with one field changed (Jellyfin replaces the configuration with what it gets: `ReplaceConfiguration`), then reads it again | `Jellyfin.Api/Controllers/ConfigurationController.cs:49-69`; fields `MediaBrowser.Model/Configuration/ServerConfiguration.cs`, `BaseApplicationConfiguration.cs` |

A refused change puts the file back. When the change call gets no answer at all, one login with the new pair, then one with the old pair, settle it: the new one works, the file keeps it; only the old one works, the old line comes back; neither, the file keeps both, the new one as `JELLYFIN_PASSWORD` and the old one as `JELLYFIN_PASSWORD_PREVIOUS` after it, and the error says to try the new one first (a later `set-login` that works removes that line; `login-check` mentions it). These logins mark nothing. `config set` never sets the fields in `JellyfinSettings::NEVER`: `IsStartupWizardCompleted` (false would reopen `/Startup/User` to anyone on this machine), `EnableLegacyAuthorization`, `QuickConnectAvailable`, `IsPortAuthorized`, `EnableMetrics` (an unauthenticated `/metrics` on the shared loopback), `MetadataPath`, `CachePath` and the internal migration flags; a field with no value (type unknown), a list or an object is refused too. The network configuration (`/System/Configuration/network`) is not on any allowlist. Jellyfin's own Forgot Password (a PIN file in its data folder for a request from the local network, `Jellyfin.Server.Implementations/Users/DefaultPasswordResetProvider.cs:51-130` on master `08c2ba6e`) is not used by the tool: it is the user's step (skill.md).

### Why a Jellyfin login fails

| cause | what it means | next |
|---|---|---|
| not-installed | Jellyfin was not found for this user. | The error's hint (on PMSS: the media-stack installer). |
| not-answering | Jellyfin does not answer (stopped, starting, busy, or another account's port). | The error's hint, usually `restart` |
| startup-not-complete | The setup wizard was never finished: there is no administrator, and anyone on this machine could make one. | On PMSS `bash ~/install-media-stack.sh --secure-app=jellyfin`; elsewhere the user finishes the wizard now |
| credentials-missing | `~/.media-stack-credentials.txt` has no `JELLYFIN_USERNAME` and `JELLYFIN_PASSWORD` lines (or there is no file). | The user's own step (skill.md), then `set-login` |
| credentials-placeholder | The line holds the installer's `[...]` placeholder: it kept a login it did not know. | The user's own step (skill.md), then `set-login` |
| password-rejected | Jellyfin refused the pair in the file (now, or before: then it is not tried again). | The user's own step (skill.md), then `set-login` |

## Restart

- **PMSS install**, the panel's own way (PMSS `etc/skel/www/mediaStack.php:70-104`): refused while `~/.jellyfinDisable` exists or is not a plain file (`:80-86`; the installer's `--start-app` would start it anyway, `install-media-stack.sh:299`), or when `~/install-media-stack.sh` or `~/.bashrc.custom` is missing or a link (`userMediaStackPanel.php:155-160`). Then `cd ~ && HOME=~ /bin/bash ~/install-media-stack.sh --stop-app=jellyfin` and `--start-app=jellyfin` (`install-media-stack.sh:171-177, 320-330`; these modes skip the installer's self-update, `:45`). Between the two it waits up to 15 s for Jellyfin to stop answering; if it still answers, the result is `stop not confirmed` and `--start-app` is not run. Then it waits for `/System/Info/Public` on every base path. A slow start is reported as "not answering (unknown, not down)". No credential is loaded or sent, so a restart works with the server down and no session stored.
- **Rootless Docker**: `docker restart <container>` on the user's own socket.
- **Elsewhere**: refused with the provider's way (Ultra.cc `app-jellyfin restart`, Whatbox's Settings page) [UNVERIFIED: from the research file's reading of their docs, not re-read this session].
- A plain or piped re-run of the installer is never used: with an existing `~/.config/jellyfin` it asks on stdin and then deletes Jellyfin's users, metadata and watch state (`install-media-stack.sh:1294-1301`).

## Tests

`tests/MediaTest.php`, `MediaRedactTest.php`, `MediaJellyfinTest.php`, `MediaJellyfinRestartTest.php`, `MediaJellyfinLoginTest.php` (login and settings) and `MediaUnitsTest.php` run the unit against a fake Jellyfin (`tests/fixtures/media/mediabrowser.php`, `php -S`) shaped on the 12.2.0 spec and the live answers above.

## Unverified

- [UNVERIFIED] `LoginAttemptsBeforeLockout`: a fresh 12.2.0 administrator shows `-1` (live); how many failures lock an account was not checked, so the tool simply never retries.
- [UNVERIFIED] `RefreshStatus` values other than `Idle` (only `Idle` was seen live); any other value, or a non-null `RefreshProgress`, counts as scanning.
- [UNVERIFIED] Behind the PMSS proxy Jellyfin may see every viewer as 127.0.0.1, so `remote: false` is not proof that a viewer is local.
- [UNVERIFIED] Whether an API key (not a login session) needs `userId` on `/Items/Latest`; the tool always sends the first administrator's id.
- [UNVERIFIED] The dashboard menu label for API keys on 12.x.
- [UNVERIFIED] `login-check`, `set-login` and `config` were run against the fake only, never a live Jellyfin; their calls are read from the source at tag v12.2 and the 12.2.0 OpenAPI spec (the password route is the same in v10.10.7 and v10.11.0).
