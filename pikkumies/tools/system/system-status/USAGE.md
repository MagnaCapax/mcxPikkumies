# system-status

Read-only snapshot of this machine. Nothing is written, and nothing goes over the network except `health`'s one GET of the account's own panel on this machine (PMSS).

| verb | arguments | changes | what it does |
|---|---|---|---|
| show | `[--human]` | no | One record: the cached classification (host family, stack, root access, tier), own running apps (read now), this moment's own CPU/memory/disk limits (leading with whichever is closest to its cap), load average, and `server_totals` (the whole server's CPUs, memory and /home size, with a `scope` saying whether other accounts share it). |
| account | `[--human]` | no | One live record of this account's own figures ([ADR-0058](../../../../docs/adr/0058-account-status-verb-and-live-figures-before-search.md)): disk quota (used, soft, hard, left, files, snapshot age), memory (used against limit, pressure, OOM kills), upload traffic in the last 30 days against its limit, processes, CPU quota, PMSS and Debian versions, bonus, whether Docker is enabled for the account (`docker.enabled`), own running apps, and the panel address as a template. On PMSS from `~/bin/userShowLimits --json`; elsewhere the same keys with null figures. Never the account's or the server's name; never the server-wide section. |
| health | `[--human]` | no | One record for "is it down?" ([ADR-0066](../../../../docs/adr/0066-system-status-health-one-answer-to-is-it-down.md)): `summary` (the first finding, or that all is well), `findings` (fixed sentences, most blocking first), `machine` (`up_s`, `not_shown`), `panel` (PMSS: `answers`, `platform_reason` with the 502 page's `reason` key, or `no_answer`; null elsewhere), `clients` (rTorrent, qBittorrent, Deluge: `listening`, `not_running`, `not_enabled` or `unreachable`, `why`, `running_s`, `started_after_boot`, `next_step`), `quota` (the storage unit's state, `doc`), `memory` (PMSS's level and OOM kills). One local HTTP GET of the account's own panel on PMSS; nothing else goes over the network. |
| logs | `[--grep TEXT] [--human]` | no | This account's own log files known for this host family/stack (path relative to HOME, size, modified). `--grep TEXT` adds matching lines: case-insensitive, the last 1 MiB of each file, at most 50 lines in all. Only files under HOME that this account owns. |

```sh
php ~/pikkumies/tools/system/system-status/system-status.php show            # JSON Lines for the agent
php ~/pikkumies/tools/system/system-status/system-status.php account         # this account's own figures, live
php ~/pikkumies/tools/system/system-status/system-status.php show --human    # for people
```

- `root.available` comes from what the OS allows (real uid 0 or passwordless sudo), never from configuration.
- `family` is `pmss`, a known seedbox host family (`feral`, `whatbox`, `ultra`, ...), or `generic`; `stack` is `pmss` or a known self-hosted installer (`swizzin`, `quickbox`, `saltbox`), or `none` ([ADR-0019](../../../../docs/adr/0019-hosts-and-credentials.md), [ADR-0023](../../../../docs/adr/0023-host-detection-cache.md)).
- `apps` lists only the current user's processes, read on every call (never the detection cache).
- `account` picks each figure by the name PMSS gives it in userShowLimits; a figure PMSS renames reads as null, never a guess. `unlimited` lists the limits PMSS itself reports as unlimited (a value with no limit); any other null is unknown.
- `limits.cpuQuota`/`limits.cpuset`/`limits.memMb`/`limits.disk` are read live every call, each with its own `source` (`userShowLimits` on PMSS, `cgroupv1`/`cgroupv2` elsewhere, `host` only on a dedicated, non-shared account, or `nproc`/`df` as a last resort).
- `pikkumies detect [--refresh]` re-runs the classification once and writes the cache this tool reads; `detect` is the only command that probes identity/rDNS.
