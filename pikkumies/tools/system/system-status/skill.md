---
name: system-status
description: Machine snapshot (OS, host, root, own running apps, CPU, memory, disk, load) before diagnosing anything.
---

# System status

Run the `show` verb and read the record before you change anything.

- `root.available` tells you whether you may use root at all. If it is false, do not try sudo.
- `host` says where you are: `pmss` means Pulsed Media's platform; other values name the seedbox host or `generic`.
- `apps` lists only processes of the current user.
- A full quota is the most common cause of services failing to start; check `resources.disk.quota_free_bytes` and `over_quota` first. `fs_free_bytes` is the whole filesystem (on shared servers far more than the user may use); when it is near 0 the server is full, which is for support, not the user.
