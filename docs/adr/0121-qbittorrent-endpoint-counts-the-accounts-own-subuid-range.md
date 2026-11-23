# ADR-0121: The qBittorrent endpoint check counts the account's own sub-uid range as the account's own

## Metadata
- Author: Väinämöinen (for Aleksi Ursin / MCX)
- Date: 2026-11-23
- Status: Accepted

## Context
`QbitEndpoint::locate()` and every request `QbitApi` sends check, before any login, that the listener on the WebUI port belongs to this account ([ADR-0019](0019-hosts-and-credentials.md)). `Host::ownsListener()` compared the listener's uid with the account's uid only. A rootless-Docker qBittorrent run as a non-zero container user (the common image default) listens as a host uid inside the account's own range in `/etc/subuid`, so the check answered "belongs to another account", and the hint (restart it from the panel so it gets its port back) did not help: the port was the account's own. The setup in the Pulsed Media guide maps the container user to the account's own uid and was never affected; the gap is containers set up outside the guide.

The refusal itself is correct and stays: the login must never reach another uid's service. Sub-uid ranges are meant to be disjoint per account, and the kernel's user-namespace mapping (newuidmap, which reads `/etc/subuid`) lets only the account itself start processes in its range.

## Options Considered
- **Change only the hint.** Smallest, but the account's own client stays unusable.
- **Count the whole `/etc/subuid` as own.** Rejected: it would let another account's listener through.
- **Count the account's own range as own, trusting the file (chosen first, then tightened).** One uid-range test. Because `/etc/subuid` is a plain file whose allocation this tool does not control, a range that overlaps another account's range, or starts below uid 100000 (the floor PMSS's own ownership repair also uses, since a lower range can cover real accounts), is dropped whole.
- **Widen every caller of `Host::ownsListener()` (Deluge, media, Docker tools).** Rejected for now: those checks answer different questions and none has a reported failure; each can pass the same ranges when it has one.

## Decision
- `Host::ownsListener()` accepts `[first, last]` pairs in its uid list besides single uids. `Host::ownSubuidRanges($uid, $file)` returns the account's ranges that are safe to count: matched by name or number, floor 100000, and dropped whole when they overlap any other owner's range. An unreadable or malformed file gives no range, which is today's answer. `Host::subuidEntries()` is the one parser of the file; `DockerDiagnose::subuidRanges()` uses it too.
- `QbitEndpoint` passes its uid and its ranges to the check in `locate()`, and `QbitApi` passes the ranges as `Http::request`'s `owners` option, so the second owner check at connect time agrees with the first.
- The refusal keeps its message; its hint now says the port is held by a different user ID than the account's own or its container range.
- Tests (`HostTest`, `HttpHostTest`, `QbittorrentHostTest`): a uid inside, at both ends of, one below and one past the range; another account's range; an overlap; a range below the floor; an unreadable file.

## Consequences
- An account running a container with a non-zero container user can use the qBittorrent tools without changing its setup.
- The trust placed in `/etc/subuid` is only as good as its disjointness. The overlap and floor rules make a broken file fail closed, not open.
- `QbitRestart` is unchanged: after a restart, a container that holds the port reads as answered. How many accounts run a non-zero container user is unmeasured; this change does not depend on it.

## References
- GitHub issue #11; [ADR-0019](0019-hosts-and-credentials.md); `pikkumies/lib/Host.php`, `pikkumies/lib/Http.php`, `pikkumies/tools/torrent-clients/qbittorrent/`.
