# plex

Controls the user's own Plex Media Server: status, library sections and scans, who is watching and ending a stream, recently added items, logs, and a restart of its container. Plex is the user's own install: PMSS includes it in its docs only as "an upstream-deferred catalog example" with a rootless-Docker recipe (PMSS `docs/linuxserver.io.md:341-347, 385-399`). The tool talks to the server's local HTTP API on this machine's loopback, as the user. It never contacts plex.tv or any other host.

## Where Plex is found

1. **`--url http://127.0.0.1:<port>`** (loopback only).
2. **A container on the user's own rootless Docker**: only a socket the user owns: a `unix://` `DOCKER_HOST`, else `/run/user/<uid>/docker.sock` (PMSS `docs/docker-help.md:17`), the same rule as the *ARR units (`lib/Host.php` `dockerSocket`); `tcp://` and a root or other account's daemon are never used, a running container whose image is `plex` or `pms-docker` (`lscr.io/linuxserver/plex` as in PMSS's recipe, or `plexinc/pms-docker`), at the host port it publishes for 32400. PMSS's recipe publishes `-p 32400:32400` on every interface (`docs/linuxserver.io.md:391`); `stats` shows that as `listen: 0.0.0.0:32400`. Only one account per server can hold 32400, so the published port is read, not assumed.
3. **Data directory** (`PlexLocal.php`; its `Logs/` and `Preferences.xml`): Plex keeps it in `$PLEX_MEDIA_SERVER_APPLICATION_SUPPORT_DIR/Plex Media Server`, and that variable defaults to the running user's `$HOME/Library/Application Support` (Plex support article 202915258, "$PLEX_HOME/Library/Application Support/Plex Media Server/"; the `plexinc/pms-docker` image's `/etc/services.d/plex/run:5`; the package's `plexmediaserver.service:12` shows the same for a user's home). Found on Docker: the host side of the container's `/config` mount (`docker inspect`) plus `Library/Application Support/Plex Media Server`, only that folder when the container has a `/config` mount (a live 1.43.4 container kept its `Logs/` and `Preferences.xml` there). Otherwise (`--url`), in order: `$PLEX_MEDIA_SERVER_APPLICATION_SUPPORT_DIR` when set, `~/Library/Application Support`, and `~/docker/plex/config/Library/Application Support` (the PMSS recipe's mount, `docs/linuxserver.io.md`), each plus `Plex Media Server`. Only the user's own folders: never `/var/lib/plexmediaserver`, a system install's.

Every request goes only to a listener this user's uid owns (ADR-0019).

## Credentials

1. A token the user stored with `login` (typed at a terminal, checked once, `local/secrets/plex`, 0700/0600). Plex answers a caller on an allowed network (`allowedNetworks`) and, while unclaimed, its own subnet without any token, and then takes any token (live 1.43.4, 2026-10-14). So `login` reads `/library/sections/all` with the token and again with none: if both answer, it stores the token with `verified:false` and says the server did not check it.
2. Else `PlexOnlineToken` from `Preferences.xml` in the first data directory that has one ("Where Plex is found"). A live run read a token put there by hand and sent it; [UNVERIFIED] the attribute on a real claimed server.

The token is the user's Plex **account** token, wider than this server. It goes only in the `X-Plex-Token` header (never the URL parameter Plex also accepts), only to a listener this user owns, and never to plex.tv. A refused token is marked and not tried again until `login`.

## Plex calls

The official spec is published on developer.plex.tv, which this tool and its tests never contact; it was not re-fetched. Shapes were read in the community OpenAPI spec "Plex Media Server" 1.1.1 (`https://raw.githubusercontent.com/LukeHagar/plex-api-spec/main/plex-api-spec.yaml`, downloaded 2026-10-14, sha256 `ff8801eb6c7904e6...`), which is not Plex's own: every row below is [UNVERIFIED-spec] except where it says "live". A live run on 2026-10-14 against an unclaimed Plex Media Server 1.43.4.10903 (`plexinc/pms-docker`, no plex.tv access) ran every verb; a claimed server (plex.tv account) was not tested.

| verb | calls (community spec 1.1.1) |
|---|---|
| all | headers `X-Plex-Token`, `Accept: application/json`, `X-Plex-Client-Identifier`, `X-Plex-Product`; answers are `{"MediaContainer": {...}}` (unwrapped when present) |
| stats | `GET /identity` (open, `version`), `GET /` (`friendlyName`), `GET /:/prefs` (`Setting[]` with `id`, `value`; the ids that mention hardware are reported as found), `GET /status/sessions` (optional here: refused, `streams` and `transcodes` are null with a `note`) |
| libraries | `GET /library/sections/all` (`Directory[]`: `key`, `title`, `type`, `refreshing`, `Location[].path`), `GET /library/sections/{id}/all` with `X-Plex-Container-Size=0` (`totalSize`) |
| scan | `POST /library/sections/{id}/refresh` (operationId `refreshSectionPost`, spec file lines 41951-42011; the same path also has `GET` `refreshSection` without parameters and `DELETE` `cancelRefresh`; `force` and `path` are parameters of the POST), without `force` ("whether the update of metadata and items should be performed even if modification dates indicate the items have not change[d]") and without `path` |
| sessions | `GET /status/sessions` (`Metadata[]` with `Session.id` (live: a row from a client that only reports its timeline has no `Session`; its id is then `sessionKey`, else `Player.machineIdentifier`; [UNVERIFIED] whether terminate ends such a row: the read-back reports it), `User.title`, `Player` `title`/`product`/`address`/`remotePublicAddress`/`local`/`state`, `TranscodeSession` `transcodeHwRequested`/`transcodeHwFullPipeline`) |
| end-stream | `POST /status/sessions/terminate?sessionId=&reason=` (security `admin`; documented 401: "Server does not have the feature enabled", which the tool reports without marking the token) |
| recent | `GET /library/recentlyAdded` with `X-Plex-Container-Start`/`-Size` as query parameters (in the community spec; the research file found it absent from the official 1.2.3 spec). Live 1.43.4 honoured them and answered `totalSize` (3 for a size-2 request), which a truncated call prints as `total` |
| logs | none: Plex has no log-reading endpoint; `Plex Media Server.log` (or `--log <name>`) in `<data dir>/Logs/` (live: the server's log and the scanner's, tuner's and crash uploader's beside it; right after a scan the newest file was the scanner's, so the newest is no longer taken) |
| restart | `GET /identity` (no credential; restart loads none) until it answers |

Never sent (not in `PlexClient::ALLOW`): any DELETE (including `DELETE /library/metadata/{id}`, which removes media), `/updater/apply`, preference writes (such as the networks allowed without authentication), and anything on plex.tv.

## Restart

A container on the user's own rootless Docker: `docker restart <container>`, then the tool waits for `/identity` (live: `StartedAt` moved, `answered:true`). Plex has no PMSS media-stack install, so with `--url` the refusal names the container as the one way this tool restarts Plex, and the hint gives the provider's way [UNVERIFIED: from the research file's reading of provider docs]: Ultra.cc runs Plex in its own container; Feral restarts Plex through a control file in `~/private/plex/control-files/`; Whatbox from its Settings page. Plex has no restart endpoint in its API (research file), and `/updater/apply` is never called.

## Tests

`tests/MediaPlexTest.php`, `tests/MediaPlexLiveFixesTest.php` (the defects the live run found) and `tests/MediaUnitsTest.php` run the unit against a fake Plex (`tests/fixtures/media/plex.php`) shaped on the community spec and on what the live server answered, with a fake `docker` and a socket the test owns.

## Unverified

- [UNVERIFIED-spec] "Plex calls" beyond what the live run on an unclaimed 1.43.4 server showed.
- [UNVERIFIED] Whether terminate succeeds on a claimed server with Plex Pass (the unclaimed one answered 401), and whether it accepts a `sessionKey`.
- [UNVERIFIED] `PlexOnlineToken` on a real claimed server, and that the user can read the file in a rootless container.
- [UNVERIFIED] Whether Plex has an endpoint that asks for the token even from an allowed network; `login` therefore compares with a tokenless read instead.
- [UNVERIFIED] `videoDecision` / `audioDecision`: the tool calls a session `direct` only when a `TranscodeSession` says both are not `transcode`; without those fields a `TranscodeSession` counts as a transcode.
- [UNVERIFIED] `update_available` is not read (null).
