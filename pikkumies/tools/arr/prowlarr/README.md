# prowlarr

Controls the user's own Prowlarr, the indexer manager: list and inspect indexers with their secrets hidden, search releases across them, test them, push them to Sonarr and Radarr, read history, health and stats, and restart it the provider's way. It talks to Prowlarr's API on 127.0.0.1 as the user, with the key from Prowlarr's own config.xml, never as root. Shared rules: `tools/arr/README.md`; code: `tools/arr/lib/`.

## Sources

- API shapes: Prowlarr's OpenAPI file `src/Prowlarr.Api.V1/openapi.json` at commit `12c327808314a7cae1b7301935ee10cabc19609f` (branch `master`, 2026-09-13), the branch PMSS installs (`PROWLARR_BRANCH` master: PMSS `etc/skel/install-media-stack.sh:1216`). Source files below are from the same commit.
- PMSS facts: the PMSS repository (commit `5b493872`), read only.

## Where Prowlarr is found

1. Override in `pikkumies/local/config.php`: `'arr' => ['prowlarr' => ['config' => ...]]`.
2. PMSS: `~/.config/prowlarr/config.xml` (`install-media-stack.sh:2565`; `scripts/lib/mediaStackPorts.php:24`).
3. Prowlarr's default: `~/.config/Prowlarr/config.xml` (`NzbDrone.Common/EnvironmentInfo/AppFolderInfo.cs:26,36`).
4. Docker: `~/docker/prowlarr/config/config.xml` (`scripts/lib/user/dockerInstallLsio.php:159,179,229`), host port from `docker port prowlarr <Port>/tcp` against the user's own daemon.

## Prowlarr calls

| verb | call | source |
|---|---|---|
| all | header `X-Api-Key`; base `http://127.0.0.1:<Port><UrlBase>/api/v1/` | spec `components.securitySchemes` |
| list, show | `GET indexer`, `GET indexer/{id}`; `fields` with `privacy` normal, password, apiKey or userName | spec `IndexerResource`, `Field`, `PrivacyLevel` |
| stats | `GET system/status`, `GET health` (Prowlarr has no `diskspace` route) | spec |
| history | `GET history?page&pageSize&sortKey=date&sortDirection=descending` (the optional `successful` filter is left out: absent means all); `--since`: `GET history/since?date` | spec `HistoryResource` |
| releases | `GET search?query&type=search&limit&offset=0[&indexerIds=N...]` | spec; `Prowlarr.Api.V1/Search/SearchController.cs:147-187` |
| test | one: `GET indexer/{id}`, then `POST indexer/test?forceTest=false` with that object (200, or 400 with the failures); all: `POST indexer/testall` (200, or 400 with a list when any fails; only enabled, valid indexers) | `Prowlarr.Api.V1/ProviderControllerBase.cs:207-238`, `ProviderTestAllResult.cs:7-11` |
| sync | `POST command {"name":"ApplicationIndexerSync","forceSync":false}`, then `GET command/{id}` | `NzbDrone.Core/Applications/ApplicationIndexerSyncCommand.cs:5-16`; `Messaging/Commands/Command.cs:41` |

## Secrets

- Indexer settings carry tracker passwords, API keys and cookies as `fields` name/value pairs. lib/Cli.php drops fields by key name, and a pair's keys are just `name` and `value`, so the shared lib drops every `value` whose `privacy` is not exactly `normal`, including fields with no privacy at all (`ArrRecords::scrub`).
- Releases: `downloadUrl` and `magnetUrl` are rewritten by Prowlarr into proxy links to itself (`SearchController.cs:198-199`) and are dropped, with `guid`. `infoUrl` stays; passkeys and tokens inside URLs are redacted by lib/Cli.php as everywhere.
- An unexpected failure inside a search is logged by Prowlarr and answered with an empty list (`SearchController.cs:181-186`), so `releases` says so when the answer is empty.

## Restart

As in `tools/arr/sonarr/README.md`: on PMSS the panel's own `--stop-app=prowlarr` / `--start-app=prowlarr` (`etc/skel/www/mediaStack.php:85-102`), refused while `~/.prowlarrDisable` exists; `docker restart prowlarr` for the user's own container; refused elsewhere. Prowlarr restarts in-process itself (`NzbDrone.Host/Bootstrap.cs:127-135`), but the tool uses the same PMSS path for every app, and on PMSS a restart counts only when the `prowlarr` tmux session runs.

## Polling, timeouts, tests

60 s wait bound for `sync` and `restart` (below the agent's 180 s terminal timeout); 15 s requests, 60 s for `releases` and `test`. Tests: `tests/Arr*Test.php` (`tests/ArrProwlarrTest.php` for its own verbs), fake API in `tests/fixtures/arr/` (`prowlarr-routes.php`).

## Unverified

- [UNVERIFIED] Nothing was run against a live Prowlarr.
- [UNVERIFIED] Several `--indexer` ids are sent as repeated `indexerIds=N` parameters, the usual ASP.NET binding for a list; not observed on a live server.
- [UNVERIFIED] Whether Prowlarr itself masks secret field values in `GET indexer`; the tool does not rely on it.
- [UNVERIFIED] The exact body of a single-indexer test failure (a list of validation failures is assumed, as the error pipeline writes them: Radarr `RadarrErrorPipeline.cs:46-52`; Prowlarr's own pipeline was not read).
