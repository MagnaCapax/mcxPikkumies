# lidarr

Controls the user's own Lidarr (music): list and inspect artists, look up and add new ones, remove them with or without their files, start a search, read the queue, wanted albums, history, health and stats, and restart a Docker copy. It talks to Lidarr's API on 127.0.0.1 as the user, with the key from Lidarr's own config.xml, never as root. The shared rules are in `tools/arr/README.md`; the code is in `tools/arr/lib/` (the Lidarr entry is in `lib/ArrAppsMore.php`).

## Sources

- API shapes: Lidarr's OpenAPI file `src/Lidarr.Api.V1/openapi.json` at commit `0cfa30b928f9c51edfb397b86013966f121c1c2a` (branch `develop`, 2026-10-12). PMSS's updater takes Lidarr release builds from the `develop` or `master` channel into `/opt/Lidarr` (`scripts/lib/update/apps/arr.php:17`). Source files below are from the same commit.
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## Where Lidarr is found

The PMSS media-stack installer does not install or manage Lidarr: its app list is jellyfin, sonarr, radarr, prowlarr, sabnzbd, autobrr and cloudplow (`etc/skel/install-media-stack.sh:258-263`). On PMSS a user runs the shared `/opt/Lidarr` build themselves (the `arrinfo` alias prints a Lidarr address, `etc/skel/.bashrc:121`) or a Docker container. So there is no PMSS lowercase folder for it:

1. Override: `'arr' => ['lidarr' => ['config' => ...]]` in `pikkumies/local/config.php`.
2. Lidarr's default: `~/.config/Lidarr/config.xml` (`NzbDrone.Common/EnvironmentInfo/AppFolderInfo.cs:26,36`: ApplicationData plus "Lidarr"). [UNVERIFIED: that .NET maps ApplicationData to `~/.config` on Linux.]
3. Docker: `~/docker/lidarr/config/config.xml`, the PMSS documentation's LinuxServer.io recipe (`docs/linuxserver.io.md:477-491`: container `lidarr`, `~/docker/lidarr/config:/config`, port 8686), which PMSS lists as an upstream recipe, not PMSS-tested (`:342-346`). The host port comes from `docker port lidarr <Port>/tcp` against the user's own daemon.

## Lidarr calls

| verb | call | source |
|---|---|---|
| all | header `X-Api-Key`; base `http://127.0.0.1:<Port><UrlBase>/api/v1/` | spec `components.securitySchemes` |
| list, show | `GET artist` (no boolean parameters); `artistName`, `foreignArtistId`, `statistics.sizeOnDisk`, `albumCount`, `trackFileCount`, `trackCount` | spec `ArtistResource`, `ArtistStatisticsResource` |
| stats | `GET system/status` (`version`, `startTime`), `GET health`, `GET diskspace` | spec |
| history | `GET history?page&pageSize&sortKey=date&sortDirection=descending&includeArtist=false&includeAlbum=false&includeTrack=false`; `--since`: `GET history/since?date&...` with the same three | spec |
| lookup | `GET artist/lookup?term=`; `mbid:`, `lidarr:` and `lidarrid:` with a MusicBrainz id are resolved by Lidarr itself, other text goes to its metadata service | `NzbDrone.Core/MetadataSource/SkyHook/SkyHookProxy.cs:191-251,433-436` |
| add | the lookup object + `qualityProfileId`, `metadataProfileId` (the root folder's `defaultMetadataProfileId`, else the only metadata profile), `rootFolderPath`, `monitored: true`, `addOptions {monitor: all, searchForMissingAlbums}`; `POST artist`. Lidarr requires a valid `metadataProfileId` | `Lidarr.Api.V1/Artist/ArtistController.cs:80,94`; spec `AddArtistOptions`, `RootFolderResource` |
| remove | `DELETE artist/{id}?deleteFiles=false\|true&addImportListExclusion=false` | `ArtistController.cs:195-198`; spec (both default false) |
| search | `POST command {"name":"ArtistSearch","artistId":N}` per artist, then `GET command/{id}` | `IndexerSearch/ArtistSearchCommand.cs:7`; name rule `Messaging/Commands/Command.cs:42` |
| queue | `GET queue?page&pageSize&includeUnknownArtistItems=false&includeArtist=false&includeAlbum=false` | spec `QueueResource` |
| wanted | `GET wanted/missing` or `wanted/cutoff` with `includeArtist=false&monitored=true` (albums: `id`, `artistId`, `title`, `releaseDate`) | spec `AlbumResource` |
| delete check | `GET config/mediamanagement` (`recycleBin`) | spec `MediaManagementConfigResource` |

## Deleting data

With `deleteFiles=true` Lidarr deletes the artist's whole folder after the call returns, through the recycle bin when one is set (`<recycleBin>/<folder name>`), permanently otherwise (`NzbDrone.Core/MediaFiles/MediaFileDeletionService.cs:107-144`, `MediaFiles/RecycleBinProvider.cs:40-67`). When the artist folder is the same as, or a parent of, another artist's folder it logs an error and deletes nothing (`MediaFileDeletionService.cs:123-133`); the tool refuses that case before calling. The rest is as in `tools/arr/README.md` "Deleting data".

Queue removal is not offered: `DELETE queue/{id}` defaults to `removeFromClient=true`, which calls the download client's `RemoveItem(item, true)` and deletes the data (`Lidarr.Api.V1/Queue/QueueController.cs:67,277-288`).

## Restart

- Docker: `docker restart lidarr` against the user's own daemon, then `GET system/status` until `startTime` is newer, up to 60 s.
- Anywhere else, PMSS included, the tool refuses with a hint: no PMSS installer manages Lidarr, and `POST system/restart` is never used (a Servarr restart spawns a new process, as the sonarr README explains).

## Unverified

- [UNVERIFIED] Nothing was run against a live Lidarr: JSON casing and error texts come from the spec and source only.
- [UNVERIFIED] That every Lidarr root folder carries a default metadata profile; without one, and with several metadata profiles, `add` refuses and says why.
- [UNVERIFIED] .NET's ApplicationData folder on Linux (discovery step 2).
- [UNVERIFIED] How a PMSS user starts the shared `/opt/Lidarr` build (data folder, port): no PMSS file automates it, so the override in `local/config.php` is the way to point the tool at a copy in another folder.
