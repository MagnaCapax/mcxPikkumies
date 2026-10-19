# ADR-0023: Host detection runs once; the cache is a hint, never an authority

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-15
- Status: Accepted

## Context
The operator's request of 2026-10-15: "the launcher deterministically determines which provider the service is on, does it have root access ... and record them into local config so the determination process is only ran once. ... try to determine which kind of service, or software package. PMSS is easy to detect, and has the resources tool within user space to use to expose resource limits to the AI Agent too. ... Try to detect resource limits, CPU etc. on all systems, but make the local config, only do it if local config is missing ... from that we can determine log paths to investigate too. ... deep research and try to determine how to detect feral, rapid seedbox, whatbox, bytesized etc."

`Detect::run()` ([ADR-0004](0004-provider-key-and-root-detection.md)) already reports root, apps and host-wide resource totals, computed fresh on every call — correct on a dedicated box, wrong on a shared one (host-wide `MemTotal`/`nproc` are not the account's own ceiling), and too slow to run on every `pikkumies run` once it also probes rDNS, a PMSS tool or a cgroup chain.

## Options Considered
- **Keep probing live every run.** Simple, but wrong limits on shared hosts, and slower as more probes are added.
- **A new `HostCache` class, separate from `Detect`.** A second home for the same data; the vplan's round 1 rejected this (simplification pass): merge the cache into `Detect` itself as `Detect::cached()`.
- **Cache everything, including log-path globs.** Round 12 rejected this: log globs are derived from `Host::TABLE` on use, by whichever tool searches logs, and are never written to the cache or the Hermes prompt (a stale cached glob after a PMSS update would silently miss new log paths).
- **A TTL-based cache (re-detect after N hours).** The operator asked for "only once... if local config is missing," not a timer. A TTL would re-probe for no reason on a box that has not changed, and would not catch a change that happens sooner (a userTransfer move).

## Decision
- `Detect::cached($refresh = false)` reads `local/host.json` when its `schema` matches the code's current `Detect::SCHEMA` and its `fingerprint` (hostname, `/etc/machine-id`, uid) matches this machine and account; otherwise it runs `Detect::run()` once, writes the cache (temp file + rename, mode 0600, ADR-0004's privacy model), and appends one line to `local/update.log` (ts, family, stack, tier, each limit's source, the probe's own duration). `pikkumies detect --refresh` forces a fresh probe. A probe that throws is returned in-memory for that one call and is never cached, so a transient failure (a disk hiccup, a timed-out subprocess) never persists a half-detected machine and never blocks a start.
- The fingerprint, not a TTL, is the invalidation signal: a PMSS `userTransfer` moves a home to a different server with a different hostname and machine-id, which changes the fingerprint and forces a fresh probe automatically. Nothing else is expected to change on its own; `--refresh` covers the rest (sudo granted, a cgroup limit raised).
- `local/config.php`'s `host` key, when the user sets it, is merged over the cached (or freshly probed) profile on every read — it always wins, the same rule `root.disabled` already follows.
- `Detect::run()` itself grew: `identity` (hostname, fqdn, and `rdns` of the primary IP, attempted only while `family` is still `generic` — a resolver query, not web access, [ADR-0021](0021-web-access-off-unless-configured.md) addendum below), `family` and `stack` (`Host::family()`/`Host::stack()`, ADR-0023's own table, see `Host.php`), `tenancy.shared` (pmss, or more than one human account in `/etc/passwd`; the account *count* never leaves the function — only the boolean), `root.suPresent` (an `su` binary exists on PATH; it is never executed, same principle as ADR-0004's sudo probe), and `limits` (`cpuQuota`, `cpuset`, `memMb`, `disk`, each `{value, source}`): PMSS reads its own `$HOME/bin/userShowLimits --json`; every other host reads its own cgroup chain (`Cgroup.php`, walking every ancestor from `/proc/self/cgroup` to the mount root and taking the minimum, because a parent slice's limit binds even when the leaf cgroup shows "unlimited"); `disk` always reuses the existing `Quota::fields()`. A dedicated (non-shared) account that still has no determinable limit is reported at the host's own total, labelled `source: host`; a shared account with no determinable limit stays `null` — reporting "the whole host" to a tenant would be a wrong answer, not a cautious one. `tier` (`small`/`medium`/`large`) is read off the account's own `cpuQuota`/`memMb`; an unknown value never guesses a tier either way and defaults to `medium`.
- `io` and `pids` limits are deliberately left out: nothing in this repository consumes them yet (YAGNI; add them with their first consumer).

## Consequences
- `pikkumies run`/`install`/`update` read the cache instead of re-probing identity, root, PMSS tooling and cgroups on every start; `system-status` still reads CPU/memory/disk live (ADR-0004 addendum below) but reuses the cached classification.
- A cache a user can edit cannot escalate anything: see the ADR-0004 addendum.
- A machine that silently changes shape between the official invalidation signals (sudo granted without a reboot, for example) keeps the old answer until `--refresh` or a schema bump. This mirrors the operator's own request ("only do it if local config is missing") and is the documented trade-off, not an oversight.

## References
- ADR-0004 (provider key and root detection; addendum below), [ADR-0016](0016-jsonl-is-the-default-output.md)/0017 (`--jsonl`/`--human`), [ADR-0019](0019-hosts-and-credentials.md) (hosts table), ADR-0021 (web access; rDNS is not web access)

## Addendum 2026-10-15: `rdns` is a resolver query, not web access (ADR-0021)
ADR-0021 turns off Hermes' own `web_search`/`web_extract` fallback to outside services unless the user configures a backend, because nothing should leave the machine the user did not ask for. `identity.rdns` is a single bounded reverse-DNS lookup (`timeout 3 getent hosts <ip>`, the primary IP taken from the kernel's own route cache, never a packet sent, verified on a live host) run once, only when `family` is still `generic`, as part of the detection the user already asked for by running Pikkumies on this machine. It is not a web request, and ADR-0021 does not apply to it.

## Addendum 2026-10-15: tenancy and the per-account check never read another account's data
`tenancy.shared` reads only `/etc/passwd` (world-readable, lists every account but no account's contents) and counts entries with `uid >= 1000` and an existing home directory; the count is used only to decide the one boolean and is discarded, never returned, never logged. No other user's files, processes or cgroup are read. This is the same boundary `Detect::runningApps()` already drew for `/proc` (ADR-0004: "Running apps are read from `/proc` for the user's own processes only").
