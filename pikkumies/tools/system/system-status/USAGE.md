# system-status

Read-only snapshot of this machine. Nothing is written and nothing goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| show | `[--human]` | no | One record: the cached classification (host family, stack, root access, tier), own running apps, this moment's own CPU/memory/disk limits (leading with whichever is closest to its cap), load average. |
| logs | `[--grep TEXT] [--human]` | no | This account's own log files known for this host family/stack (path relative to HOME, size, modified). `--grep TEXT` adds matching lines: case-insensitive, the last 1 MiB of each file, at most 50 lines in all. Only files under HOME that this account owns. |

```sh
php ~/pikkumies/tools/system/system-status/system-status.php show            # JSON Lines for the agent
php ~/pikkumies/tools/system/system-status/system-status.php show --human    # for people
```

- `root.available` comes from what the OS allows (real uid 0 or passwordless sudo), never from configuration.
- `family` is `pmss`, a known seedbox host family (`feral`, `whatbox`, `ultra`, ...), or `generic`; `stack` is `pmss` or a known self-hosted installer (`swizzin`, `quickbox`, `saltbox`), or `none` (ADR-0019, ADR-0023).
- `apps` lists only the current user's processes.
- `limits.cpuQuota`/`limits.cpuset`/`limits.memMb`/`limits.disk` are read live every call, each with its own `source` (`userShowLimits` on PMSS, `cgroupv1`/`cgroupv2` elsewhere, `host` only on a dedicated, non-shared account, or `nproc`/`df` as a last resort).
- `pikkumies detect [--refresh]` re-runs the classification once and writes the cache this tool reads; `detect` is the only command that probes identity/rDNS.
