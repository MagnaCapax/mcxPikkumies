---
name: system-status
description: Machine snapshot (OS, host, root, own running apps, CPU, memory, disk, load) before diagnosing anything.
---

# System status

Run the `show` verb and read the record before you change anything.

- `root.available` tells you whether you may use root at all. If it is false, do not try sudo.
- `family` says where you are: `pmss` means Pulsed Media's platform; other values name the seedbox host or `generic`. `stack` names a self-hosted installer (`swizzin`, `quickbox`, `saltbox`) when one is detected, or `none`.
- `apps` lists only processes of the current user.
- `limits` lists CPU/memory/disk with whichever is closest to its own cap first — read that one when asked "can we increase performance" or similar; each carries its own `source` so you know whether it is this account's real ceiling or (`source: host`, dedicated accounts only) the whole machine.
- When something fails, run `logs --grep <word from the error>` (for example `logs --grep error` or the app's name): it searches this account's own log files that this host is known to keep and returns the matching lines. `logs` alone lists those files.
- A full quota is the most common cause of services failing to start; check `limits.disk.value.quota_free_bytes` and `over_quota` first. `fs_free_bytes` is the whole filesystem (on shared servers far more than the user may use); when it is near 0 the server is full, which is for support, not the user.
