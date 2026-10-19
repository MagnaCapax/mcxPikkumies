---
name: system-status
description: "Is the seedbox down: web panel or ruTorrent not loading, 502, a torrent client not running (seedbox ei toimi, ei aukea); this account's own figures (disk space left, quota, memory, traffic, limits; levytila, kiintiö, muisti, liikenne, rajat) and a machine snapshot (OS, host, root, own running apps) before diagnosing anything; slowness and whether the server is busy go to performance."
---

# System status

**Down, not loading, 502, "seedbox ei toimi"**: run `health` once and answer from its `summary` and `findings`; each finding's `next_step` (and `doc` when given) is what the user does. You are running on this server, so it is up; `not_shown` is what this check cannot see (the user's own network). A client that `started ... while the machine kept running` restarted on its own; the server did not.

**How much is left** (disk, quota, memory, traffic, processes, CPU; in Finnish levytila, kiintiö, muisti, liikenne, rajat): run `account` once and answer from it. On PMSS it is the platform's own live figures for this account (userShowLimits): `disk` (`used`, `soft` quota, `hard` ceiling, `left` to the soft quota, files, `age_s` of the quota snapshot), `memory` (`used` against `limit`, pressure, OOM kills), `traffic` (upload in the last 30 days against `limit`, `left`, inbound, bonus, `cap_mbit`), `processes`, `cpu`, PMSS and Debian versions, bonus, own running apps. These are live figures: no memory search first, and never `df`, `free`, `du` or a wiki page for them. A key listed in `unlimited` has no limit; any other null is unknown, so say so. Convert bytes for the user (1 GiB = 1073741824 bytes). The user's own view of the same figures is the Info tab of their panel (`panel` gives the address as a template; never fill in a name you have not been given). Off PMSS every figure is null: read `limits` from `show` instead.

Run the `show` verb and read the record before you change anything.

- `root.available` tells you whether you may use root at all. If it is false, do not try sudo.
- `family` says where you are: `pmss` means Pulsed Media's platform; other values name the seedbox host or `generic`. `stack` names a self-hosted installer (`swizzin`, `quickbox`, `saltbox`) when one is detected, or `none`.
- `apps` lists only processes of the current user, read now.
- `limits` lists CPU/memory/disk with whichever is closest to its own cap first — read that one when asked "can we increase performance" or similar; each carries its own `source` so you know whether it is this account's real ceiling or (`source: host`, dedicated accounts only) the whole machine.
- `server_totals` are the whole server's CPUs, memory and /home size (`scope` says whether other accounts share it); they are never this account's limits.
- When something fails, run `logs --grep <word from the error>` (for example `logs --grep error` or the app's name): it searches this account's own log files that this host is known to keep and returns the matching lines. `logs` alone lists those files.
- A full quota is the most common cause of services failing to start; check `limits.disk.value.quota_free_bytes` and `over_quota` first. `fs_free_bytes` is the whole filesystem (on shared servers far more than the user may use); when it is near 0 the server is full, which is for support, not the user.
