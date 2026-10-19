# bazarr

Controls the user's own Bazarr, the subtitle downloader for Sonarr and Radarr: list series and movies with their missing subtitles, inspect one, read the wanted list, history, health and stats, ask Bazarr to search for missing subtitles now, and restart a Docker copy. It talks to Bazarr's API on 127.0.0.1 as the user, with the key from Bazarr's own config.yaml, never as root, and deletes nothing. Bazarr is not a Servarr app: its API is Flask-RESTX, so the unit has its own verbs (`BazarrRun.php`, `BazarrVerbs.php`) and config reader (`BazarrConfig.php`), and shares the HTTP client, Docker, restart and output code in `tools/arr/lib/`.

## Sources

- Bazarr source `morpheus65535/bazarr` at commit `bfe4593b04caa16eb4a09c2a233777b0bd7723b6` (branch `master`, 2026-10-11): `bazarr/app/server.py`, `app/config.py`, `app/get_args.py`, `bazarr/api/**`, `frontend/src/apis/raw/base.ts`. Paths below are under `bazarr/` unless named otherwise.
- LinuxServer.io image `linuxserver/docker-bazarr` at commit `8a64a4385efeb65f13939c17d61372dbc54d3a12` (branch `master`, 2026-10-14).
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## Where Bazarr is found

The PMSS media-stack installer does not install or manage Bazarr (`etc/skel/install-media-stack.sh:258-263`); the PMSS documentation offers it only as an upstream LinuxServer.io recipe (`docs/linuxserver.io.md:511-526`, `:342-346`). First match wins:

1. Override: `'arr' => ['bazarr' => ['config' => '/path/config/config.yaml']]` in `pikkumies/local/config.php`.
2. A checkout in `~/bazarr`: `~/bazarr/data/config/config.yaml`. Bazarr's default config folder is `data` next to its checkout (`app/get_args.py:30-31`) and the file is `<config folder>/config/config.yaml` (`app/config.py:517-519`). [UNVERIFIED: that users put the checkout in `~/bazarr`; any other place needs the override.]
3. Docker: `~/docker/bazarr/config/config/config.yaml`: the PMSS recipe mounts `~/docker/bazarr/config` at `/config` and the image starts Bazarr with `--config /config` (`root/etc/s6-overlay/s6-rc.d/svc-bazarr/run:7`). The host port comes from `docker port bazarr <general.port>/tcp` against the user's own daemon.

## config.yaml

- Read line by line with regular expressions, never with a YAML library and never evaluated. Only a direct child of the top-level `general:` or `auth:` section counts: `general.port`, `general.base_url`, `auth.apikey` (validators `app/config.py:87-90,185`). Bazarr's file also has `sonarr.apikey`, `radarr.apikey`, `plex.apikey` and other keys of the same names, which are never taken. Quoted values are unquoted; a YAML tag, anchor, block or flow value counts as no value.
- Bazarr writes the file on every start (`write_config()` at module load, `app/config.py:648`); before its first start it can be empty (`:523-526`), which the tool reports as "empty: start Bazarr once".
- The key: Bazarr generates 32 hex characters (`app/config.py:185`); the tool takes 20 to 64 letters and digits, like the Servarr units.
- The tool connects to 127.0.0.1. Bazarr binds `general.ip` (default `*`) and the port from `--port` if it was started with one, else `general.port`, and falls back to 6767 when that port is taken (`app/server.py:36-37,54-69`): a copy started with `--port`, on another address, or moved to 6767 answers elsewhere, and the tool then says "not running".

## Bazarr calls

| verb | call | source |
|---|---|---|
| all | `http://127.0.0.1:<port><base_url>/api/...`, header `X-API-KEY`; Bazarr also accepts the key as `?apikey=` or a form field, which the tool never uses | `app/server.py:21`; `api/__init__.py:35-41`; `api/utils.py:100-115` |
| list, show | `GET series?start=0&length=-1[&seriesid[]=N]`, `GET movies?start=0&length=-1[&radarrid[]=N]`; answers `{data, total}`. The `monitored` and `missing` filters are left out: absent means all (`api/utils.py:35-49`) | `api/series/series.py:23-61`, `api/movies/movies.py:21-56` |
| stats | `GET system/status` (`bazarr_version`, `start_time`, `sonarr_version`, `radarr_version`), the totals of the four lists (`length=1`), `GET system/health` | `api/system/status.py:20-55` |
| health | `GET system/health`: `{object, issue}` | `api/system/health.py:12-20` |
| history | `GET episodes/history` and `GET movies/history` with `start=0&length=N`; `timestamp` is relative text and `parsed_timestamp` local-format text | `api/episodes/history.py:19-53`, `api/movies/history.py:20-52` |
| wanted | `GET episodes/wanted`, `GET movies/wanted` with `start=0&length=N` | `api/episodes/wanted.py:16-40`, `api/movies/wanted.py:17-37` |
| search | `PATCH series` with the form `seriesid=N&action=search-missing`, `PATCH movies` with `radarrid=N&action=search-missing`, the way Bazarr's own page sends them (a form body: `frontend/src/apis/raw/base.ts`). Bazarr 1.6.2 queues the search as a job and answers 204 at once (`subtitles/mass_download/movies.py:24-32`, `series.py:25-29` in the v1.6.2-ls368 image); the call still allows up to 60 s in all for the answers, and `searched` means accepted. `GET providers` first (`api/providers/providers.py`: each enabled provider, status `Good` or its throttle reason): with none usable nothing is sent, since the job would only log "All providers are throttled" (live 1.6.2, 2026-10-14) | `api/series/series.py:243-273`, `api/movies/movies.py:200-232` |

Never used: `POST system` (restart or shutdown, `api/system/system.py:11-30`), the other PATCH actions, and every DELETE.

## Login and settings

`login-check`, `set-login` and `config` (`BazarrLogin.php`, `BazarrSettings.php`; tools/arr/README.md "Login of the other apps"):

| verb | call | source |
|---|---|---|
| login-check, set-login, config | `GET system/settings`: every section of fields, read raw and never printed whole (it holds the key and the md5 of the login) | `api/system/settings.py:24-41`, `app/config.py:651-667` |
| set-login | `POST system/settings`, header `X-API-KEY`, form `settings-auth-type=form`, `settings-auth-username`, `settings-auth-password` (sent plain over loopback; Bazarr stores its md5) | `api/system/settings.py:43-130`, `app/config.py:690`, `:768-770`, `api/utils.py:100-115` |
| config set | `POST system/settings` with the one form field `settings-<section>-<field>` | the same |
| login-check, set-login | `POST system/account`, form `action=login`, `username`, `password`, no key: 204, 403 refused, 500 when auth.type is neither form nor basic | `api/system/account.py:15-50`, `utilities/helper.py:17-30` |

Bazarr turns a form value that is a whole number into a number unless its key is one of `str_keys` (`app/config.py:604, 726-731`), so `set-login` refuses a user name made of digits only, and `config set` reports a text it could not read back. `config set` never touches the sections `auth`, `cors`, `proxy` and `postgresql`, nor the fields listed in `BazarrSettings::NEVER` (addresses, ports, base URLs, keys, update and permission settings, the post-processing command, webhooks, certificate checks); lists are not set at all.

When the settings call gets no answer at all, one login with the new pair comes first: it works, `set-login` goes on; refused, the old line comes back; no answer either, the file keeps both (`BAZARR_PASSWORD` the new one, `BAZARR_PASSWORD_PREVIOUS` the old one). `config set` also never sets a path-valued field (`backup.folder`, `general.subfolder`, `general.subfolder_custom`, `general.path_mappings`, `general.path_mappings_movie`: `app/config.py:93, 117, 148-149, 195`).

### Why a Bazarr login fails

| cause | what it means | next |
|---|---|---|
| not-installed | No config.yaml was found. | The error's hint. |
| config-invalid | config.yaml is empty or not Bazarr's. | The error's hint. |
| not-answering | Bazarr does not answer its API (stopped, busy, another account's port). | The error's hint, usually `restart` |
| auth-not-configured | auth.type is neither form nor basic: the web page is open to anyone on this machine. | `set-login` |
| credentials-missing | `~/.media-stack-credentials.txt` has no `BAZARR_PASSWORD` line (or there is no file). | `set-login` |
| credentials-placeholder | The line holds a `[...]` placeholder, not a usable value. | `set-login` |
| password-rejected | Bazarr refused the user name with the one in the file. | `set-login` |

## Restart

Docker: `docker restart bazarr` against the user's own daemon, then `GET system/status` until `start_time` changes, up to 60 s. Anywhere else, PMSS included, the tool refuses with a hint: no PMSS installer manages Bazarr. Bazarr's own restart route is not used, for the same reason as `POST system/restart` on the Servarr apps: it is the provider's process, not the tool's.

## Tests

`tests/ArrBazarrTest.php` against `tests/fixtures/arr/bazarr-router.php` (`php -S`), helpers in `tests/fixtures/arr/phase2.php`. The login and settings verbs: `tests/ArrOtherLoginTest.php`.

## Unverified

- [UNVERIFIED] Nothing was run against a live Bazarr, `set-login` and `config set` included; their calls are read from the source at the commit above.
- [UNVERIFIED] The `parsed_timestamp` text is `%x %X` in Bazarr's locale; `event_at` is filled only when PHP's `strtotime` reads it, and is 0 otherwise.
- [UNVERIFIED] How Flask-RESTX's request parser treats a PATCH without a form body; the tool always sends the form, as Bazarr's page does.
