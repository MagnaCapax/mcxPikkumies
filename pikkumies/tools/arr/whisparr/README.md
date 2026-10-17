# whisparr

Controls the user's own Whisparr: list and inspect what it holds, remove items with or without their files, start a search, read the queue, wanted items, history, health and stats, and restart a Docker copy. It talks to Whisparr's API on 127.0.0.1 as the user, with the key from Whisparr's own config.xml, never as root. The shared rules are in `tools/arr/README.md`; the code is the shared Servarr code in `tools/arr/lib/` (the two Whisparr rows are in `lib/ArrAppsMore.php`) plus `WhisparrRun.php`, which picks the row.

## Sources

- Upstream: `github.com/Whisparr/Whisparr`, read 2026-10-14. The default branch is `v2-develop`; the earlier "no API spec" note looked for a `develop` branch, which does not exist.
  - v2: branch `v2-develop` at `c55fdc66a410ad94f2d09261ed2fbb5e00dbc8c0` (release `v2.2.0-develop.404`, 2026-09-19). Its OpenAPI file lives on branch `api-docs` at `dff667ed86dbf6d29fa9bb93b2eaa5b3d790e78f`, `src/Whisparr.Api.V3/openapi.json`.
  - v3: branch `eros` at `cc3fb2abcf60f7c0048eb0294015d291b82bde08` (2026-08-28); releases are tagged `v3.1.0.<n>` (`v3.1.0.2116` = `94480761`). OpenAPI file: `src/Whisparr.Api.V3/openapi.json` at that commit. It lacks the wanted routes, which the controllers have (`Wanted/MissingController.cs:18,40`, `Wanted/CutoffController.cs:18,44`).
  - Source files below are from those two commits (v2 / v3).
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## Which Whisparr

Two different programs share the name:

| | v2 | v3 |
|---|---|---|
| based on | Sonarr: sites are series, scenes are episodes | Radarr: movies and scenes (`MovieResource.itemType`: movie or scene) |
| version | `2.2.0.<build>` (`.github/workflows/build.yml:22-24`) | `3.x` (release tags `v3.1.0.<n>`; the hotio `v3` image answered `3.6.5.1886`, branch `eros`) |
| API | `/api/v3`, header `X-Api-Key` (spec `securitySchemes`) | the same |
| items | `series`, search `SeriesSearch` (`IndexerSearch/SeriesSearchCommand.cs:7`, one per site) | `movie`, search `MoviesSearch` (`IndexerSearch/MoviesSearchCommand.cs:8`, one for all) |

Both keep config.xml in the same folder and answer the same paths for status and health, so the tool finds the app first, then reads `version` from `GET system/status` (`Whisparr.Api.V3/System/SystemController.cs:63`: the assembly version) when a verb runs. Major 2 uses the Sonarr-shaped row, major 3 the Radarr-shaped one. Anything else (a self-built copy reports `10.0.0.*`: `src/Directory.Build.props:77` v2, `:86` v3) is an error that changes nothing; the tool never guesses. `restart` does not need to know: when Whisparr does not answer it restarts with the shared fields.

## No lookup and no add

Both are left out on purpose; the user adds titles in Whisparr's own page.

- Lookup and add go to Whisparr's own metadata service, `api.whisparr.com/v3` for v2 (`MetadataSource/SkyHook/SkyHookProxy.cs:43`) and `/v4` for v3 (`SkyHookProxy.cs:48`). Whether it answers was not checked [UNVERIFIED], and a tool that adds through a service it cannot vouch for fails in ways the user cannot see.
- v2 adds a whole site (a studio), monitored: with Sonarr's `monitor: all` that is every scene the site ever released.
- v3 adds scenes and movies by StashDB, TPDB or TMDB id with an item type (`SkyHookProxy.cs:650-679,932-1016`; `MovieResource.stashId`, `foreignId`, `itemType`), and performers and studios as items of their own. The shared `add` models one id kind per app.

`meta_id` is still shown: `tpdb:<n>` for a v2 site (the TPDB id sits in `tvdbId`: `SkyHookProxy.cs:100-117` v2), `stash:<id>` or `tmdb:<n>` for v3.

## Where Whisparr is found

The PMSS media-stack installer does not install or manage Whisparr: its sessions are sonarr, radarr, prowlarr, sabnzbd, cloudplow and autobrr (`etc/skel/install-media-stack.sh:101`); Whisparr appears in PMSS only as a cookie path in the per-user nginx template (`etc/seedbox/config/template.nginx-user:32,63`). So there is no PMSS lowercase folder:

1. Override: `'arr' => ['whisparr' => ['config' => ...]]` in `pikkumies/local/config.php`.
2. Whisparr's default: `~/.config/Whisparr/config.xml` (`NzbDrone.Common/EnvironmentInfo/AppFolderInfo.cs:26,36` in both: ApplicationData plus "Whisparr"). [UNVERIFIED: that .NET maps ApplicationData to `~/.config` on Linux.]
3. Docker: `~/docker/whisparr/config/config.xml`, the same LinuxServer.io-style layout as the other units, with the host port from `docker port whisparr <Port>/tcp` against the user's own daemon. Whisparr's default port is 6969 (`NzbDrone.Core/Configuration/ConfigFileProvider.cs:206` v2, `:194` v3). [UNVERIFIED: which image a user runs and that it mounts the data folder at `/config`.]

## Whisparr calls

| verb | v3 call | v2 call |
|---|---|---|
| list, show | `GET movie?excludeLocalCovers=false` | `GET series?includeSeasonImages=false`; `statistics.episodeFileCount`, `episodeCount` |
| stats | `GET system/status`, `GET health`, `GET diskspace` | the same |
| history | `GET history?page&pageSize&sortKey=date&sortDirection=descending&includeMovie=false`; `--since`: `history/since` | `...&includeSeries=false&includeEpisode=false` |
| remove | `DELETE movie/{id}?deleteFiles=false\|true&addImportExclusion=false` (`MovieController.cs:418`) | `DELETE series/{id}?deleteFiles=false\|true&addImportListExclusion=false` (`SeriesController.cs:204`) |
| search | `POST command {"name":"MoviesSearch","movieIds":[...]}`, then `GET command/{id}` | `{"name":"SeriesSearch","seriesId":N}` per site |
| queue | `GET queue?...&includeUnknownMovieItems=false&includeMovie=false` | `...&includeUnknownSeriesItems=false&includeSeries=false&includeEpisode=false` |
| wanted | `GET wanted/missing` or `wanted/cutoff` with `monitored=true` (movie records) | with `includeSeries=false&includeImages=false&monitored=true` (`includeEpisodeFile=false` for cutoff); scenes: `id`, `seriesId`, `title`, `releaseDate` |

Every boolean is the spec's (v2) or the controller's (v3) with its default sent explicitly; the v3 queue and history flags are the spec's.

## Deleting data

With `deleteFiles=true` Whisparr deletes the item's whole folder through its recycle bin when one is set, and deletes nothing when that folder is the same as, or a parent of, another item's folder (`NzbDrone.Core/MediaFiles/MediaFileDeletionService.cs:91-121` v2, `:128-159` v3): the Sonarr and Radarr code, so the tool refuses those cases before calling, as for them. The rest is as in `tools/arr/README.md` "Deleting data". Queue removal is not offered (`removeFromClient` defaults to true there too).

## Restart

- Docker: `docker restart whisparr` against the user's own daemon, then `GET system/status` until `startTime` is newer, up to 60 s.
- Anywhere else, PMSS included, the tool refuses with a hint: no PMSS installer manages Whisparr, and `POST system/restart` is never used.

## Unverified

- Live run 2026-10-14, read only, against throwaway `ghcr.io/hotio/whisparr:v3` (answered `3.6.5.1886`) and `:v2` (`2.2.0.231`) containers on 127.0.0.1 with empty libraries: `stats`, `list`, `health`, `history` (and `--since`), `queue`, `wanted` (and `--cutoff`) answered with every boolean sent; the version picked the movie (v3) or series (v2) row; a wrong key gave the 401 error and a stopped app "not running", the key in no output. [UNVERIFIED] Library records, `search` and `remove` against real items: the libraries were empty (adding needs the metadata service), so those shapes come from the specs, source and fakes.
- [UNVERIFIED] v2 `releaseDate` is a `DateOnly`; the spec draws it as an object, and the tool reads it as the date text .NET writes. When it is not text, `released_at` is 0.
- [UNVERIFIED] Whether Whisparr's metadata service answers (why lookup and add are left out).
- [UNVERIFIED] .NET's ApplicationData folder on Linux, and the Docker image layout (discovery steps 2 and 3).
