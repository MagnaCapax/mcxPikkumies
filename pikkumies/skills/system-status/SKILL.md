---
name: system-status
description: Get a read-only snapshot of this machine (OS, root access, running apps, CPU, memory, disk, load) before diagnosing anything.
---

# System status

Run `php ~/pikkumies/tools/system/status.php` and read the JSON before you change anything.

- `root.available` tells you whether you may use root at all. If it is false, do not try sudo.
- `apps` lists only processes of the current user.
- Low `disk.freeGb` is the most common cause of services failing to start; check it first.
