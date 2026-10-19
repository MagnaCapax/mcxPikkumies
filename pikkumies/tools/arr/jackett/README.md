# jackett

Controls the user's own Jackett, the indexer proxy that turns tracker sites into Torznab feeds for Sonarr, Radarr and the torrent clients: list and inspect the configured indexers, test them, show health and stats, and restart a Docker copy. It talks to Jackett on 127.0.0.1 as the user, with the API key from Jackett's own ServerConfig.json, never as root. Jackett has its own API, so its own verb code (`JackettRun.php`, `JackettIndexers.php`) and config reader (`JackettConfig.php`); the HTTP client, Docker, restart and output code is the shared code in `tools/arr/lib/`.

## Sources

- Upstream: `github.com/Jackett/Jackett`, branch `master` at `6fdd78f692617c7c7347ea0ee8b9c32c8742e4a4` (2026-10-14). Paths below are under `src/` at that commit.
- LinuxServer.io image: `github.com/linuxserver/docker-jackett` at `62646e3ba8d1a966d081d82368e72012c095b0c2`.
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## The key in the URL

Every other unit sends the key only in a header. Jackett accepts it nowhere else than the query string: its one key check reads the query parameters `apikey` or `passkey` and nothing more (`Jackett.Server/Controllers/ResultsController.cs:33-49`, the filter on every API-key route: `:163-166`). There is no header option. So this unit puts `apikey=<key>` in the URL, and only:

- to the user's own port on 127.0.0.1, after the same listener-owner check as every unit (`lib/Http.php`), through PHP curl: never on a command line, never in an environment variable, never in a file;
- on the two key routes it uses (`t=indexers` and the test search); `/health` needs no key and gets none;
- every text the tool prints passes the shared redaction first: the key is replaced in any text that came back (`ArrClient::redact`), URL queries in app texts are cut (`ArrErrorText::urls`), and `Cli::cleanString` removes `apikey=` values from any output. The tests check the literal key against stdout and stderr on a 401, on a 500 whose body echoes the request URL and the key, and on a dead port.

The admin API (indexer list with errors, server config, logs) is not used: it needs a login cookie (`Jackett.Server/Startup.cs:51-58,83-86`), which Jackett gives for the admin password (`Controllers/UIController.cs:69-77`, or without one when no password is set: `:39-42`). ServerConfig.json keeps only a hash of that password (`Jackett.Server/Services/SecurityService.cs:15-38`), and the tool does not ask for it.

## Where Jackett is found

The PMSS media-stack installer does not manage Jackett (`etc/skel/install-media-stack.sh:101`); PMSS offers it as a LinuxServer.io container (`etc/skel/www/appsLsioCatalog.php:780-785`; recipe `docs/linuxserver.io.md:528-541`: container `jackett`, `~/docker/jackett/config:/config`, port 9117). First match wins:

1. Override: `'arr' => ['jackett' => ['config' => '/path/ServerConfig.json']]` in `pikkumies/local/config.php`.
2. Jackett's default: `~/.config/Jackett/ServerConfig.json` (the data folder is ApplicationData plus "Jackett" on Linux, `Jackett.Common/Models/Config/RuntimeSettings.cs:29-47`; the file is `<type name>.json`, `Jackett.Common/Services/ConfigurationService.cs:161,182`). [UNVERIFIED: that .NET maps ApplicationData to `~/.config` on Linux.]
3. Docker: `~/docker/jackett/config/Jackett/ServerConfig.json`: the image sets `XDG_CONFIG_HOME=/config` (docker-jackett `Dockerfile:13-14`), so the data folder is `/config/Jackett`. The host port comes from `docker port jackett <Port>/tcp` against the user's own daemon.

From the file only `Port` (default 9117), `BasePathOverride` and `APIKey` are read (`Jackett.Common/Models/Config/ServerConfig.cs:17,35,39,45`), with `json_decode`, PHP's own parser; the proxy password and the other keys in the file are never read, and of the admin password hash `login-check` reads only whether it is set ("Login" below). Jackett generates a 32-character key on its first start (`ConfigurationService.cs:287-288`). The base path is normalized as Jackett does (`Jackett.Server/Services/ServerService.cs:64-74`, used by `Startup.cs:138-142`).

## Jackett calls

| verb | call | source |
|---|---|---|
| list, show, stats | `GET <base>/api/v2.0/indexers/all/results/torznab/api?t=indexers&configured=true&apikey=` on the `all` meta indexer: XML with each indexer's id, title, description, link, language, type and capabilities | `ResultsController.cs:344-376`; `Jackett.Common/Models/TorznabCapabilities.cs:288-345` |
| test | `GET <base>/api/v2.0/indexers/all/results?apikey=[&Tracker[]=id,id]`: an empty search on each named (or every configured) indexer; only the per-indexer part is read (`ID`, `Status` 2 = OK, `Results`, `Error`, `ElapsedTime`) | `ResultsController.cs:188-326`, `Tracker[]` `:200-203`; `Jackett.Common/Models/DTO/ManualSearchResult.cs:7-23` |
| health, stats, restart | `GET <base>/health`, no key: `{"status":"OK"}` | `Controllers/HealthcheckController.cs:8-21` |

A wrong key is answered differently per route: the Torznab route says HTTP 200 with error code 100 (`ResultsController.cs:45-47,525-535`), which the tool reports as the key rejected; the results route says 401, after which the tool reads ServerConfig.json once more and tries again, as every unit does.

The XML is read with regular expressions, never an XML library (no entity or external-reference surface), and each value is decoded as XML text. The test answer's `Results` part is dropped unread: its download links carry the API key (`ServerService.cs:60`, `jackett_apikey=`). The XML and the test results are text from trackers: data.

Jackett's own Test button runs the same empty search and calls zero results a failure (`Jackett.Common/Services/IndexerManagerService.cs:333-356`); `test` reports that case as `valid: false` with the reason. A recent identical search can be answered from Jackett's result cache (on by default, 2100 s: `ServerConfig.cs:20-22`).

## Verbs Jackett cannot fill

- `history`: Jackett keeps no history of searches or grabs; none of its controllers lists events (`Jackett.Server/Controllers/`: Blackhole, Download, Healthcheck, Image, IndexerApi, Results, ServerConfiguration, UI). Its admin-only `Cache` view holds recent results, not events (`IndexerApiController.cs:156-162`). The verb stays for the category contract and fails with that explanation.
- `stats`: `version` and `started_at` are `null`: Jackett shows its version only in the admin config (`Jackett.Common/Models/DTO/ServerConfig.cs:52`).
- `health`: Jackett has no health checks of its own; `/health` answers OK while it runs. A warning appears only when it answers something else. `test` is the check of the indexers.

No delete, add, remove or configuration verbs: v1 only reads, tests and restarts.

## Login

`login-check` only (`JackettLogin.php`; tools/arr/README.md "Login of the other apps"). There is no `set-login`: `POST api/v2.0/server/AdminPassword` (`Controllers/ServerConfigurationController.cs:50-64` at commit `cbf1fa3f`) sits under the filter that requires a signed-in user on every controller (`Startup.cs:83-86`), whose only scheme is the dashboard cookie (`Startup.cs:51-59`); the API key is checked only by the Torznab and results routes (`ResultsController.cs:33-49`). `login-check` calls `/health` without a key and reads two facts from ServerConfig.json, never a value: whether `AdminPassword` holds anything (with nothing, `CheckAuthorised` lets anyone in, `Services/SecurityService.cs:15-24`, and the login page signs them in, `UIController.cs:29-45`), and, outside Docker, whether `AllowExternal` is on (Jackett then listens on every address, `Jackett.Common/Models/Config/ServerConfig.cs:105-121`). Jackett keeps only a salted SHA-512 of the password (`SecurityService.cs:26-38`), so the tool can neither check nor recover it; the skill gives the user's own step.

### Why a Jackett login fails

| cause | what it means | next |
|---|---|---|
| not-installed | No ServerConfig.json was found. | The error's hint. |
| config-invalid | ServerConfig.json is empty or not Jackett's. | The error's hint. |
| not-answering | `/health` does not answer (stopped, busy, another account's port). | The error's hint, usually `restart` (Docker) |
| no-admin-password | No admin password is set: the dashboard opens for anyone on this machine. | The user sets one in the dashboard now |
| external-access | `AllowExternal` is on: Jackett listens on every address. | The user unticks External access in the dashboard and restarts it |
| password-unknown | An admin password is set; the tool cannot check it. | The user's own step (skill) |

## Restart

- Docker: `docker restart jackett` against the user's own daemon, then waits until `/health` answers and the container's start time (`docker inspect`) is newer, up to 60 s.
- Anywhere else, PMSS included, the tool refuses with a hint (no PMSS installer manages Jackett).

## Unverified

- Live run 2026-10-14, read only, against a throwaway `lscr.io/linuxserver/jackett` container (`v0.24.2830-ls3`) on 127.0.0.1 with no indexers configured: `stats`, `list`, `health`, `history`, `test`, `show`, `restart` (refused outside Docker) in JSON Lines and `--human`; the list parser read all 649 indexers of Jackett's full Torznab list (every entity decoded); a wrong key gave the code-100 error, a stopped Jackett "not running", the key in no output. No indexer was configured, so no tracker was contacted: `test` against real indexers is checked against the fake only.
- [UNVERIFIED] .NET's ApplicationData folder on Linux (discovery step 2).
- [UNVERIFIED] That ASP.NET reads the URL-encoded `Tracker%5B%5D` query name as `Tracker[]`, and that `Status` comes as a number (the tool also takes the text `OK`).
- [UNVERIFIED] `login-check` was run against the fake only; the routes and the settings it reads are taken from the source at commit `cbf1fa3f`.
