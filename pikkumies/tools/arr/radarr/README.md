# radarr

Controls the user's own Radarr (movies): list and inspect movies, look up and add new ones, remove them with or without their files, start a search, read the queue, wanted list, history, health and stats, and restart it the provider's way. It talks to Radarr's API on 127.0.0.1 as the user, with the key from Radarr's own config.xml, never as root. The shared rules are in `tools/arr/README.md`; the code is in `tools/arr/lib/`.

## Sources

- API shapes: Radarr's OpenAPI file `src/Radarr.Api.V3/openapi.json` at commit `a96bf7c3ecb23cb76993770727285dc17a613fbc` (branch `master`, 2026-09-13), the branch PMSS installs (`RADARR_BRANCH` master: PMSS `etc/skel/install-media-stack.sh:1215`). Source files below are from the same commit.
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## Where Radarr is found

1. Override: `'arr' => ['radarr' => ['config' => ...]]` in `pikkumies/local/config.php`.
2. PMSS: `~/.config/radarr/config.xml`, the installer's `--data` folder (`install-media-stack.sh:2564`), with `Port`, `UrlBase` `/public-<user>/radarr` and BindAddress 127.0.0.1 (`install-media-stack.sh:660-662`).
3. Radarr's default: `~/.config/Radarr/config.xml` (`NzbDrone.Common/EnvironmentInfo/AppFolderInfo.cs:26,36`). [UNVERIFIED: .NET's ApplicationData folder on Linux.]
4. Docker: `~/docker/radarr/config/config.xml` (PMSS `scripts/lib/user/dockerInstallLsio.php:179`; container `radarr`, `:229`; container port 7878 in PMSS's catalog, `:157`). The host port comes from `docker port radarr <Port>/tcp` against the user's own daemon socket (`etc/skel/.bashrc:284`).

config.xml validity, parsing and error order: as in `tools/arr/README.md` (installer test `install-media-stack.sh:642`; regex reading as `scripts/lib/mediaStackPorts.php:19`).

## Radarr calls

| verb | call | source |
|---|---|---|
| all | header `X-Api-Key`; base `http://127.0.0.1:<Port><UrlBase>/api/v3/` | spec `components.securitySchemes`; `install-media-stack.sh:1645-1651` |
| list, show | `GET movie?excludeLocalCovers=false`; `sizeOnDisk`, `hasFile`, `tmdbId`, `imdbId` | spec `MovieResource` |
| stats | `GET system/status`, `GET health`, `GET diskspace` | spec |
| history | `GET history?...&includeMovie=false`; `--since`: `GET history/since?date&includeMovie=false` | spec |
| lookup | `GET movie/lookup?term=`; Radarr resolves `imdb:`/`imdbid:` and `tmdb:`/`tmdbid:` itself | `NzbDrone.Core/MetadataSource/SkyHook/SkyHookProxy.cs:473,495` |
| add | the lookup object + `qualityProfileId`, `rootFolderPath`, `monitored: true`, `addOptions {monitor: movieOnly, searchForMovie, addMethod: manual, ignoreEpisodesWith(out)Files: false}`; `POST movie` | spec `AddMovieOptions`, `MonitorTypes` (`movieOnly`), `AddMovieMethod` (`manual`) |
| remove | `DELETE movie/{id}?deleteFiles=false\|true&addImportExclusion=false` | spec (both default false; note Sonarr's name is `addImportListExclusion`) |
| search | one `POST command {"name":"MoviesSearch","movieIds":[...]}` for the batch, then `GET command/{id}` | `IndexerSearch/MoviesSearchCommand.cs:8`; name rule `Messaging/Commands/Command.cs:42` |
| queue | `GET queue?page&pageSize&includeUnknownMovieItems=false&includeMovie=false` | spec `QueueResource` |
| wanted | `GET wanted/missing` or `wanted/cutoff` with `monitored=true` | spec `MovieResourcePagingResource` |
| delete check | `GET config/mediamanagement` (`recycleBin`) | spec |
| releases | `GET release?movieId=<id>` | `Indexers/ReleaseController.cs` `GetReleases`, read from the upstream source on 2026-10-18 |
| grab | `POST release {guid, indexerId}` (`DownloadRelease`; 404 "Couldn't find requested release in cache, try searching again", 409 "Getting release from indexer failed"); Radarr then sends it to its own configured download client | `Indexers/ReleaseController.cs` `DownloadRelease`; 30-minute cache (`TimeSpan.FromMinutes(30)`), the same TTL `ArrReleases` uses; docs/adr/0046 |

## Deleting data

With `deleteFiles=true` Radarr deletes the movie's whole folder, extras included, after the call returns: into `<recycleBin>/<folder name>` when a recycle bin is set, permanently otherwise (`MediaFiles/MediaFileDeletionService.cs:91-127`, `MediaFiles/RecycleBinProvider.cs:40-67`). When the folder equals or contains another movie's folder it logs an error and deletes nothing while the API call still succeeds (`MediaFileDeletionService.cs:106-116`); the tool refuses that case first and reports any folder still there afterwards as `refused`.

Queue removal is not offered: `removeFromClient` defaults to true and deletes the download's data (`Radarr.Api.V3/Queue/QueueController.cs:74,330`).

## Restart

As in `tools/arr/sonarr/README.md`: on PMSS the panel's own `--stop-app=radarr` / `--start-app=radarr` (`etc/skel/www/mediaStack.php:85-102`; `install-media-stack.sh:152-153,320-331`), refused while `~/.radarrDisable` exists; `docker restart radarr` for the user's own container; refused elsewhere. Radarr itself restarts inside the same process (`NzbDrone.Host/Bootstrap.cs:126-134`, `shouldRestart` loop), but the tool still uses the PMSS path, one way for every app.

## Polling, timeouts, tests

As in `tools/arr/sonarr/README.md`: 60 s wait bound below the agent's 180 s terminal timeout, 15 s requests (lookups 60 s), fake API in `tests/fixtures/arr/`, tests in `tests/Arr*Test.php`.

## Unverified

- [UNVERIFIED] Nothing was run against a live Radarr: JSON casing and exact error texts come from the spec and source only.
- [UNVERIFIED] A lookup by `imdb:` returns the movie with its `imdbId` set, which is how `add imdb:tt…` matches it; the metadata service's answer was not observed.
- [UNVERIFIED] .NET's ApplicationData folder on Linux, and how its recursive delete treats symlinks (the tool never follows them).
- [UNVERIFIED] `releases`' `quality` column reads `quality.quality.name`, as for Sonarr (`tools/arr/sonarr/README.md` Unverified); Radarr's own `ReleaseResource` was not read, only Sonarr's.
