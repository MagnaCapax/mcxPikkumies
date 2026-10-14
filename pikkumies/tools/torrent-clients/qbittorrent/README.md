# qbittorrent

Controls the user's own qBittorrent (qbittorrent-nox): list, inspect, add, remove, pause and resume torrents, recheck and reannounce them, read stats and traffic, read and set the global speed limits, and restart it on PMSS and Ultra.cc. It talks to the WebUI API v2 on 127.0.0.1 through `lib/Http.php`, as the user, without root. It never changes qBittorrent's login settings (no localhost bypass, no subnet whitelist, no new credentials).

Sources below: `PMSS:` = the PMSS clone at commit `5b493872` (2026-10-08), read-only; qBittorrent source and wiki copies fetched 2026-10-14 (`tc-5.0.0.cpp` = release-5.0.0 `torrentscontroller.cpp`, `webapp-5.0.0.cpp` = `webapplication.cpp`, `appc.cpp` = `appcontroller.cpp`, `tcr.cpp` = `torrentcontentremover.cpp`, `qbt-wiki-5.md` = the WebUI API (qBittorrent 5.0) wiki page; `-5.1.4` / `-5.2.4` = the same files at the release-5.1.4 and release-5.2.4 tags, plus `serialize_torrent.cpp/.h`, fetched 2026-10-14); research notes `torrent-clients.md` (2026-10-14). `live:` = a smoke run on 2026-10-14 against qBittorrent 5.2.4 (API 2.15.1) and 5.1.4 (API 2.11.4) in throwaway local containers.

## Where qBittorrent is found

1. **Host.** PMSS when `/etc/seedbox/config` exists, else the provider from the hostname, else generic (`lib/Host.php`).
2. **Port.** PMSS: `~/.qbittorrentPort` (written by PMSS `scripts/util/userConfig.php:214`, kept equal to the config by `scripts/lib/user/torrentPort.php:105-134`). Everywhere, and as the PMSS fallback: `WebUI\Port` in the `[Preferences]` section of `~/.config/qBittorrent/qBittorrent.conf` (PMSS `etc/seedbox/config/template.qbittorrent.conf:59`; the file path is PMSS `scripts/lib/user/qbittorrent.php:15-18`). PMSS binds the WebUI to 127.0.0.1 (`template.qbittorrent.conf:50`).
3. **Owner check.** The port must be this user's listener in `/proc/net/tcp(6)`; another account's port is never contacted and no login is sent there (ADR-0019, `lib/Host.php`, `lib/Http.php`).
4. **Username.** `WebUI\Username` from the same file (PMSS sets it to the account name, `template.qbittorrent.conf:61`).
5. **The WebUI secret.** Not on disk: PMSS keeps only a PBKDF2 hash (`scripts/lib/user/qbittorrent.php:167-173`). The user types it once with `login` at a terminal (no echo); it is kept in `pikkumies/local/secrets/qbittorrent` (directory 0700, file 0600, set explicitly). On PMSS it is normally the account's own login: `scripts/changePw.php:109-112` copies a changed account password into qBittorrent. It goes only into the login request body; it is never printed, logged or put on a command line.

Errors name the cause: not installed (no port anywhere), not enabled (PMSS without `~/.qbittorrentEnable`; the watchdog starts qBittorrent only while it exists, PMSS `scripts/lib/user/watchdog.php:179-180`, `scripts/cron/checkQbittorrentInstances.php:14-17`), not running (nothing listens on the port), busy (no answer in time: state unknown, not down), refused, the port belongs to another account, nothing stored yet, rejected, or banned.

## Login and bans

- Every API call except `auth/login` needs a session; without one qBittorrent answers 403 (`webapp-5.0.0.cpp:323-324`). The tool first asks for `app/webapiVersion` without a session: 403 means a login is needed; 200 means the user turned on the localhost bypass, which `stats` then reports as a warning (the setting is left alone).
- Login is `POST auth/login` with the fields `username` and `password`. Up to 5.1 the answer is `200 Ok.` with a session cookie, or `200 Fails.` (`authcontroller-5.1.4.cpp:56-97`); from 5.2 it is `204` (no body) with the cookie, or `401` (`authcontroller-5.2.4.cpp:43-60`, `webapplication-5.2.4.cpp:368-373, 424-425`; live: 5.2.4 answered 204 and 401, 5.1.4 `200 Ok.`). 403 means the address is banned (`qbt-wiki-5.md:133-160`; `webapplication-5.2.4.cpp:1016-1021`). A 401 also answers a refused Host or Origin header (`webapplication-5.2.4.cpp:650-662`), but then the session-less version request before the login gets 401 too and no login is sent. The cookie name is configurable (`webapp-5.0.0.cpp:73, 174`), so the first `Set-Cookie` is sent back as received.
- No `Origin` or `Referer` header is sent; qBittorrent's CSRF check lets such requests through (`webapp-5.0.0.cpp:781-786`).
- **One login attempt per call.** After `Fails.` (5.2: 401) the stored secret is marked rejected and no call tries it again until the user runs `login`: 5 failures ban the address (research `torrent-clients.md` §2.2), and on PMSS the panel's proxy connects from the same 127.0.0.1, so a ban would lock the user out of their own WebUI.

## qBittorrent calls

| verb | call | source |
|---|---|---|
| version | `GET app/webapiVersion`, `GET app/version` | `qbt-wiki-5.md:185-215`; `appc.cpp:72-80` |
| list | `GET torrents/info` in pages of 500 (`sort=hash`, `limit`, `offset`; a row seen twice counts once), or `hashes=H1\|H2` for named torrents. Pages keep each answer far below `lib/Http.php`'s 5 MiB limit; an answer over it is reported as such, not as "not JSON" | `qbt-wiki-5.md:1202-1338, 1213-1216` |
| show | `GET torrents/properties?hash=H` | `qbt-wiki-5.md:1340-1440`; `tc-5.0.0.cpp:410-423` |
| show --trackers | `GET torrents/trackers?hash=H`: `url`, `status` (0 disabled, 1 not contacted, 2 working, 3 updating, 4 not working), `tier`, `num_seeds`, `num_leeches`, `msg`; the DHT/PeX/LSD rows (`** [DHT] **`...) are skipped | `qbt-wiki-5.md:1444-1490`; `tc-5.0.0.cpp:175-245, 506-530` |
| list fields | `completion_on` (`completed_at`; `null` unless above 0 and at most a day in the future), `seeding_time` (API 2.8.1 and later), `private` (5.0.0 and later; `null` without metadata), `uploaded` (all time); a field the version lacks is `null`. The wiki calls the private flag `isPrivate`, but 5.0.0, 5.1.4 and 5.2.4 send `private` (`serialize_torrent.h` `KEY_TORRENT_PRIVATE`; live 5.1.4 and 5.2.4), and 4.x sends none; `isPrivate` and `is_private` are read only when `private` is missing | `qbt-wiki-5.md:1241, 1251, 1279`; `qbt-wiki-4.1.md:215`; `serialize_torrent-5.2.4.cpp:121` |
| recheck | `POST torrents/recheck` with `hashes=H1\|H2` | `qbt-wiki-5.md:1744-1766`; `tc-5.0.0.cpp:1260-1266` |
| reannounce | `POST torrents/reannounce` with `hashes=H1\|H2` | `qbt-wiki-5.md:1768-1790`; `tc-5.0.0.cpp:1268-1274` |
| limit | read: `dl_rate_limit`, `up_rate_limit` of `transfer/info` (as `traffic`); set: `GET transfer/speedLimitsMode` (1 = alternative limits on), then `POST transfer/setDownloadLimit` / `transfer/setUploadLimit` with `limit` in bytes/s, 0 = none. qBittorrent keeps whole KiB/s (1 to 1024 becomes 1 KiB/s, more is cut to whole KiB), and with alternative limits on the same calls change the alternative limits | `qbt-wiki-5.md:1088-1102, 1134-1180`; `sessionimpl-5.0.0.cpp:477-480, 3359-3373, 3452-3464` |
| files | `GET torrents/files?hash=H`; names are relative to the torrent's base folder: `content_path` minus the single file's name or the common root folder, or `content_path` itself for several files without a root folder (where the data is, the incomplete folder while downloading); `save_path` only when `content_path` is empty | `qbt-wiki-5.md:1242, 1540-1600` |
| shared-file check | the rows of the paged list, then `GET torrents/files?hash=H` for each other torrent: it takes one hash, and the API has no call for several torrents' files | `qbt-wiki-5.md:1540-1551` |
| add | `POST torrents/add`, multipart: `urls` or a `torrents` file part, `savepath`, and `stopped=true` (API ≥ 2.11) or `paused=true` (older); answer `Ok.`/`Fails.`, 415 for a bad file; 5.2 answers JSON `success_count`, `failure_count`, `pending_count`, `added_torrent_ids` (200, or 202 while a URL is fetched) and 409 when nothing was added: added means an id listed or a count above 0, and a URL's id is taken from `added_torrent_ids` | `qbt-wiki-5.md:1792-1884`; `tc-5.0.0.cpp:693-806`; `qbt-wiki-4.1.md:1912`; `torrentscontroller-5.2.4.cpp:1264-1282`; live 5.2.4 |
| pause, resume | API ≥ 2.11: `POST torrents/stop`, `torrents/start`; older: `torrents/pause`, `torrents/resume` | `tc-5.0.0.cpp:928-942`; research §2.3 (API 2.11.2 = qBittorrent 5.0.0, 2.8.19 = 4.5.2) |
| remove | `POST torrents/delete` with `hashes` and `deleteFiles=true\|false` | `tc-5.0.0.cpp:1099-1110`; `qbt-wiki-5.md:1719-1742` |
| stats | `GET app/preferences` (`listen_port`, `save_path`, `torrent_content_remove_option`) | `appc.cpp:158, 206, 354` |
| traffic | `GET transfer/info` (`dl_info_speed`, `up_info_speed`, `dl_info_data`, `up_info_data`, `dl_rate_limit`, `up_rate_limit`) | `qbt-wiki-5.md:1028-1086` |

GET for reads and POST for changes (`qbt-wiki-5.md:121-125`: 405 otherwise since v4.4.4). Ids are always sent exactly as qBittorrent listed them, joined with `|`, after a check that each is 40 hex characters; `all` and an empty list are never sent.

States map as `downloading`, `forcedDL`, `metaDL`, `forcedMetaDL`, `allocating` → downloading; `uploading`, `forcedUP`, `stalledUP` → seeding; `pausedUP`, `pausedDL`, `stoppedUP`, `stoppedDL` → paused; `checkingUP`, `checkingDL`, `checkingResumeData` → checking; `queuedUP`, `queuedDL` → queued; `stalledDL` → stalled; `error`, `missingFiles` → error; `moving`, `unknown` and anything else → unknown (`qbt-wiki-5.md:1283-1305`; `stoppedUP`/`stoppedDL` from research §2.3).

## Ids of added torrents

`torrents/add` does not return an id, so the tool computes it and then reads the list back: the id is the v1 info-hash, or the v2 info-hash cut to 40 hex characters for v2 and hybrid torrents (`tc-5.0.0.cpp:421-423`). From a magnet: `xt=urn:btmh:1220<sha256>` wins over `xt=urn:btih:` (hex or base32). From a .torrent file: SHA-1 of the raw bencoded `info` dictionary, or SHA-256 cut to 40 when `info` has `meta version` 2. A URL is fetched by qBittorrent itself; its id is unknown until it shows up in `list`.

## Deleting data

`remove --with-data` runs the shared plan first (`lib/DeletePlan.php`: the exact `--expect` byte total and the shared-file check against the other torrents in this qBittorrent, because qBittorrent deletes the torrent's files without checking other torrents, `tcr.cpp:32-60`; torrents of another client are not seen). It reads `torrent_content_remove_option` and sends `deleteFiles=true`. qBittorrent 5 defaults to `MoveToTrash` (`sessionimpl-5.0.0.cpp:529`; `tcr.cpp:42` moves each file to the trash), so the record says `disposal: trashed` and no space is freed; any other value, or a qBittorrent without the setting, means `deleted`. The tool then reads back that each torrent left the list and, for `deleted`, waits up to 5 s for the files to be gone (`lib/DeleteRun.php`): `freed_bytes` counts only files that are gone, files still there make it `partial`, and any error ends the call with `ok: false`.

## Restart

- **PMSS**, only while `~/.qbittorrentEnable` exists (the panel creates it on start, PMSS `etc/skel/www/scriptsInc.php:1765-1768`); otherwise refused with the panel instruction. Graceful stop of this user's own `qbittorrent-nox`: processes matched in `/proc` by owner uid, mount namespace and exact name (`comm`, and `exe` when readable), as the panel does (`scriptsInc.php:1136-1166`); SIGTERM, up to 10 s for qBittorrent to save its state, SIGKILL only to survivors that the same owner, name and namespace match still finds, so a pid reused during the wait is never killed (the panel waits 3 s, `scriptsInc.php:1187-1191`). When the tool cannot read its own mount namespace it finds no process at all, as PMSS (`scriptsInc.php:1139-1140`). Then `qbittorrent-nox -d`, the panel's start command (`etc/skel/www/qbittorrent.php:10`), run from `$HOME` like the watchdog (`scripts/cron/checkQbittorrentInstances.php:16`). Never `pkill -f`.
- **Ultra.cc**: `app-qbittorrent restart` (docs.ultra.cc/applications/qbittorrent, fetched 2026-10-14).
- **Other hosts**: refused, with the provider's own way: Feral restarts qBittorrent by itself within five minutes and says it cannot be started by hand (feralhosting.com/wiki/software/qbittorrent; its only documented command is `pkill -f`, which this tool never uses); Whatbox uses its Manage page (whatbox.ca/wiki/bittorrent_clients).
- After the start the tool waits up to 30 s until this user's listener on the WebUI port answers, then reads the version (logging in with the stored secret, one attempt).

## Tests

`tests/Qbittorrent*Test.php` (helpers in `tests/fixtures/qbittorrent/harness.php`) run the unit against a fake WebUI (`tests/fixtures/qbittorrent/router.php` under `php -S`) with HOME in a temporary directory. The test wrapper sets PHP variables before including `qbittorrent.php` (`$qbitFamily`, `$qbitSecretDir`, `$qbitProcRoot`, `$qbitPage`); nothing is read from the environment for them. The fake `/proc` has mount-namespace links (one process in another namespace), and the foreign-port check runs on a fixed `/proc/net/tcp` (`QbitEndpoint::$tables`).

## Unverified

- [UNVERIFIED] The 5-failure / 3600 s ban default comes from research §2.2 (upstream `preferences.cpp`, not in the local copies); the login answers and the ban's 403 were read in the 5.1.4 and 5.2.4 source (above), and live 5.2.4 counted attempts 1, 2 in its log.
- [UNVERIFIED] Whether 5.2 answers the change calls (recheck, reannounce, the limit setters) with 200 or 204: they set an empty result (`torrentscontroller-5.2.4.cpp:1823`), which Qt 6 may or may not count as null; the tool accepts both.
- [UNVERIFIED] The text value of `torrent_content_remove_option` for "delete": only `MoveToTrash` was seen (`sessionimpl-5.0.0.cpp:529`, `appc.cpp:938`); every other value is treated as a permanent delete.
- [UNVERIFIED] Where the trash is on Linux (Qt `QFile::moveToTrash`, `fs.cpp:330-346`); the tool does not report a trash path.
- [UNVERIFIED] That `content_path` of a multi-file torrent without a root folder is the folder that holds its files (`TorrentImpl::contentPath` is not in the local source copies; the wiki only says "root path for multifile torrents", `qbt-wiki-5.md:1242`).
- [UNVERIFIED] Whether `torrents/info` on qBittorrent 4.x before API 2.6 has `content_path`; without it the tool uses `save_path` + file name, which is right for torrents with a root folder.
- [UNVERIFIED] The v2/hybrid id rule on qBittorrent 4.4–4.6 (only the 5.0.0 source comment was read) and what `infohash_v1`/`infohash_v2` look like on 4.x.
- [UNVERIFIED] Uptime assumes 100 clock ticks per second in `/proc/<pid>/stat` (USER_HZ).
- [UNVERIFIED] Feral and Ultra.cc: whether `WebUI\Port` in the user's `qBittorrent.conf` is the port reachable on 127.0.0.1 (Feral runs qBittorrent in a container with port 9001), and whether the WebUI login there is the slot login.
- [UNVERIFIED] That `app-qbittorrent restart` exits 0 on success.
- [UNVERIFIED] What `completion_on` holds for a torrent that has not finished (the wiki does not say; values of 0 or below, and values more than a day in the future such as 4294967295, print as `null`), and whether `num_seeds`/`num_leeches` are -1 when the tracker sent no scrape (values below 0 print as `null`).
- [UNVERIFIED] Whether a limit set through the API is written to qBittorrent's config at once: the values are session settings (`sessionimpl-5.0.0.cpp:477-480`), but the settings storage code is not in the local copies, so the record has no `persists` field.
- The 4.1 wiki copy has the same calls: `speedLimitsMode` (`qbt-wiki-4.1.md:1130`), `setDownloadLimit` (:1176), `setUploadLimit` (:1208), `recheck` (:1787), `reannounce` (:1811). [UNVERIFIED] on a running 4.x client.
- Timeouts: `lib/Http.php` fixes the connect timeout at 10 s; the tool sets the whole request to 5 s for the version probe and login (the brief asked for a 5 s connect timeout) and 20 s for other calls.
