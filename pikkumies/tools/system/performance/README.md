# performance

Answers "why is it slow" from inside the user's own account, without root (ADR-0035). It looks at the account's limits and what uses them, at how busy the server is, at the user's own transfer sessions, at the route to a target and at the disk, and names what it found from a fixed rule table. By default it never stresses the server: load tests run only when the user asks and approves each one.

It reads only your own account: its own processes, its own cgroup directory, its own connections. Nothing it finds is sent anywhere; the probe packets of `net` and the traffic of a `heavy` test the user approved are the only things that leave the machine.

## Verbs

`check`, `net`, `disk`, `heavy`, `samples` (USAGE.md has the table). The code is split by verb: `performance.php` checks the arguments and runs the verb's class; `PerfProbe.php` (runner), `PerfFs.php` (the one file reader) and `PerfLimits.php` (every number) are shared; `PerfAccount`, `PerfHost`, `PerfProcs`, `PerfSessions`, `PerfRouter` and `PerfSamples` make `check` and `samples`; `PerfNet` and `PerfDisk` make `net` and `disk`; `PerfHeavy` makes `heavy`. A verb whose class is not in this version answers "not built yet" and runs nothing.

Each verb class has one static method, `(PerfProbe $probe, array $params)`, and returns records as `Cli::run` expects; `$params` are already checked: check `symptom`, `share`, `with_ip`; net `target` (`ssh`, an IP or a hostname), `count`; disk `read`; heavy `test`, `url`, `target`; samples `hours`. `PerfSamples::tick` writes the scheduled sample. A class that finds a bad argument throws `PerfUsageError` (exit 2). It puts the router classes it found in `$probe->classes` and the checks it skipped in `$probe->skipped`; the dispatcher logs them.

## How it finds things on each host

| host | account limits and use | server |
|---|---|---|
| PMSS | `~/bin/userShowLimits --json` (the platform's own per-account tool) through `Detect::userShowLimitsData()`, which drops the account and server names before anything else sees them; OOM kills there are lifetime counts | `/proc/pressure`, `/proc/stat`, `/proc/diskstats`, `/proc/mdstat` |
| elsewhere | the account's own cgroup directory, found from `/proc/self/cgroup`: memory.stat, memory.events, cpu.stat, io.max or the blkio throttle files, and the cgroup's own pressure files where the kernel has them | the same `/proc` files |
| no cgroup of its own | `unavailable` on a shared server; on a dedicated one the machine's figures, labelled `scope: machine` | the same |

The client-area Diagnose button on PMSS checks the account and the server with the platform's own tools; `check` points to it rather than rebuilding it.

## Runner

- Every program runs with an argv through `ToolProc::run` (required from `tools/storage/lib`, as the backup units do), with `nice -n 19` and `timeout -k 2 <seconds>` in front: a program that does not stop is killed two seconds later, with its children.
- Walks (`find`) use scan mode: the idle I/O class, so they wait for everyone else. Probes use measure mode: best-effort level 7, because a probe in the idle class on a busy disk measures only its own waiting. The class is printed with each probe result.
- Niceness and the I/O class order work only inside the account's own group [inferred from how the kernel groups scheduling per cgroup]; what protects other accounts is the OS's per-account weights and limits, and the small, capped probes (the Budgets table).
- One run at a time per account (a non-blocking lock on `local/performance/run.lock`). Probe files live in `local/performance/scratch/<pid>/` (0700, files 0600 created exclusively), inside the account's quota, never in `/tmp`; the run removes them, and the next run removes what a killed run left.
- Each run appends one line to `local/performance/runs.jsonl` (0600, never sent): the verb, a hash of the machine fingerprint (a server move shows as a change), the classes found, the checks skipped, and the cost: wall time, children's CPU time, bytes written, bytes read directly, packets sent, bytes downloaded.
- Pressure (PSI) is read one way everywhere: cpu `some`, io `full`, memory `full`, avg10 and avg60. A missing file is unknown, never 0. Only io full makes a verdict (avg10 >= 15 burst, avg60 >= 10 sustained). Light probes are never held back by pressure; it is shown beside their result. A heavy test is refused when io full avg10 >= 15 or avg60 >= 10, memory full avg10 >= 10, or cpu some avg10 >= 80. Unknown pressure does not refuse it (PSI is off on some kernels); the result shows what was seen.

## Privacy

- Processes: those whose owner in `/proc/<pid>/status` is this account, whatever `/proc` shows.
- Cgroup files: this process's own cgroup directory only. `PerfFs` has no listing and refuses a glob, a `..` segment, any path that names another account's resource files, and the kernel's socket tables, whoever asks.
- Connections: filtered in the kernel by your own SSH connection's address and your own ports; raw rows are never printed. Where the host hides other sockets, sessions say "not visible here".
- What reaches the model: no hostnames, usernames, your home address, route hop addresses, torrent names or command lines. `--share` is a fixed list of fields for a ticket; `--with-ip` adds your connection's address and the hops, only at the user's request. Nothing is written for it.

## Rules

`tools/system/DOCTRINE.md` goes into SOUL.md word for word (ADR-0029). Each rule, then why it is there:

- Read only this account's processes, cgroup files and connections; never read, name or count other accounts.
  Why: other customers' activity is theirs (privacy first), and an agent that talks about "a noisy neighbour" invents what it cannot see and repeats it to the user.
- No hardware verdict from speed, load, PSI or sector counts; busy is not broken.
  Why: a busy disk is demand, not a fault. A hardware verdict needs the provider's own diagnostics, and a wrong "your disk is failing" scares the user and sends support after the wrong thing.
- Load average is run-queue length, not CPU use; PSI some is not saturation.
  Why: load average counts processes waiting for the disk too, so it is high on an idle CPU; `some` means at least one task waited, which happens on every healthy server.
- Report a found limit with its value, never as deliberate or a bug; suggest a bigger plan only when a plan limit is the measured cause.
  Why: the tool sees that a cap exists, not why. Selling an upgrade for a problem the plan does not cause costs the user money and fixes nothing; an I/O cap lower than the plan says is a ticket, not an upgrade.
- Route verdicts come from the hop where loss starts and lasts to the end, never mid-path loss; the home side is measured by the user, never inferred.
  Why: routers answer probes at low priority, so one hop showing loss that later hops do not is that router's rate limit. The user's own line can only be measured from the user's own computer (the wiki procedure runs mtr both ways).
- Load tests (fio, iperf3, speedtest, big downloads) only via `performance heavy` on the user's yes, never chained or scheduled; results are the share now, not the ceiling.
  Why: on a shared server a load test takes disk and bandwidth from everyone, and its number is what this account got at that moment, not what the server can do. The guard asks before each one.
- Never promise what support or the provider will do; no prices.
  Why: the agent cannot commit anyone but itself, and prices change; the client area and the provider's own pages are the source.

Never-claims (the router's text is checked against them): hardware failing or degraded; another account's activity, identity or count; a cap called deliberate or a bug; a route verdict from loss that does not persist; a home or ISP cause inferred; load average as CPU use; PSI some as saturation; "oversold"; promises of support or provider action; cancel, refund or "move elsewhere"; prices or product names; the disk type from the rotational flag; a benchmark result as the hardware ceiling; failure counters or peak usage as memory pressure.

## Budgets

Every number is a constant in `PerfLimits.php`, provisional until measured on a customer server (the measured cost of each verb goes into the ADR-0035 addendum). A test checks that each constant is in this table with its value.

| constant | value | what |
|---|---|---|
| `KILL_AFTER_S` | 2 | seconds between TERM and KILL for every program |
| `SCAN_NICE` | 19 | CPU niceness of every program |
| `MEASURE_IO_LEVEL` | 7 | best-effort I/O level of probes |
| `CHECK_WINDOW_S` | 2 | seconds between the two samples of `check` |
| `CHECK_MAX_RECORDS` | 10 | records per `check` |
| `USL_TIMEOUT_S` | 10 | userShowLimits time limit |
| `MEM_TOP_GROUPS` | 5 | app groups in memory by app |
| `PSS_TOP` | 5 | largest processes whose shared memory is split out |
| `NET_QUICK_COUNT` | 20 | mtr cycles by default (quick indication) |
| `NET_WIKI_COUNT` | 1000 | mtr cycles of the wiki procedure |
| `NET_COUNT_MAX` | 1000 | highest `--count` |
| `NET_ETA_NOTICE_S` | 10 | runs longer than this print their ETA first |
| `DISK_WRITES` | 16 | synced writes of the light probe |
| `DISK_WRITE_BS` | 4096 | bytes per write |
| `DISK_READS` | 64 | direct random reads of the light probe |
| `DISK_READ_BS` | 4096 | bytes per read |
| `DISK_READ_FILE_MIN` | 67108864 | smallest own file to read from (64 MiB) |
| `DISK_FIND_S` | 5 | seconds to look for that file |
| `DISK_DEADLINE_S` | 20 | seconds for the whole light probe |
| `DISK_QUOTA_FACTOR` | 2 | free quota needed, times the probe's bytes |
| `PROBE_IO_MAX_BYTES` | 327680 | light probe I/O ceiling (320 KiB) |
| `HEAVY_DISK_READ_S` | 30 | seconds of the disk-read test |
| `HEAVY_DISK_READ_MAX` | 1073741824 | bytes the disk-read test may read (1 GiB) |
| `HEAVY_DISK_WRITE_BYTES` | 268435456 | bytes of the disk-write test (256 MiB, not on shared servers) |
| `HEAVY_DOWNLOAD_MAX` | 536870912 | bytes of the download test (512 MiB) |
| `HEAVY_DOWNLOAD_S` | 60 | seconds of the download test |
| `HEAVY_IPERF_S` | 10 | iperf3 seconds each way |
| `HEAVY_COOLDOWN_S` | 3600 | seconds before the same test runs again (a courtesy, not a security limit) |
| `HEAVY_TRAFFIC_MARGIN` | 0.1 | largest share of the plan traffic left that a test may use |
| `PSI_IO_FULL_AVG10` | 15 | io full avg10 that counts as a disk pressure burst |
| `PSI_IO_FULL_AVG60` | 10 | io full avg60 that counts as sustained disk pressure |
| `PSI_MEM_FULL_AVG10` | 10 | memory full avg10 that refuses a heavy test |
| `PSI_CPU_SOME_AVG10` | 80 | cpu some avg10 that refuses a heavy test |
| `RWND_LIMITED_PCT` | 50 | share of a session's busy time limited by the receiver's window |
| `BDP_RTT_MS` | 50 | round trip from which one stream is window-bound |
| `LINK_SPEED_LOW_MBIT` | 100 | interface speed at or below which the link is the limit |
| `MEM_ANON_PCT` | 90 | memory in use (not cache) against the limit, in both samples |
| `CPU_THROTTLE_PCT` | 20 | share of CPU periods throttled |
| `STEAL_PCT` | 5 | CPU steal share |
| `NEAR_CAP_PCT` | 80 | share of a cap a measured rate must reach to count as at the cap |
| `TRAFFIC_NEAR_PCT` | 90 | share of the plan traffic limit used that counts as near it |
| `MTR_GRACE_S` | 10 | seconds mtr may wait for late replies after its last cycle |
| `TRACEROUTE_S` | 40 | deadline for the traceroute fallback |
| `PING_GRACE_S` | 5 | seconds after the last ping for its reply |
| `NEAR_HOPS` | 3 | loss starting within this many hops of the server is a ticket |
| `DD_MAX_S` | 10 | part of the disk budget the synced writes may use |
| `ALLOCATED_SHARE` | 0.9 | share of a file's size that must be on disk for the read probe (not sparse) |
| `SAMPLE_INTERVAL_MIN` | 15 | minutes between scheduled samples |
| `SAMPLE_RUNS` | 96 | samples per scheduled job (24 hours) |
| `SAMPLES_HOURS_DEFAULT` | 24 | `samples --hours` default |
| `SAMPLES_HOURS_MAX` | 720 | highest `samples --hours` |
