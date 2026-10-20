---
name: mdraid
description: "Linux software RAID (md): array status, disks, build, recover a degraded or failed array, scrub and tune, on a machine with root. The only way to run mdadm writes, mkfs or fsck repair here."
---

# Software RAID (md)

Use this for anything about mdadm arrays on a machine where root is available (check `root.available` with system-status; without root only `status` works). Run it as `php ~/pikkumies/tools/server-admin/mdraid/mdraid.php ...`, never `sudo php`: as uid 0 it runs each program directly, with passwordless sudo it runs each as `sudo -n` itself. Raw `mdadm` writes, `mkfs`/`mke2fs`, `wipefs`, `fsck` repair and writes to `/sys/block/md*/md/` are refused by the guard; this tool does them after checking the live device state.

1. **Read first.** `status` (arrays, members, superblocks, the N-1 verdict, mdadm.conf, mdmonitor, kernel line), and before any build or new member `disks`. Say what you found before you change anything.
2. **Build** (`create`): pick the level and profile from the table below, run `create ... --plan`, then the same line without `--plan`. A disk that holds data is refused with what is on it: tell the user in one line and add its serial to `--destroy` only when they want it destroyed. After create: `tune --profile <same>`, measure on the empty array, and reboot once before data goes on; `status` must show the array under its name with the tune values.
3. **Recover** (degraded, failed, inactive): follow doctrine/SOP/recover-a-degraded-or-failed-array.md. In short: `status` first; a host or cable fault before a disk verdict; no disk verdict from SMART counts; `re-add` the same disk first, `add` or `replace` a new one second; `assemble` (read-only) when the array is stopped, mount read-only and look, then `assemble mdN --writable`. Two or more members behind: stop, keep the disks untouched, tell the owner and offer Pulsed Media support or a data recovery specialist.
4. **Maintain**: `check mdN` starts a scrub (refused while degraded); `idle mdN` stops a check or resync before a reboot. The OS's own scrub timers stay as they are.
5. **Verify**: every write verb reads the result back and reports `verified`; quote it.

| need | level | profile |
|---|---|---|
| media, backups, big files | RAID5 (3+ disks) or RAID10 | big-file |
| mixed, "just make it work" | RAID1 (2 disks), RAID10 (4+), RAID5 (3+) | typical |
| many small files, databases, VMs | RAID10 or RAID1 | small-file |
| speed only, data replaceable | RAID0, with `--no-redundancy`, after saying one failed disk loses everything | any |

Never: `--create` to recover an array (recovery is `assemble`); call a disk blank from `blkid` or `wipefs` alone (`disks` samples it); name a disk by `sdX`; repair a filesystem on a suspect array (`e2fsck -n` only, then tell the user); recommend RAID6 or ZFS for random writes; touch boot or root arrays with this tool (it builds data arrays on whole disks only).
