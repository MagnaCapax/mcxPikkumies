# emby

Controls the user's own Emby server: status, libraries and scans, who is watching and ending a stream, recently added items, logs, and a restart of its container. Emby is the user's own install; Pulsed Media's PMSS does not install it (PMSS `docs/linuxserver.io.md:341-347`). The tool talks to Emby's HTTP API on this machine's loopback, as the user, with an API key the user stored. It shares its client with the Jellyfin unit (`lib/Mediabrowser.php`; Jellyfin is a fork of Emby).

## Where Emby is found

1. **`--url http://127.0.0.1:<port>`** (loopback only; `/emby` is added when missing).
2. **A container on the user's own rootless Docker**: only a socket the user owns: a `unix://` `DOCKER_HOST`, else `/run/user/<uid>/docker.sock` (PMSS `docs/docker-help.md:17`), the same rule as the *ARR units (`lib/Host.php` `dockerSocket`); `tcp://` and a root or other account's daemon are never used, a running container whose image is `emby` or `embyserver` (`lscr.io/linuxserver/emby` as in PMSS's recipe, `docs/linuxserver.io.md:366-381`, or the official `emby/embyserver`), at the host port it publishes for 8096 (the recipe uses `-p 8097:8096`, which publishes on every interface; `stats` shows that as `listen: 0.0.0.0:8097`).

Every request goes only to a listener this user's uid owns (ADR-0019).

## Credentials

An API key the user stored with `login`, sent only as the `X-Emby-Token` header (Emby also accepts an `api_key` URL parameter, which ends up in logs; the tool never sends one). No Emby password file exists to read. A refused key is marked and not tried again until `login`.

## Emby calls

Paths and parameters from the Emby OpenAPI spec 4.1.1.0 (`http://swagger.emby.media/openapi.json`, downloaded 2026-10-14, sha256 `475716b9...`; an old version), checked live against `emby/embyserver:latest` (Emby 4.10.1.0) in a throwaway local container on 2026-10-14.

| verb | calls |
|---|---|
| all | base `/emby` (live: answers there, and at the bare path), header `X-Emby-Token` (live: 200; no credential: 401) |
| stats | `GET /System/Info`, `GET /Items/Counts` (the per-type counts, MovieCount to BookCount plus GameCount and GameSystemCount in the 4.1.1.0 spec; `ItemCount` is not used: live 4.10.1.0 said 0 with a key, a userId and a login session while MovieCount was 2), the libraries' counts as `libraries` reads them (`items`), `GET /System/Configuration/encoding` (live: the hardware setting is `HardwareAccelerationMode`; there is no `HardwareAccelerationType`), `GET /ScheduledTasks`, `GET /Sessions` |
| libraries | `GET /Library/VirtualFolders` (live: `ItemId` is a short number, e.g. `3`), `GET /Items?ParentId&Recursive&MediaTypes&Limit=0` |
| scan | `POST /Items/{Id}/Refresh?Recursive=true&MetadataRefreshMode=Default&ImageRefreshMode=Default` (live: 204) |
| sessions | `GET /Sessions` (Session.SessionInfo; TranscodingInfo with VideoDecoderIsHardware / VideoEncoderIsHardware) |
| end-stream | `POST /Sessions/{Id}/Message?Text&Header&TimeoutMs` (with `--reason`), `POST /Sessions/{Id}/Playing/Stop` |
| recent | `GET /Users` (first administrator), `GET /Users/{UserId}/Items/Latest?ParentId&Limit&Fields=DateCreated` per library (live: 200) |
| logs | `GET /System/Logs`, `GET /System/Logs/Log?Name=` (live: `embyserver.txt`, plain text) |
| restart | `GET /System/Info/Public` (no credential) until it answers |

Parameter names are sent in the spec's PascalCase. Never sent: any DELETE (including `DELETE /Videos/ActiveEncodings`, which the spec offers to kill a transcode), `/Auth/Keys`, `/System/Restart`, `/System/Shutdown`, `/Startup/*`, configuration or user writes.

## Restart

A container on the user's own rootless Docker: `docker restart <container>`, then the tool waits for `/System/Info/Public`; no API key is needed or sent. Anything else (a `--url` install) is refused with a hint: the tool does not know how that Emby is run.

## Tests

`tests/MediaEmbyTest.php` and `tests/MediaUnitsTest.php` run the unit against the fake in `tests/fixtures/media/mediabrowser.php` in Emby mode, with a fake `docker` and a socket the test owns.

## Unverified

- [UNVERIFIED] The dashboard menu for API keys: the 4.1.1.0 spec says "Advanced > Security"; newer versions may label it differently.
- [UNVERIFIED] `RefreshStatus` while a scan runs (live 4.10.1.0 showed `null` when idle).
- [UNVERIFIED] Whether Emby's `/Items/Counts` and the per-library counts need a user id when called with an API key (live checks used a login session).
- [UNVERIFIED] That `/proc/net/tcp` shows the user's own uid for a port published by rootless Docker. If it does not, the tool refuses (the safe direction) and `--url` cannot help.
