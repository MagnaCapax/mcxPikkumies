# ADR-0035: One performance unit: light diagnostics by default, load tests only on the user's yes

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-17
- Status: Accepted (foundations: the dispatcher, runner, file reader and limits; the verbs land in stages, each answering "not built yet" until it does). The guard entries and the dated [ADR-0020](0020-deletes-need-the-users-approval.md) amendment come with the `heavy` stage; the measured-cost addendum comes with the integration stage.

## Context
The request of 2026-10-17, verbatim: "Actually! Need full performance diagnostics tooling + skill too , all of it. but default do not do heavy tests that stress the server. also needs to consider I/O, CPU etc. prioritiers", next to the containers request of the same day ([ADR-0034](0034-containers-category.md)).

Facts read for this decision:
- On PMSS the user has no root. The platform ships `~/bin/userShowLimits --json`, which runs as the user and reports the account's limits, use, OOM kills, traffic and the server's pressure (PMSS `etc/skel/bin/userShowLimits`); Pikkumies already reads it (`Detect::userShowLimits`). Its JSON names the account and the server (`user`, `host`, and `username`/`hostname` metrics), which [ADR-0023](0023-host-detection-cache.md) keeps out of what reaches the model.
- `/proc/pressure/*`, `/proc/stat`, `/proc/diskstats` and the account's own cgroup files are readable without privilege. Pressure must be read per resource (cpu `some`, io `full`, memory `full`, as userShowLimits does): CPU `full` is near 0 at the root on a busy server.
- mtr and ping work unprivileged where the distribution grants their binaries the capability (checked on one PMSS server); `mtr --report -c N` exits on its own. A deadline on mtr bounds the parent only unless the whole process group is killed. iperf3 is not part of the PMSS base.
- The Pulsed Media wiki's procedure for route problems is mtr in both directions with at least 1000 packets; the home-to-server direction runs on the user's computer. The wiki calls speed tests unreliable and the client-area Diagnose button is the platform's account-and-server check.
- PHP 7.3 has no `fsync()`, so a synced-write probe has to be a subprocess (`dd oflag=dsync`). The idle I/O class is honoured only by BFQ/CFQ.
- ToolProc stays in `tools/storage/lib` (the backup units require it from there; ADR-0034 keeps it there too).

## Options Considered
- **One unit per area** (network, disk, cpu, memory): four skills on every turn and four classifiers of the same account state.
- **A `doctor` unit**: the name is taken by `pikkumies doctor` (lib/Launch.php).
- **Merge into `system-status`**: changes the shape of a record other skills rely on, and its `show` is read before anything is changed, not when something is slow.
- **One unit, `tools/system/performance`, five verbs, code split into class files** (chosen; the containers precedent). One skill, one rule table, one privacy boundary; builders stay file-disjoint.

## Decision
- **Verbs**: `check` (limits and use, server pressure, own sessions, a verdict; `--tick` writes one silent sample), `net` (mtr, then traceroute, then ping), `disk` (16 synced 4 KiB writes, 64 direct 4 KiB reads), `heavy` (fio, a big write only on a non-shared server, a single-stream download through `lib/Http.php`, an installed iperf3 or speedtest binary), `samples` (per-hour medians). Not added: history, sample start/stop (the Hermes cronjob tool already creates and removes jobs), DNS timing, PMTU, multi-stream downloads, a parser for pasted benchmarks.
- **Light by default.** `check`, `net` and `disk` are `changes: no`; `heavy` is `changes: loads`, asked by the guard for each run, refused under pressure, within the cooldown or near the plan's traffic limit, and its result is labelled "your share now, not the ceiling".
- **Two run modes, no idle class for probes.** Walks: `nice -n 19 ionice -c3 timeout -k 2 N`. Probes: `nice -n 19 ionice -c2 -n7 timeout -k 2 N`: a probe in the idle class on a busy disk measures only its own wait. Niceness orders work only inside the account's own group; other accounts are protected by the OS's per-account weights and limits and by the small probe sizes, not by niceness.
- **Pressure rule**: one parser, per resource, avg10 and avg60, missing = unknown. Verdicts only for io full (avg10 >= 15, avg60 >= 10); the heavy gate adds memory full avg10 >= 10 and cpu some avg10 >= 80 (provisional).
- **Privacy**: it reads only your own account. Processes by owner uid; cgroup files at the account's own directory from `/proc/self/cgroup`, through one reader (`PerfFs`) that cannot list and refuses globs and paths outside the account; connections filtered in the kernel by the session's own endpoints. The model record carries no hostnames, usernames, home address, hop addresses, torrent names or command lines; `check --share --with-ip` adds addresses only at the user's request. `Detect::userShowLimitsData()` drops the names from userShowLimits. Nothing is sent anywhere (AGENTS.md privacy rule; the telemetry setting of [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) has no sender).
- **Verdicts come from a fixed rule table** in PHP (class, evidence, negation, disproved_by, next step), with a list of claims the text never makes (README.md). The model narrates; it does not diagnose from raw numbers.
- **Sampling** is a Hermes no_agent job the agent creates on the user's request: script `pikkumies-performance-sample.sh` (one line running `check --tick`, exiting quietly if the unit is gone), `every 15m`, repeat 96. Bounded because each job keeps the per-minute tick alive ([ADR-0024](0024-hermes-features-on-at-install.md), [ADR-0025](0025-scheduled-jobs-per-minute-tick-under-no-new-privs.md)).
- **One run at a time** per account (non-blocking lock); scratch inside the account's quota, removed by the run, swept when a run died; every budget in `PerfLimits`, each provisional until measured.

## Consequences
- One skill (about 100 bytes of index per turn) and 795 bytes of `tools/system/DOCTRINE.md` in SOUL.md.
- The guard's load and privacy entries are safety prompts for the agent's own mistakes, not security (ADR-0020); a path built in a variable gets past them, the OS does not.
- Telemetry: nothing is sent; `local/performance/runs.jsonl` stays on the machine.
- Follow-ups outside this unit: the leaf-memory reading of `Detect::leadingLimit`, qBittorrent cache fields and web-UI latency in their own units.

## References
[ADR-0015](0015-tool-units-one-skill-per-tool.md) (tool units), [ADR-0016](0016-jsonl-is-the-default-output.md)/0017 (output), [ADR-0019](0019-hosts-and-credentials.md) (Http), ADR-0020 (approvals), ADR-0023 (identity), ADR-0024/0025 (cron), [ADR-0029](0029-tool-doctrine-loaded-into-soul.md) (doctrine in SOUL.md), ADR-0032 (telemetry setting), ADR-0034 (containers). Pulsed Media wiki: network diagnostics, network troubleshooting with mtr, Diagnose. PMSS `etc/skel/bin/userShowLimits`.
