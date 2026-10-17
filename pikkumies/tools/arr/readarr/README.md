# readarr

Reads the user's own Readarr (books and audiobooks): authors, queue, wanted books, history, health and stats, and restarts a Docker copy. Nothing is added, removed or searched: the Readarr project was retired and its GitHub repository archived (GitHub API `repos/Readarr/Readarr`: `archived: true`, last push 2025-06-27, read 2026-10-14), so `lookup` and `add`, which depend on its metadata service, are left out, and so are the deleting and downloading verbs. It talks to Readarr's API on 127.0.0.1 as the user, with the key from Readarr's own config.xml, never as root. Shared rules: `tools/arr/README.md`; the Readarr entry is in `tools/arr/lib/ArrAppsMore.php`.

## Sources

- API shapes: Readarr's OpenAPI file `src/Readarr.Api.V1/openapi.json` at commit `0b79d3000d4e5f8425f499970b0190e2c421fceb` (branch `develop`, 2025-06-27, the last commit before the archive). PMSS's updater still installs Readarr release builds into `/opt/Readarr` (`scripts/lib/update/apps/arr.php:17`). Source files below are from the same commit.
- PMSS facts: the PMSS repository (commit `5b493872`, 2026-10-08), read only.

## Where Readarr is found

The PMSS media-stack installer does not install or manage Readarr (`etc/skel/install-media-stack.sh:258-263`); the `arrinfo` alias prints a Readarr address (`etc/skel/.bashrc:121`). First match wins:

1. Override: `'arr' => ['readarr' => ['config' => ...]]` in `pikkumies/local/config.php`.
2. Readarr's default: `~/.config/Readarr/config.xml` (`NzbDrone.Common/EnvironmentInfo/AppFolderInfo.cs:26,36`). [UNVERIFIED: .NET's ApplicationData on Linux.]
3. Docker: `~/docker/readarr/config/config.xml`, the PMSS documentation's LinuxServer.io recipe (`docs/linuxserver.io.md:494-508`: container `readarr`, `~/docker/readarr/config:/config`, port 8787; upstream recipe, not PMSS-tested, `:342-346`), with the host port from `docker port readarr <Port>/tcp` against the user's own daemon.

## Readarr calls

| verb | call | source |
|---|---|---|
| all | header `X-Api-Key`; base `http://127.0.0.1:<Port><UrlBase>/api/v1/` | spec `components.securitySchemes` |
| list, show | `GET author` (no boolean parameters); `authorName`, `statistics.sizeOnDisk`, `bookFileCount`, `bookCount` | spec `AuthorResource`, `AuthorStatisticsResource` |
| stats | `GET system/status`, `GET health`, `GET diskspace` | spec |
| history | `GET history?...&includeAuthor=false&includeBook=false`; `--since`: `GET history/since?date&includeAuthor=false&includeBook=false` | spec |
| queue | `GET queue?page&pageSize&includeUnknownAuthorItems=false&includeAuthor=false&includeBook=false` | spec `QueueResource` |
| wanted | `GET wanted/missing` or `wanted/cutoff` with `includeAuthor=false&monitored=true` (books: `id`, `authorId`, `title`, `releaseDate`) | spec `BookResource` |

## Secrets

Readarr's `Field` schema has no `privacy` property at all (the spec defines no `PrivacyLevel`). The shared rule keeps a setting's value only when `privacy` is exactly `normal` (`lib/ArrRecords.php` `scrub`), so every Readarr setting value is hidden. No verb here prints settings anyway.

## Restart

Docker: `docker restart readarr` against the user's own daemon, then `GET system/status` until `startTime` is newer, up to 60 s. Anywhere else, PMSS included, the tool refuses with a hint: no PMSS installer manages Readarr, and `POST system/restart` is never used.

Queue removal is not offered: `DELETE queue/{id}` defaults to `removeFromClient=true` and the client then deletes the data (`Readarr.Api.V1/Queue/QueueController.cs:72,259`).

## Unverified

- [UNVERIFIED] Nothing was run against a live Readarr.
- [UNVERIFIED] Whether Readarr's metadata service still answers; it does not matter to the read verbs.
