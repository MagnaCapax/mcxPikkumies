# plex

Controls the user's own Plex Media Server: status, library sections and scans, who is watching and ending a stream, recently added items, logs, and a restart of its container. Plex is the user's own install: PMSS includes it in its docs only as "an upstream-deferred catalog example" with a rootless-Docker recipe (PMSS `docs/linuxserver.io.md:341-347, 385-399`). The tool talks to the server's local HTTP API on this machine's loopback, as the user. It never contacts plex.tv or any other host.

## Where Plex is found

1. **`--url http://127.0.0.1:<port>`** (loopback only).
2. **A container on the user's own rootless Docker**: only the socket `/run/user/<uid>/docker.sock` the user owns (PMSS `docs/docker-help.md:17`), a running container whose image is `plex` or `pms-docker` (`lscr.io/linuxserver/plex` as in PMSS's recipe, or `plexinc/pms-docker`), at the host port it publishes for 32400. PMSS's recipe publishes `-p 32400:32400` on every interface (`docs/linuxserver.io.md:391`); `stats` shows that as `listen: 0.0.0.0:32400`. Only one account per server can hold 32400, so the published port is read, not assumed.
3. **Data directory**: the host side of the container's `/config` mount (`docker inspect`), plus `Library/Application Support/Plex Media Server` [UNVERIFIED: the layout comes from the research file's reading of Plex's data-directory article and the LinuxServer image docs, not read this session].

Every request goes only to a listener this user's uid owns (ADR-0019).

## Credentials

1. A token the user stored with `login` (typed at a terminal, checked once, `local/secrets/plex`, 0700/0600).
2. Else `PlexOnlineToken` from `Preferences.xml` in the data directory [UNVERIFIED: attribute name and file place are third-party consensus in the research file; not checked against a live server].

The token is the user's Plex **account** token, wider than this server. It goes only in the `X-Plex-Token` header (never the URL parameter Plex also accepts), only to a listener this user owns, and never to plex.tv. A refused token is marked and not tried again until `login`.

## Plex calls

The official spec is published on developer.plex.tv, which this tool and its tests never contact; it was not re-fetched. Shapes were read in the community OpenAPI spec "Plex Media Server" 1.1.1 (`https://raw.githubusercontent.com/LukeHagar/plex-api-spec/main/plex-api-spec.yaml`, downloaded 2026-10-14, sha256 `ff8801eb6c7904e6...`), which is not Plex's own: every row below is [UNVERIFIED-spec], and no live Plex test was run (a claimed server needs a plex.tv claim token).

| verb | calls (community spec 1.1.1) |
|---|---|
| all | headers `X-Plex-Token`, `Accept: application/json`, `X-Plex-Client-Identifier`, `X-Plex-Product`; answers are `{"MediaContainer": {...}}` (unwrapped when present) |
| stats | `GET /identity` (open, `version`), `GET /` (`friendlyName`), `GET /:/prefs` (`Setting[]` with `id`, `value`; the ids that mention hardware are reported as found), `GET /status/sessions` |
| libraries | `GET /library/sections/all` (`Directory[]`: `key`, `title`, `type`, `refreshing`, `Location[].path`), `GET /library/sections/{id}/all` with `X-Plex-Container-Size=0` (`totalSize`) |
| scan | `POST /library/sections/{id}/refresh` (operationId `refreshSectionPost`, spec file lines 41951-42011; the same path also has `GET` `refreshSection` without parameters and `DELETE` `cancelRefresh`; `force` and `path` are parameters of the POST), without `force` ("whether the update of metadata and items should be performed even if modification dates indicate the items have not change[d]") and without `path` |
| sessions | `GET /status/sessions` (`Metadata[]` with `Session.id`, `User.title`, `Player` `title`/`product`/`address`/`remotePublicAddress`/`local`/`state`, `TranscodeSession` `transcodeHwRequested`/`transcodeHwFullPipeline`) |
| end-stream | `POST /status/sessions/terminate?sessionId=&reason=` (security `admin`; documented 401: "Server does not have the feature enabled", which the tool reports without marking the token) |
| recent | `GET /library/recentlyAdded` with `X-Plex-Container-Size` (in the community spec; the research file found it absent from the official 1.2.3 spec) |
| logs | none: Plex has no log-reading endpoint; the newest `*.log` in `<data dir>/Logs/` [UNVERIFIED: folder from the research file's reading of Plex's log article] |
| restart | `GET /identity` (no credential; restart loads none) until it answers |

Never sent (not in `PlexClient::ALLOW`): any DELETE (including `DELETE /library/metadata/{id}`, which removes media), `/updater/apply`, preference writes (such as the networks allowed without authentication), and anything on plex.tv.

## Restart

A container on the user's own rootless Docker: `docker restart <container>`, then the tool waits for `/identity`. Elsewhere it refuses with the provider's way [UNVERIFIED: from the research file's reading of provider docs]: Ultra.cc runs Plex in its own container; Feral restarts Plex through a control file in `~/private/plex/control-files/`; Whatbox from its Settings page. Plex has no restart endpoint in its API (research file), and `/updater/apply` is never called.

## Tests

`tests/MediaPlexTest.php` and `tests/MediaUnitsTest.php` run the unit against a fake Plex (`tests/fixtures/media/plex.php`) shaped on the community spec, with a fake `docker` and a socket the test owns.

## Unverified

- [UNVERIFIED-spec] Everything in "Plex calls": community spec only, no live server.
- [UNVERIFIED] `PlexOnlineToken` in `Preferences.xml`, the data-directory layout, and that the user can read the file in a rootless container.
- [UNVERIFIED] `videoDecision` / `audioDecision`: the tool calls a session `direct` only when a `TranscodeSession` says both are not `transcode`; without those fields a `TranscodeSession` counts as a transcode.
- [UNVERIFIED] `update_available` is not read (null).
