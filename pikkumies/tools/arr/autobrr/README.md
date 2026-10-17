# autobrr

Controls the user's own autobrr, the release automation that watches tracker IRC announce channels and feeds: list and inspect filters, turn filters on or off, read recent releases and what autobrr did with them, IRC and database health and stats, and restart it the provider's way. It talks to autobrr's API on 127.0.0.1 as the user, never as root. autobrr is not a Servarr app and keeps its API keys in its own database, so the unit has its own verbs (`AutobrrRun.php`, `AutobrrVerbs.php`), config reader (`AutobrrConfig.php`) and key store (`AutobrrSecret.php`), and shares the HTTP client, Docker, restart and output code in `tools/arr/lib/`.

## Sources

- autobrr source `autobrr/autobrr` at commit `32210b47a2f4246db8c4e01ac6e016a997ddaf0f` (branch `develop`, 2026-10-13): `internal/http/*.go`, `internal/domain/*.go`, `internal/config/config.go`, `internal/api/service.go`, `Dockerfile`. PMSS installs autobrr's latest GitHub release (`etc/skel/install-media-stack.sh:471-494`), so a user's copy can be older than this commit.
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## The API key (ADR-0019)

- PMSS's installer creates only a login user for autobrr (`autobrrctl create-user`, `etc/skel/install-media-stack.sh:1790`) and no API key. autobrr makes a key in its web page (Settings, API keys) or through `/api/keys` (`internal/http/server.go:240`); a key is 16 random bytes in hex, 32 characters (`internal/api/service.go:53-54,104-110`). The tool never creates one: making a new admin key needs the user's own setup step.
- `login` reads the key at a terminal prompt without echo (never in the chat, never on a command line), checks it with `GET config`, and stores it in `pikkumies/local/secrets/autobrr` (directory 0700, file 0600, temporary file and rename), the pattern of `torrent-clients/qbittorrent/QbitSecret.php` and `backup/lib/BackupStore.php`. A wrong key stores nothing; when autobrr does not answer, the key is stored unchecked and the record says so. autobrr answers a wrong key with 401 and nothing more (`internal/http/middleware.go:71-85`), so no "rejected" mark is kept as for qBittorrent.
- The key goes only in the `X-API-Token` header. autobrr also takes `?apikey=` (`middleware.go:80-85`), which the tool never uses: a URL ends up in logs.

## Where autobrr is found

First match wins:

1. Override: `'arr' => ['autobrr' => ['config' => '/path/config.toml']]` in `pikkumies/local/config.php`.
2. `~/.config/autobrr/config.toml`: on PMSS the installer's folder (`install-media-stack.sh:1986-1988` starts it with `--config=$HOME/.config/autobrr`; panel app `etc/skel/www/userMediaStackPanel.php:288-294`), elsewhere autobrr's own default search path (`internal/config/config.go:505-509`).
3. `~/.autobrr/config.toml` (`config.go:509`).
4. Docker: `~/docker/autobrr/config/config.toml` (the image runs `autobrr --config /config`, `Dockerfile:46-61`), host port from `docker port autobrr <port>/tcp` against the user's own daemon. [UNVERIFIED: the container name `autobrr` and that folder; PMSS documents no autobrr container.]

config.toml is read with regular expressions, never a TOML library and never evaluated: only top-level `port`, `baseUrl` and `baseUrlModeLegacy` before the first `[table]`. Defaults when absent: port 7474, baseUrl `/`, legacy mode on (`config.go:262-270`). The API is at `<baseUrl>api`, or at `/api` in the legacy mode (`internal/http/server.go:263-302`; autobrr does not add a missing slash, so the tool does not either). PMSS writes `port`, `baseUrl = "/public-<user>/autobrr/"` and `baseUrlModeLegacy = false` (`install-media-stack.sh:104,496-525`) and passes the same values as `AUTOBRR__*` variables at start (`:1986-1988`); root reads the same `port` line (`scripts/lib/mediaStackPorts.php:18,26`). Those variables win over config.toml (`config.go:254-255,308-314`: load, then loadFromEnv), and `baseUrl` is only appended when missing (`:518-523`), so on PMSS the tool uses `/public-<user>/autobrr/api/` whatever config.toml says; `stats` prints `url_base_from` and, when config.toml disagrees, `url_base_note`.

## autobrr calls

| verb | call | source |
|---|---|---|
| list | `GET filters` | `internal/http/filter.go:46-47`; `internal/domain/filter.go:108-195` |
| show | `GET filters/{id}` | `filter.go:53-54` |
| toggle | `PUT filters/{id}/enabled` `{"enabled": true\|false}` (204), then `GET filters/{id}` | `filter.go:60,268-295` |
| stats | `GET config` (`version`), `GET filters`, `GET release/stats`, plus `health` | `internal/http/config.go:19-34`, `release.go:56-59`, `domain/release.go:393-400` |
| health | `GET healthz/readiness` (OK, or 500 when the database is unreachable; no key needed), `GET irc` (`connected`, `healthy`, `connection_errors`) | `internal/http/health.go:28-56`, `server.go:226`; `irc.go:49-50`, `domain/irc.go:204-228` |
| history | `GET release?limit=N&offset=0` (`{data, count, next_cursor}`) | `release.go:106-154`, `domain/release.go:42-121,625-629` |
| restart | liveness: `GET healthz/liveness` | `health.go:28-35` |

Never used: any DELETE, any POST, `GET irc/network/{id}/restart` (a GET that restarts an IRC connection, `irc.go:60`), `PATCH config`, `/api/keys`, updates.

## Secrets in answers

Filters, indexers, IRC networks and releases carry tracker settings, IRC passwords and SASL logins, proxy logins, action commands, webhook addresses and download links (`domain/indexer.go:37`, `domain/irc.go:212-215`, `domain/action.go:12-24`, `domain/release.go:55`). autobrr itself masks some of them (secret indexer settings and the IRC `pass`: `domain/indexer.go:60-80`, `domain/irc.go:124-133`), but the tool does not rely on that: every record is built from a list of allowed fields (`AutobrrVerbs.php`), so none of those fields can reach the output, and lib/Cli.php still drops secret-named fields and redacts keys in URLs.

## Restart

- PMSS: refused while `~/.autobrrDisable` exists (the panel's Stop marker; its restart refuses the same way, `etc/skel/www/mediaStack.php:80-89`); otherwise `/bin/bash ~/install-media-stack.sh --stop-app=autobrr`, then `--start-app=autobrr`, exactly the panel's restart (`mediaStack.php:97-98`; autobrr is one of the installer's apps, `install-media-stack.sh:258-263`). The installer must be this uid's own file.
- autobrr reports no start time, so on PMSS the restart counts when it answers `healthz/liveness` from a new listening socket (a new inode in `/proc/net/tcp`) and its tmux session `autobrr` runs (the watchdog's test, as for the other apps). In Docker it counts when the container's start time (`docker inspect`) is newer.
- Elsewhere: refused with a hint.

## Tests

`tests/ArrAutobrrTest.php` against `tests/fixtures/arr/autobrr-router.php` (`php -S`), run through `tests/fixtures/arr/autobrr-wrapper.php`, which sets the two test seams (`$autobrrSecretDir`, `$autobrrPrompt`). The restart test's fake installer really stops the fake server and starts a new one on the same port.

## Unverified

- [UNVERIFIED] Nothing was run against a live autobrr; field names come from the Go struct tags at the commit above.
- [UNVERIFIED] Docker discovery (step 4 above).
