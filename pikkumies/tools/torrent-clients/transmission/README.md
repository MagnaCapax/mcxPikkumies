# transmission

Controls the user's own Transmission (`transmission-daemon`): list, inspect, add, remove, pause and resume torrents, recheck and reannounce them, read stats and traffic, read and set the global speed limits, restart it on PMSS, repath a torrent onto data that is already in place, and export its saved `.torrent` file. It talks to Transmission's own JSON RPC over a unix socket (PMSS) or a TCP port (other stacks) through `lib/Http.php`, as the user, without root.

Sources below: PMSS development/PMSS at commit `c8ba1754` (2026-10-17), read-only -- `scripts/lib/user/transmissionSettings.php`, `etc/skel/www/transmissionLaunch.php`, `etc/skel/www/torrentClientsResolve.php`, `scripts/lib/torrentClients/identity.php`, `scripts/cron/transmissionInstancesCheck.php`, `scripts/lib/lighttpd/transmissionProxy.php`, `docs/adr/0108-transmission-socket-integration.md`. Transmission's own RPC spec, fetched 2026-10-20: the `main` branch's `docs/rpc-spec.md` (for the version-support notice only) and the `4.0.6` tag's `docs/rpc-spec.md` (for every method and field name this unit uses: `https://github.com/transmission/transmission/blob/4.0.6/docs/rpc-spec.md`).

## Why the pre-4.1 ("old") RPC protocol

Transmission 4.1.0 added a JSON-RPC 2.0 protocol with snake_case method and field names, and converted the whole wire format to it. The `main` branch's `docs/rpc-spec.md` says, verbatim, at the top: "The old bespoke RPC protocol, and the old mix of kebab-case and camelCase strings, are still supported in Transmission 4 but are deprecated and will be removed in the future." PMSS's curated catalog policy is "4.1.3 as default and 4.0.5 as legacy" (`docs/adr/0108-transmission-socket-integration.md`), and 4.0.5 predates 4.1.0 entirely -- only the old protocol works on both curated versions, so this unit sends the old `method`/`arguments`/`tag` shape with kebab method names (`torrent-get`, `session-set`, ...) and camelCase/kebab field names throughout, never the new snake_case one.

## Where Transmission is found

1. **The settings file.** `~/.config/transmission-daemon/settings.json`, one plain JSON object -- Transmission's own file, read the same way for every provider. Missing entirely means Transmission has never been started on this account.
2. **The endpoint.** `rpc-bind-address`: when it starts `unix:`, the rest is a unix socket path, checked with `Host::ownsSocket()` ([ADR-0019](../../../../docs/adr/0019-hosts-and-credentials.md)) before it is ever contacted; otherwise it is read as a TCP bind, and `rpc-port` (default 9091, Transmission's own default) is checked with `Host::ownsListener()` the same way qBittorrent and Deluge check their own ports. **PMSS always manages the unix-socket form**: `rpc-bind-address: "unix:/home/<user>/.lighttpd/transmission.sock"`, mode 0700 (`transmissionSettings.php:21-24`; `docs/adr/0108`).
3. **The RPC path.** `rpc-url` plus `rpc` (Transmission's own convention: the default URL is `http://host:9091/transmission/rpc`, i.e. the default `rpc-url` of `/transmission/` plus `rpc`). **PMSS sets `rpc-url` to `/user-<user>/transmission/`** (`transmissionSettings.php:24`), so the request path becomes `/user-<user>/transmission/rpc` even though this unit never goes through the lighttpd proxy that external URL also answers to (`transmissionProxy.php`) -- the unix socket connects straight to Transmission's own RPC server, which still matches requests by this same path.
4. **Authentication.** PMSS always disables it (`rpc-authentication-required: false`, `transmissionSettings.php:24`). If a settings file says `true` (a self-built daemon or another provider, never PMSS's own managed socket), this tool NEVER suggests turning it off -- a TCP endpoint with authentication off lets any co-tenant on a shared server drive the daemon as this account, and this RPC can run scripts. Instead, `login` (mirroring qBittorrent's) reads the RPC password from the terminal once and stores it in `pikkumies/local/secrets/transmission`; every call then sends it as HTTP Basic auth (`rpc-username` from settings.json, never secret, plus the stored password; rpc-spec.md section 2.3.3). A 401 means the stored password was rejected: the hint is always to run `login` again, never to disable authentication.
5. **Host whitelisting (DNS rebinding protection).** Transmission checks the `Host:` header against `rpc-host-whitelist` unless `rpc-host-whitelist-enabled` is off (PMSS turns it off, `transmissionSettings.php:24`) or the header is `localhost`/an IP, which are always allowed regardless (rpc-spec.md 2.2.2 on `main`, 2.3.2 on 4.0.6). This unit always sends `http://localhost<rpc-path>` for a unix-socket connection, which is always allowed either way.

**Not yet live anywhere.** As of this unit's writing, `docs/adr/0108-transmission-socket-integration.md` is "Status: Proposed", and its own Consequences section says: "No account receives a Transmission proxy or launch from this catalog today." Everything in this README and unit is verified against the PMSS source, not against a running PMSS account's Transmission (unlike qBittorrent's README.md, which cites a live 2026-10-14 smoke run). Treat the PMSS-specific behaviour here as source-verified, not live-verified, until that changes.

Errors name the cause: no settings.json yet, RPC disabled (`rpc-enabled: false`), no credential stored yet or a rejected one (hint: run `login`, never to disable authentication), no build selected (PMSS: no `~/bin/transmission/default` symlink), not installed (no `transmission-daemon` on PATH, elsewhere), not running (nothing listens on the socket/port), the socket or port is not this account's own (ADR-0019), or busy (no answer in time: state unknown, not down).

## Transmission RPC calls

Method and field names below are from `rpc-spec.md` on the `4.0.6` tag unless noted.

| verb | call | source |
|---|---|---|
| list | `torrent-get` with `fields`: `id`, `hashString`, `name`, `status`, `error`, `errorString`, `isStalled`, `percentDone`, `totalSize`, `sizeWhenDone`, `leftUntilDone`, `uploadRatio`, `rateDownload`, `rateUpload`, `addedDate`, `downloadDir`, `labels`, `doneDate`, `secondsSeeding`, `isPrivate`, `uploadedEver` | section 3.3 |
| list: id | `hashString`, lower-cased; Transmission's own integer `id` is explicitly "not stable across Transmission daemon restarts" (section 3.1), so this unit never uses it as the shared `id`/`native` | section 3.1, 3.3 |
| list: state | `status` 0..6 maps to the shared words (0 stopped -> paused, 1/3/5 queued-to-* -> queued, 2 verifying -> checking, 4 -> downloading, 6 -> seeding); overridden to `error` when `error` is nonzero, and to `stalled` when `isStalled` is true and `error` is 0 -- neither has its own status number, both are separate `tr_stat` fields | section 3.3 ("status", "error", "isStalled") |
| list: done | `sizeWhenDone - leftUntilDone`, floored at 0 (no single "bytes done of the wanted set" field exists) | section 3.3 |
| show (extra fields) | `torrent-get` with `peers`, `eta`, `uploadedEver`, `totalSize`, `file-count`, `trackerStats`; `seeds`/`peers` are counted from `peers[].progress` (>= 1.0 = a connected seed, the same split Deluge's `num_seeds`/`num_peers` draws); `swarm_seeds`/`swarm_peers` are the largest non-negative `seederCount`/`leecherCount` across `trackerStats` | section 3.3 |
| show --trackers | `trackerStats`: `announce`, `tier`, `seederCount`, `leecherCount`, `isBackup`, `hasAnnounced`, `lastAnnounceSucceeded`, `lastAnnounceResult`. `status` is approximated: `isBackup` -> `disabled`, no `hasAnnounced` -> `not contacted`, `lastAnnounceSucceeded` -> `working`, else `failing` -- [UNVERIFIED] this is not Transmission's own `announceState` enum (not in this fetch), only a best-effort translation to the shared status words | section 3.3 |
| add | `torrent-add` with `filename` (magnet or http(s) URL; Transmission fetches a URL itself) or `metainfo` (base64 .torrent), `paused`, `download-dir`; a new torrent answers `torrent-added`, an already-known one answers `torrent-duplicate` in the same shape, both with `result: "success"` | section 3.4 |
| remove | `torrent-remove` with `ids`, `delete-local-data`; no trash, so a data delete is always `deleted`, never `trashed` | section 3.5 |
| pause, resume | `torrent-stop`, `torrent-start` with `ids` (omitting `ids` would act on every torrent; this unit always sends ids) | section 3.1 |
| recheck | `torrent-verify` with `ids` | section 3.1 |
| reannounce | `torrent-reannounce` with `ids` ("ask tracker for more peers") | section 3.1 |
| repath | `torrent-set-location` with `ids`, `location`, `move: false` ("otherwise, search 'location' for files"), then `torrent-verify` | section 3.6, 3.1 |
| export | `torrent-get` with `torrentFile` (the on-disk path of Transmission's own saved copy; there is no RPC field that returns the raw bytes -- `metainfo` is an input of `torrent-add` only) | section 3.3, 3.4 |
| limit (read) | `session-get` with `speed-limit-down`, `speed-limit-up`, `speed-limit-down-enabled`, `speed-limit-up-enabled` (KBps; 0 reported when the matching `-enabled` flag is off) | section 4.1 |
| limit (set) | `session-set` with the same keys: a positive rate also turns its `-enabled` flag on, `0` turns it off (Transmission gates the limit with this flag rather than a sentinel value) | section 4.1, 4.1.1 |
| stats | `session-get` (`version`, `peer-port`, `rpc-version-semver`) | section 4.1.2, 4.1 |
| traffic | `session-stats` (`downloadSpeed`, `uploadSpeed`, `cumulative-stats.downloadedBytes`/`uploadedBytes`) | section 4.2 |

GET is never used: Transmission's RPC is POST-only, one endpoint, the method name decides read or write (section 2.3). Ids are always sent as the `hashString`s `list` reported, never Transmission's own numeric id and never `all`/omitted on a changing verb.

## CSRF handshake

Most Transmission RPC servers require an `X-Transmission-Session-Id` header; the first request (or one sent after the token expired) gets HTTP 409 back with the right id in the SAME response's own headers, and the correct handling is to resend the identical request once with that id (rpc-spec.md section 2.3.1 on 4.0.6, 2.2.1 on `main`). This unit does exactly that, once, never looping.

## Deleting data

`remove --with-data` runs the shared plan first (`lib/DeletePlan.php`: the exact `--expect` byte total and the shared-file check against the other torrents in this Transmission, because Transmission's own `torrent-remove` does not check other torrents for shared files any more than qBittorrent or Deluge do; torrents of another client are not seen). Transmission has no trash (no `MoveToTrash`-equivalent setting was found in the RPC spec), so a data delete always reports `disposal: deleted`. The tool then reads back that each torrent left the list and, for a delete, waits up to 5 s for the files to be gone (`lib/DeleteRun.php`): `freed_bytes` counts only files that are gone, files still there make it `partial`, and any error ends the call with `ok: false`.

## repath

`repath <id>... --to <dir>` is for the case where a torrent's data has already been moved or copied outside this tool (a migration, a storage reorganisation) and Transmission's own record needs to catch up -- it never moves bytes itself. Before touching anything it reads the torrent's current `files()` and checks that every one already exists at `<dir>` with the exact byte size; any missing or mismatched file refuses the whole call with no change. Only then does it call `torrent-set-location` with `move: false` (section 3.6: "otherwise, search 'location' for files") and `torrent-verify` (section 3.1) to hash-check the new location. A mismatch this check missed (same size, different bytes) is not caught by `repath` itself -- `torrent-verify` runs in the background and any corruption shows up afterwards as missing/incomplete data in `list`, the same way a `recheck` would reveal it.

## export

`export <id>` has no equivalent in the other three units' USAGE tables (it was added for this unit on request). Transmission keeps its own saved copy of every `.torrent` file it was given or that it reconstructed from a magnet link's metadata, and `torrentFile` (section 3.3) names that copy's path. There is no RPC field or method that returns the raw bytes directly -- `metainfo` only ever appears as an INPUT to `torrent-add` (section 3.4). `export` reads that file (checked to resolve inside `$HOME`, never a symlink escape) and base64-encodes it. A torrent added from a magnet link and still waiting for its metadata has no file yet, and `export` refuses rather than guess.

## Restart

**PMSS, only while a build is selected** (`is_link("$home/bin/transmission/default")`, [PMSS ADR-0108](https://github.com/MagnaCapax/PMSS/blob/main/docs/adr/0108-transmission-socket-integration.md)). Graceful stop of this user's own `transmission-daemon`: processes matched in `/proc` by owner uid, mount namespace and exact 15-byte-truncated `comm` ("transmission-da" -- PMSS reads it back the identical way, `etc/skel/www/transmissionLaunch.php:14-29`); SIGTERM, up to 3 s, SIGKILL only to survivors. Then the account's own shipped launcher: `php ~/www/torrentClientsResolve.php transmission --launch` -- the exact command PMSS's own watchdog cron runs for this account (`scripts/cron/transmissionInstancesCheck.php:20-22`), whose CLI tail calls `pmssTransmissionLaunch()` (`etc/skel/www/transmissionLaunch.php`): that function alone resolves the curated build, (re)writes the managed settings.json while stopped, and starts the daemon under its own `flock`. This unit never re-implements that catalog/projection resolution -- it only runs the same unprivileged entrypoint every PMSS account already has in `~/www/`.

**Other hosts**: refused ("restart is done by the provider here"; restart it the way that provider documents it) -- no Feral/Ultra.cc/Whatbox-specific Transmission restart instructions have been researched for this unit (unlike Deluge's and qBittorrent's units, which cite each provider's own documentation).

## Tests

`tests/Transmission*Test.php` run the unit against a fake RPC endpoint (`php -S`, mirroring the qBittorrent/Deluge test fixtures) with HOME in a temporary directory, `PIKKUMIES_PMSS_DIR` and `PIKKUMIES_TORRENT_TIMEOUT` as test seams the same way `deluge.php`/`qbittorrent.php` use them.

## Unverified

- [UNVERIFIED] `trackerStats`' `announceState` field's exact numeric enum: the `status` word mapping above (`isBackup`/`hasAnnounced`/`lastAnnounceSucceeded`) is a best-effort approximation, not a verified one-to-one translation, because `libtransmission`'s announcer header is not in this fetch.
- [UNVERIFIED] Whether `uploadRatio`'s two documented negative sentinels (not-applicable and infinite -- named informally, not with their exact numeric values, in the fetched spec) are `-1` and `-2`; this unit clamps any negative ratio to `0`, the same simplification Deluge's unit makes for its own `-1` sentinel.
- [UNVERIFIED] Whether `torrentFile` is populated for a torrent added as a `.torrent` file with `trash-original-torrent-files` enabled (Transmission's own session setting that deletes the SOURCE file after adding) -- this unit assumes Transmission always keeps its own internal copy regardless, since the field is documented as unconditional in the `torrent-get` table, but this was not observed on a live daemon.
- [UNVERIFIED] The entire PMSS integration (socket path, `rpc-url`, process name, the launcher command) is read from PMSS source only: `docs/adr/0108` itself says no live account has Transmission selected from the catalog yet, so none of this has been exercised against a running PMSS Transmission.
- [UNVERIFIED] Restart on providers other than PMSS: not researched; `restart` simply refuses there.
- [UNVERIFIED] That Transmission answers a rejected HTTP Basic auth credential with exactly 401 (rpc-spec.md section 2.3.3 documents the header it inspects, not the status code on failure): not exercised against a live daemon with `rpc-authentication-required: true`, so `login`/`TransmissionRpc`'s 401 handling is protocol-reasoned, not observed.
