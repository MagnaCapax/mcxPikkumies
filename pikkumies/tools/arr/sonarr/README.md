# sonarr

Controls the user's own Sonarr (TV series): list and inspect series, look up and add new ones, remove them with or without their files, start a search, read the queue, wanted list, history, health and stats, and restart it the provider's way. It talks to Sonarr's API on 127.0.0.1 as the user, with the key from Sonarr's own config.xml, never as root. The shared rules are in `tools/arr/README.md`; the code is in `tools/arr/lib/`.

## Sources

- API shapes: Sonarr's OpenAPI file `src/Sonarr.Api.V3/openapi.json` at commit `bc54afbdd0e38bdb0fca4785fcf7d7a6af77bf9a` (branch `main`, 2026-10-12), the branch PMSS installs (`SONARR_BRANCH` main, `SONARR_MAJOR` 4: PMSS `etc/skel/install-media-stack.sh:1214,1218`). Source files below are from the same commit.
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## Where Sonarr is found

1. Override: `'arr' => ['sonarr' => ['config' => ...]]` in `pikkumies/local/config.php`.
2. PMSS: `~/.config/sonarr/config.xml`, the installer's `--data` folder (`install-media-stack.sh:2563`). The installer writes `Port`, `UrlBase` `/public-<user>/sonarr` and BindAddress 127.0.0.1 there (`install-media-stack.sh:660-662`).
3. Sonarr's default: `~/.config/Sonarr/config.xml` (`NzbDrone.Common/EnvironmentInfo/AppFolderInfo.cs:27,37`: ApplicationData plus "Sonarr"). [UNVERIFIED: that .NET maps ApplicationData to `~/.config` on Linux; the runtime source was not read.]
4. Docker: `~/docker/sonarr/config/config.xml` (PMSS `scripts/lib/user/dockerInstallLsio.php:179`, container name `sonarr`, `:229`; container port 8989, `:158`). The host port comes from `docker port sonarr <Port>/tcp` (the container listens on the Port in its config.xml), run against `unix:///run/user/<uid>/docker.sock` (the address PMSS exports, `etc/skel/.bashrc:284`) or `DOCKER_HOST`, and only when that socket is owned by this user.

config.xml is valid when non-empty and containing `</Config>`, the installer's own test (`install-media-stack.sh:642`); the installer keeps a bad non-empty file as `config.xml.invalid-<time>` before writing a new one (`:643-652`). It is read with regular expressions like PMSS does (`scripts/lib/mediaStackPorts.php:19`), never with an XML parser.

## Sonarr calls

| verb | call | source |
|---|---|---|
| all | header `X-Api-Key`; base `http://127.0.0.1:<Port><UrlBase>/api/v3/`; without the UrlBase the app answers an empty 307 | spec `components.securitySchemes`; `install-media-stack.sh:1645-1651` |
| list, show | `GET series?includeSeasonImages=false`; `statistics.sizeOnDisk`, `episodeFileCount`, `episodeCount` | spec `SeriesResource`, `SeriesStatisticsResource` |
| stats | `GET system/status` (`version`, `startTime`), `GET health`, `GET diskspace` (`freeSpace`) | spec `SystemResource`, `HealthResource`, `DiskSpaceResource` |
| history | `GET history?page&pageSize&sortKey=date&sortDirection=descending&includeSeries=false&includeEpisode=false`; `--since`: `GET history/since?date&includeSeries=false&includeEpisode=false` | spec |
| lookup | `GET series/lookup?term=`; `tvdb:N` is resolved by Sonarr itself, other text goes to its metadata service | `NzbDrone.Core/MetadataSource/SkyHook/SkyHookProxy.cs:107-145` |
| add | the lookup object + `qualityProfileId`, `rootFolderPath`, `monitored: true`, `seasonFolder: true`, `addOptions {monitor: all, searchForMissingEpisodes, searchForCutoffUnmetEpisodes: false, ignoreEpisodesWith(out)Files: false}`; `POST series` | spec `AddSeriesOptions`, `MonitorTypes` |
| remove | `DELETE series/{id}?deleteFiles=false\|true&addImportListExclusion=false` | spec (both default false) |
| search | `POST command {"name":"SeriesSearch","seriesId":N}` per series, then `GET command/{id}` until completed/failed/aborted/cancelled/orphaned | `IndexerSearch/SeriesSearchCommand.cs:7`; name rule `Messaging/Commands/Command.cs:41`; spec `CommandStatus` |
| queue | `GET queue?page&pageSize&includeUnknownSeriesItems=false&includeSeries=false&includeEpisode=false` | spec `QueueResource` |
| wanted | `GET wanted/missing` or `wanted/cutoff` with `includeSeries=false&includeImages=false&monitored=true` (cutoff also `includeEpisodeFile=false`) | spec `EpisodeResource` |
| delete check | `GET config/mediamanagement` (`recycleBin`) | spec `MediaManagementConfigResource` |

## Deleting data

With `deleteFiles=true` Sonarr deletes the series' whole folder after the call returns (`HandleAsync`), through `RecycleBinProvider.DeleteFolder`: into `<recycleBin>/<folder name>` when a recycle bin is set, permanently otherwise (`MediaFiles/MediaFileDeletionService.cs:91-127`, `MediaFiles/RecycleBinProvider.cs:40-67`). When the series folder is the same as, or a parent of, another series' folder it logs an error and deletes nothing (`MediaFileDeletionService.cs:106-116`); the tool refuses that case before calling. Afterwards it waits up to 60 s for each folder to go and reports `deleted`, `recycled` or `refused`. The DELETEs go one at a time; the first that fails stops the call (`failed`, the rest `not_sent`) and it ends `ok:false`, naming every item.

Queue removal is not offered: `DELETE queue/{id}` defaults to `removeFromClient=true`, and that calls the download client's `RemoveItem(item, true)`, which deletes the data (`Sonarr.Api.V3/Queue/QueueController.cs:74,342`).

## Restart

- PMSS: refused while `~/.sonarrDisable` exists (the panel's Stop touches it and its restart refuses the same way, `etc/skel/www/mediaStack.php:85-89`); otherwise `/bin/bash ~/install-media-stack.sh --stop-app=sonarr`, then `--start-app=sonarr`, from `$HOME`, exactly the panel's restart (`mediaStack.php:97-102`; flags `install-media-stack.sh:152-153,171-178`; `--stop-app` kills only the `sonarr` tmux session, `--start-app` runs the installer's alias, `:320-331,276-318`). With these flags the installer never self-updates (`:40-52`). The tool never runs the installer without them, and never one that is not this user's own file. The restart counts only when the app answers with a newer start time and its tmux session runs: the installer names the session after the app (`:101`, `:300`, `:1949`), the watchdog tests it with `tmux has-session -t =sonarr` (`scripts/lib/mediaStackWatchdog.php:64`) and the panel lists sessions by name (`etc/skel/www/scriptsInc.php:1211-1214`); an app that answers outside it is an error.
- Never `POST system/restart` on PMSS: Sonarr v4 restarts by spawning a new process and letting the old one exit (`NzbDrone.Host/AppLifetime.cs:70-78`), which ends the tmux session the PMSS watchdog checks.
- Docker: `docker restart sonarr` against the user's own daemon. Elsewhere: refused with a hint.
- Then `GET system/status` until `startTime` is newer (PMSS: and the tmux session runs), up to 60 s.

## Polling and timeouts

Commands, deletes and restarts wait at most 60 s (`ArrCommand::WAIT`), well below the agent's terminal timeout of 180 s in the pinned Hermes release (`tools/terminal_tool.py:709`, `hermes_cli/config_defaults.py:277,294` in v2026.9.24). A command still running then is reported `ok:true` with its `state` and `command_id`. Requests time out after 15 s (lookups 60 s); a timeout is "busy (unknown)", never "down".

## Tests

`tests/Arr*Test.php` run the unit against a fake API (`tests/fixtures/arr/router.php` under `php -S`) whose shapes follow the spec above, with HOME in a temporary directory. Test seams: `PIKKUMIES_PMSS_DIR`, `PIKKUMIES_ARR_TIMEOUT`, `PIKKUMIES_ARR_WAIT`, and `ArrLocate::find`'s `$tables` (the /proc/net contents for the port-owner check).

## Unverified

- [UNVERIFIED] Nothing was run against a live Sonarr: JSON casing and the exact error texts come from the spec and source only.
- [UNVERIFIED] `seasonFolder: true` is assumed to match the add form's default; the UI source was not read.
- [UNVERIFIED] The Docker host port is owned by this user's uid under rootless Docker; if it is not, lib/Http.php refuses the port, which fails safe.
- [UNVERIFIED] `.NET`'s ApplicationData folder on Linux (step 3 above).
- [UNVERIFIED] .NET's recursive folder delete and symlinks inside a series folder: the tool lists symlinks with 0 bytes and never follows them.
