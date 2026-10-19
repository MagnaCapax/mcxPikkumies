# ADR-0046: Release handles and `grab` (find to add, never automatic)

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted

## Context
The operator's request of 2026-10-18, verbatim: "...if you consider it safe to provide torrent
search stuff because it's external 3rd party supplied then [design and plan] the tooling to use that,
all the way from finding to adding to favorite torrent client etc."

Prowlarr's `releases` (`tools/arr/prowlarr/`) already searches the user's own configured indexers
and prints titles; it never offered a way to add what it found. Sonarr and Radarr already `add` a
title and let the app search for it, but a user who wants one specific release of a title already in
the library (a particular quality, a particular group) had no path to it. Legal design rules from
the legal review of 2026-10-18, binding on this ADR: Pikkumies ships no source or indexer list ever; it
only searches the indexers the user configured in their own Prowlarr; the user picks the exact item
(the agent never auto-picks one); the add happens in the user's own software (Prowlarr's configured
download client, the user's own torrent client, or the *arr app's own configured download client);
search text is never stored; documentation examples use lawful content only (Linux ISOs, public-
domain media); no "ask for anything / watch anything" wording. The one-sentence test from that
review, stated plainly rather than attributed to a specific ruling (no case law was read this
session, so none is cited by name here): the user selects the sources (their own Prowlarr
indexers) and the exact item (one named handle), and the transfer runs in software the user already
runs (their torrent client, or the app's own download client) — Pikkumies only shows results and
relays one named choice; it never chooses a source, a release, or sends anything on its own.

Facts checked 2026-10-18 against the pinned commits in each unit's own README.md, and against
current GitHub source for the pieces those READMEs do not cover (Prowlarr's download proxy,
Sonarr/Radarr's exact-release endpoint — read over HTTPS from raw.githubusercontent.com, not
executed):
- Prowlarr's search answer (`Search/SearchController.cs` `MapReleases`) rewrites `downloadUrl` and
  `magnetUrl` into a proxy link to itself (`DownloadMappingService.ConvertToProxyLink`:
  `{serverUrl}{urlBase}/{indexerId}/download?apikey={ApiKey}&link={encryptedLink}&file=t|m`) and
  caches the underlying `ReleaseInfo` for exactly `TimeSpan.FromMinutes(30)`, keyed by
  `indexerId_guid`. A grab after that window is a cache miss.
- `GrabRelease` (`Search/SearchController.cs:55-87`) is `POST search {indexerId, guid}`: 404 "Couldn't
  find requested release in cache, try searching again" on a cache miss, 409 "Getting release from
  indexer failed" when `SendReportToClient` throws `ReleaseDownloadException` (the indexer fetch
  itself failed, not a download-client problem). On success Prowlarr hands the release to whichever
  download client it has configured — which, on a PMSS seedbox, is typically none: Sonarr/Radarr hold
  that configuration instead, matching the operator's "favorite torrent client" framing.
- `GET downloadclient` (`Prowlarr.Api.V1/DownloadClient`) lists Prowlarr's own configured clients,
  each with an `enable` flag (the same field name every other app in this codebase uses for an
  on/off setting, `ArrRecords::indexer`'s `enable`; Prowlarr's own `DownloadClientResource` was not
  read to confirm the name, so this is [UNVERIFIED] and noted in `prowlarr/README.md`).
- The proxy's own receiving endpoint (`Indexers/NewznabController.cs` `GetDownload`,
  `[HttpGet("/api/v1/indexer/{id:int}/download")]` and `[HttpGet("{id:int}/download")]`) decrypts
  `link` (`DownloadMappingService.ConvertToNormalLink`) and fetches it
  (`DownloadService.DownloadReport`). When the resulting bytes start with the ASCII sequence
  `magnet:` it answers `RedirectPermanent(magnetUrl)` — an HTTP 301 whose `Location` header is the
  literal magnet URI, not a second HTTP fetch target. Otherwise it answers the bytes directly, 200,
  `Content-Type: application/x-bittorrent` (torrent) or `application/x-nzb` (usenet, out of scope: no
  usenet client unit exists). This is the one behaviour the request asked to be confirmed and cited
  before writing the fetch code; it is cited here and at `pikkumies/tools/arr/lib/ArrGrab.php`.
- Sonarr's `GET /api/v3/release` (`Indexers/ReleaseController.cs:171-190` `GetReleases`) takes
  `episodeId` alone, or `seriesId` and `seasonNumber` together (either alone is a 400 "seriesId and
  seasonNumber must be provided together"); neither given falls back to the whole RSS feed, which
  this unit never asks for. Radarr's own `ReleaseController` takes `movieId` alone. Both cache the
  decision for the same `TimeSpan.FromMinutes(30)` and answer `DownloadRelease` (`POST release
  {guid, indexerId}`) with the same 404/409 shape as Prowlarr's `GrabRelease`, then send it to the
  app's own configured download client: no torrent-client code is involved on this path.
- `tools/torrent-clients/README.md` and its unit READMEs already document how each client is found
  ("Where each client is found") without a password or an API call (`RtorrentLocate::address`,
  `QbitEndpoint::locate`, `DelugeLocate::find`): the same probe each unit's own `restart` verb relies
  on to say "not running" versus "not installed". Reusing it for detection, as the request specified,
  needed no new probe.

## Options Considered
- **`GrabRelease` only (path A only).** Simplest, and exactly what Prowlarr's own UI does. Fails on
  the common PMSS shape, where Prowlarr has no download client configured at all (Sonarr/Radarr hold
  it) — the tool would always error there, which is most accounts.
- **In-process fetch-and-add only (path B only).** Works everywhere there is a torrent client, but
  ignores a user who set Prowlarr's own download client up on purpose (a Usenet client, a remote
  seedbox), and duplicates logic Prowlarr already has for exactly this.
- **Both, auto-chosen (chosen).** `--via-prowlarr` forces path A; otherwise path A runs only when
  Prowlarr already has an enabled download client (respecting that setup), else path B runs with
  `--client` or the one torrent client installed and running for this account, refusing with the
  choices when there are none or several. Prowlarr's own grab semantics are unchanged either way;
  Pikkumies only decides which path relays the user's one choice.
- **Auto-pick the first/best release.** Rejected outright by the legal design rules: the user always
  names the exact handle.

## Decision
- **Handles, never links, in output.** `pikkumies/tools/arr/lib/ArrReleases.php` stores each
  `releases` result's `guid`, `downloadUrl` and `magnetUrl` (a tracker passkey can be in any of
  them, and Prowlarr's own proxy link carries its apikey) behind a random 6-character lowercase hex
  handle, never derived from the guid or title, in `local/secrets/releases.json` (0600, atomic,
  `SecretFile`) — the same path the shell guard (`hooks/guard/guard-destructive`) already blocks
  commands on. `releases` prints the handle and nothing it protects. The set is replaced on every
  `releases` call (one search's results at a time) and an entry is read by `take()` (never mutates),
  removed by `forget()` only after a successful grab, and treated as gone once 30 minutes old — the
  same window the apps themselves use, so a handle never outlives what it names anyway.
- **Prowlarr: `releases "<text>"` (unchanged arguments) gains a `handle` column; new `grab <handle>
  [--client rtorrent|qbittorrent|deluge] [--via-prowlarr]`** (`pikkumies/tools/arr/lib/ArrGrab.php`).
  Path A (`--via-prowlarr`, or no `--client` and Prowlarr has an enabled download client): `POST
  search {indexerId, guid}`. Path B otherwise: the chosen client's `add()` gets the stored
  `magnetUrl` directly when it is a literal `magnet:` link, or the fetched `downloadUrl` — pinned to
  this account's own configured Prowlarr (scheme, host and port; the link carries its apikey) before
  any fetch, capped at 10 MiB, decoded through `Http::request` (no redirects followed: a 301 whose
  `Location` is `magnet:` is handed to the client as a magnet, matching `GetDownload` above), and
  required to parse as bencode with an `info` dict (`torrent-clients/lib/Bencode.php`) before being
  written to a 0600 temporary file under `pikkumies/local/tmp/` and handed to the client; the file is
  always removed afterwards (`finally`).
- **`ArrGrabClient.php`** picks the torrent client: `--client`, or the one of rtorrent/qbittorrent/
  deluge that is installed and running for this account (each unit's own Locate code, no login, no
  API call), or a `CliError` naming the choices. `$detect`/`$build` are test seams, so a test exercises
  the handle/pin/bencode/temp-file logic in `ArrGrab` against a fake `TorrentClient` without a real
  rTorrent, qBittorrent or Deluge, and without touching this install's own `local/secrets/qbittorrent`.
- **Sonarr and Radarr: new `releases <id> [--season N] [--episode <id>]` (Sonarr) / `releases <id>`
  (Radarr), and `grab <handle>`** (`pikkumies/tools/arr/lib/ArrLibraryReleases.php`), wired in only
  for these two apps (`Arr::build()`): `GET release` with the query shape above, `POST release
  {guid, indexerId}` for grab. No torrent client is ever touched on this path: the app's own
  configured download client does that. `ArrReleases::take()` refuses a handle whose stored app
  differs from the app running the verb (a Sonarr handle on `radarr grab` is refused, naming which
  app it belongs to), so the three `releases` surfaces (Prowlarr, Sonarr, Radarr) can never cross.
- **Lidarr, Readarr, Whisparr are out of scope.** Their API rows in `ArrAppsMore.php` were not
  checked against the same `GET/POST release` shape (the design limited this to sonarr+radarr when
  unsure); adding them later is a new ADR amendment once their controllers are read the same way.
- **Docs.** `prowlarr/skill.md` replaces its old "it only reads" line with: search, show the numbered
  results with handles, wait for the user to name one, then `grab`; never pick one, never grab more
  than named; prefer `sonarr`/`radarr releases <id>` for a title already in the library.
  `sonarr/skill.md` and `radarr/skill.md` get the matching step. All three units' `USAGE.md` and
  `README.md` gain the verb rows and call citations above; `tools/arr/README.md` gains a `grab` row
  on the Prowlarr table and a short "Sonarr and Radarr: exact-release search" note (not the shared
  first table, so lidarr/readarr/bazarr/autobrr/jackett/whisparr are unaffected by the contract test
  that enforces that table on every unit of the category).

## Consequences
- A release's guid, download link and magnet link never reach agent output, a ticket, a log line or
  this tool's own stdout/stderr at any point — only a 6-character handle does, and only for 30
  minutes.
- The agent still never picks a release: every `grab` names an exact handle the user chose from a
  `releases` printout; nothing here makes `releases` search anything the user did not ask for, and
  nothing adds more than the one handle named.
- Path B adds the only place in this codebase where an `arr/` unit's code constructs and calls a
  `tools/torrent-clients/` client directly, rather than following its pattern (as `autobrr/`'s own
  `AutobrrSecret.php` does for `QbitSecret.php`). This is a deliberate, narrow exception: the
  operator's own framing ("finding to adding to favorite torrent client") asked for that exact path,
  and duplicating a whole client's HTTP/XML-RPC/JSON-RPC implementation a second time inside `arr/`
  would be the opposite of DRY for no safety benefit.
- `local/secrets/releases.json` is a new file under the existing `local/secrets/` guard boundary; it
  adds no new secret class the guard does not already cover (the directory match is unconditional).
- A grab that chooses path B depends on `torrent-clients`' own install/running detection being
  correct; a client misreported as absent fails closed (the user is told to use `--client` or
  `--via-prowlarr`), never silently skipped.

## References
- [ADR-0015](0015-tool-units-one-skill-per-tool.md) (tool units), [ADR-0019](0019-hosts-and-credentials.md) (hosts and credentials, port-owner pinning, secrets never in a
  URL/log — except Prowlarr's own apikey in its own proxy link, which this ADR pins rather than
  forbids, the same exception ADR-0019's Jackett addendum already makes for a different reason),
  [ADR-0020](0020-deletes-need-the-users-approval.md) (deletes need approval — `grab` adds data, so it is not gated the same way, but the
  temp-file write/delete pattern follows the same "never leave a secret-bearing file behind" spirit).

## Amendment 2026-10-19: the agent picks the release ([ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md))
ADR-0073 ended questions about requested work: the agent decides from what it found, and only an irreversible loss or disclosure the request does not cover goes to the user. A grab adds a download (that a library upgrade can replace a file the skills already say), so waiting for the user to name a handle was a question about requested work.
- `prowlarr`, `sonarr` and `radarr` skills: when the user asked for a release grabbed, the agent picks one handle by what the user said (quality, size, language, group), then, among results the app did not reject, the most seeders, shows the numbered results with its pick and grabs it. It asks only when nothing the user said decides. It grabs one handle unless the user asked for more, and nothing when the user asked only to see the results.
- Unchanged: handles, the 30-minute window, the per-app pin and `grab` taking exactly one handle per call. `releases` still searches only what the user asked for, and the Consequences line "the agent still never picks a release" is replaced by this section.
