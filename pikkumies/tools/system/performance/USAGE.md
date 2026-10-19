# performance

Why is it slow, measured from this account. Light by default: `check`, `net` and `disk` cost a few seconds, a few packets and at most 320 KiB of disk I/O. Load tests are `heavy` only, run when the user asked for a speed test or benchmark. It reads only your own account. Nothing leaves the machine except the probe packets and test traffic the user asked for.

| verb | arguments | changes | what it does |
|---|---|---|---|
| check | `[--symptom slow-download\|slow-upload\|capped\|stalls\|torrents\|lag\|everything] [--share [--with-ip]] [--tick]` | no | At most 10 records: account (limits and use), cpu, memory (by app), io, host (pressure, steal, disk wait, maintenance), sessions (your own transfers), next_checks, verdict (class, evidence, negation, disproved_by, next_step), skipped, cost. About 2 s. `--symptom` only orders the verdicts; every area always runs. `--share` is a view to paste into a ticket (no names, no addresses); `--with-ip` adds your connection's address and the route hops, only when the user asks. `--tick` writes one sample and prints nothing (the scheduled job). |
| net | `--target ssh\|IP\|HOST [--count N]` | no | Route test with `mtr --report -n` (traceroute, then ping, when mtr cannot run): one record per hop (loss, average, deviation, worst; no addresses), a reading, the method, and how the user runs the reverse direction. `ssh` = the connection you are on. N 1-1000, default 20 (a quick indication, labelled so); 1000 is the wiki procedure, about 17 minutes. The ETA is printed first when the run takes over 10 s. |
| disk | `[--no-read]` | no | Light probe: 16 synced 4 KiB writes in scratch (commit latency), 64 direct 4 KiB random reads of one of your own files of 64 MiB or more (fio, read-only), the server's disk wait at the same moment, and the previous run. No absolute verdict. Refused when your quota is nearly full. `--no-read` skips the reads. |
| heavy | `--test disk-read\|disk-write\|download --url U\|iperf3 --target H\|speedtest` | loads | One load test, when the user asked for one: disk-read (fio, 30 s, at most 1 GiB, read-only), disk-write (256 MiB, only when the server is not shared), download (one stream, counted and thrown away, at most 512 MiB or 60 s), iperf3 (installed only, 10 s each way), speedtest (the installed binary only). Refused when the server is under pressure, within an hour of the same test, or when it would use more than 10% of the plan traffic left. The result is your share now, not the server's ceiling. |
| samples | `[--hours N]` | no | Per-hour medians of what the scheduled `check --tick` recorded (server pressure, busy, steal, disk wait, your throttling), split where the server changed, with the time of the last sample and any gap. N 1-720, default 24. |

```sh
php ~/pikkumies/tools/system/performance/performance.php check --symptom slow-download
php ~/pikkumies/tools/system/performance/performance.php net --target ssh            # quick: 20 cycles
php ~/pikkumies/tools/system/performance/performance.php net --target ssh --count 1000
php ~/pikkumies/tools/system/performance/performance.php disk --human
php ~/pikkumies/tools/system/performance/performance.php heavy --test disk-read     # only when the user asked for a test
php ~/pikkumies/tools/system/performance/performance.php samples --hours 24
```

- One run at a time per account: a second one says "another performance run is in progress"; a scheduled `check --tick` that finds a run going skips its sample silently.
- A bad argument exits 2 and runs nothing; a verb this version does not have yet says "not built yet" (exit 1).
- A value the tool could not read is `null` (unknown), never 0, and the check it belongs to is listed under `skipped` with the reason.
- Sampling over a day is a Hermes job the agent creates on the user's request: no_agent, script `pikkumies-performance-sample.sh`, `every 15m`, repeat 96 (24 hours). Remove it with the cronjob tool.
