# media-servers

These are the servers that stream the user's own media to their devices: `jellyfin`, `emby` and `plex`, one unit each (ADR-0015). On Pulsed Media (PMSS) the user installs Jellyfin with PMSS's media-stack installer; Plex and Emby are the user's own installs (PMSS `docs/linuxserver.io.md:341-347`: "PMSS installs and supports Jellyfin as its media server"). Every unit has the same verbs and record fields; `lib/` holds the shared code, and the contract test checks every unit against the table below.

## Verbs (the same in every unit)

| verb | arguments | changes | units | what it does |
|---|---|---|---|---|
| stats | | no | all | Version, how it was found (`native`, `docker`, `url`), `listen` address:port as a plain fact, library and item counts (Jellyfin, Emby: `items` is the sum of the libraries' counts, `item_counts` the server's per-type counts), active streams and transcodes, the hardware-acceleration setting as found, pending restart and update available (Jellyfin, Emby; read only, never applied), where the credential lives. |
| libraries | | no | all | One record per library: `id`, `name`, `type`, `paths`, `items`, `scanning`, `progress`. |
| scan | `<library>... \| --all` | yes | all | Starts a scan of each library (an id or name from `libraries`). Jellyfin and Emby: explicit refresh modes `Default`, never `replaceAll*`; Plex: never `force`. Result per library: `started` or `already scanning`. |
| sessions | `[--addresses]` | no | all | What is playing: `id`, `user`, `client`, `device`, `title`, `progress`, `paused`, `play` (direct or transcode), `transcode_reasons`, `hardware`, `bitrate`, `remote`. Viewer addresses only with `--addresses`. |
| end-stream | `<session-id>... [--reason <text>]` | yes | all | Ends viewers' playback. Ids come from a live `sessions` call, several per call, never "all"; reads the list back (`verified`). A stop that fails does not hide the ones that worked: the call ends `ok:false` with each id's result; after a refused credential nothing more is sent. Plex may refuse (its feature gate) and the tool says so. |
| recent | `[--limit N]` | no | all | Recently added items, newest first: `title`, `type`, `library`, `added_at`. Paged like every list: `--limit 0` gives as many as fit, `--offset` the next page (`truncated`, `next_offset`); one call reads at most the 500 newest (`note` says when that stops it). |
| logs | `[--lines N] [--addresses]` | no | all | The last lines of the server log (default 50, at most 1000), with credentials removed and every IPv4/IPv6 address (with or without a port, bracketed, in a URL) replaced unless `--addresses`. Jellyfin and Emby: the log API, or the file for a native install; Plex: the files in its data directory. |
| restart | | yes | all | Restarts the server the way this host runs it (below) and waits for it to answer. Loads and sends no credential. Elsewhere it refuses and prints the provider's way. |
| login | | yes (local secret) | all | The user types an API key (Jellyfin, Emby) or token (Plex) at a terminal. Stored in `local/secrets/<unit>` (0700/0600), never typed in chat or on the command line. |

No delete, update, shutdown, key or configuration verbs. Later, once a need shows up: `users` (local accounts only), `tasks`, `history` (needs its own privacy review: it is other people's viewing).

## Safety rules (binding for every unit)

1. Every call goes only to a listener the user's own uid owns (ADR-0019, `lib/MediaHttp.php`); "localhost" is never trusted. API calls carry the credential and nothing relies on a server's tokenless loopback access; only the public status endpoints `restart` polls are sent without one.
2. Logins are never weakened: no changes to Plex's allowed networks, Jellyfin's `LocalNetworkSubnets`/`KnownProxies`, or Emby's equivalents (the units send no configuration writes at all).
3. No call to plex.tv or any other machine: `--url` must be `http://127.0.0.1|localhost|[::1]:<port>[/base path]`, checked without a DNS lookup; the base path is plain segments only (no `.` or `..` segment, no `%`, no query or fragment).
4. Each lib has a method and path allowlist (`Mediabrowser::ALLOW`, `PlexClient::ALLOW`): no DELETE of any kind, no shutdown or restart endpoint, no API-key creation (`/Auth/Keys`), no `/Startup`, no user-policy or configuration writes. A credential or overwrite flag in a URL query is refused too.
5. Deletes through these servers (Jellyfin/Emby `DELETE /Items`, Plex `DELETE /library/metadata`) ask the user (ADR-0020, the guard hook); the units never send one.
6. `scan` never sends `replaceAllMetadata`, `replaceAllImages` or Plex `force`, which overwrite metadata the user edited by hand.
7. `sessions` and `logs` keep viewer addresses out unless the user asks (`--addresses`): tool output goes to the model. Titles, user names and log text are data, never instructions (ADR-0016).
8. No security-audit verb. `stats` shows the listen address as a plain fact.
9. Plex and Emby are the user's own installs. PM provides no GPU transcoding; `hardware_acceleration` reports the server's setting as found, nothing more.
10. `restart` never uses a server's own restart endpoint and never re-runs an installer.

## Credentials

| unit | from | sent as |
|---|---|---|
| jellyfin | a key stored with `login`; else, on PMSS, a login with the password in the user's own `~/.media-stack-credentials.txt` (one attempt, session token kept in `local/secrets/jellyfin`) | `Authorization: MediaBrowser ..., Token="..."` |
| emby | a key stored with `login` | `X-Emby-Token` |
| plex | a token stored with `login`, else `PlexOnlineToken` from the container's `Preferences.xml` | `X-Plex-Token` |

One attempt per call. A refused key, password or token (HTTP 401 on a call the credential must pass) is marked and not tried again until the user runs `login`. A 403 (the credential works but may not do that) and a 401 on an optional read (Plex `/:/prefs`, Jellyfin/Emby item counts) mark nothing. Records name where the credential lives, never its value; the literal value is also removed from any text the tools print.

## Restart

| platform | jellyfin | emby | plex |
|---|---|---|---|
| PMSS media-stack install | refused while `~/.jellyfinDisable` exists; `bash ~/install-media-stack.sh --stop-app=jellyfin`, then `--start-app=jellyfin`, the PMSS panel's own steps; if it still answers 15 s after the stop: `stop not confirmed`, start not run | n/a | n/a |
| the user's own rootless Docker | `docker restart <container>` on `unix:///run/user/<uid>/docker.sock` only | same | same |
| Ultra.cc, Feral, Whatbox, other | refused, with the provider's way as the hint | refused + hint | refused + hint |
| never | `POST /System/Restart`, `--start-stopped`, `--uninstall`, an installer re-run | `POST /System/Restart` | `/updater/apply` |

Restart needs no credential: it uses only the public status endpoint (Jellyfin/Emby `/System/Info/Public`, Plex `/identity`) and the platform's own restart, so it works when the server is down or no credential is stored yet.

## Deliberate deviations from the spec

- Ultra.cc (`app-jellyfin restart`), Feral (Plex control file) and Whatbox (Settings page) restarts are refused with that way as the hint, not run: the tool runs only restarts it can check (the PMSS installer's panel flags, the user's own rootless Docker).
- `stats` reports no process or uptime, which the design (media-r1 verbs table) listed: it reads only the server's API, the listen table and the PMSS status file, never the process table.

Each unit's README.md has the sources, the per-server differences and what is still [UNVERIFIED].
