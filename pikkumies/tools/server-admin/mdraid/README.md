# mdraid

Linux software RAID (md) for the owner of a machine where Pikkumies has root ([ADR-0004](../../../../docs/adr/0004-provider-key-and-root-detection.md)): read the arrays and disks, build a data array, recover a degraded or failed one, scrub, and apply a performance profile. It lives in `server-admin`, so `root.disabled` hides it; on a shared seedbox without root it does nothing.

**Root, uid 0 or sudo.** The tool asks `Detect::rootAccess` (the same check as `root.available`): running as uid 0 with full capabilities it runs each program directly and reads and writes files in PHP; with passwordless sudo it runs each program as `sudo -n <program>`, writes a root file by copying a private temp file (0600) with `sudo -n cp`, and reads disk samples with `sudo -n dd`, so this PHP never runs as root itself; with neither, every verb but `status` is refused with the reason. The agent always runs `php .../mdraid.php`, never `sudo php`. Decisions: [ADR-0095](../../../../docs/adr/0095-md-raid-build-recover-and-tune-through-one-tool.md).

## Why one tool

Typed in a terminal, `mdadm --create`, `--zero-superblock`, `--fail`/`--remove`/`--add`, `--assemble --force`, `mkfs`, `mke2fs`, `wipefs`, `fsck` repair and writes into `/sys/block/md*/md/` are refused by the guard (`hooks/guard/guard-destructive/GuardDisk.php`). This tool runs them only after it has read the live state in the same process:

- **Disks by by-id or serial**, never `sdX` (kernel names change between boots). Every device path handed to a program must be `/dev/mdN` or an entry of the live `/dev/disk/by-id` list at that moment (`MdRun.php`).
- **In use is never taken:** a disk or partition that is mounted, swap, in fstab or crypttab, held by an md or dm device (an inactive array assembled by itself from a used disk too: stop that array first, `mdadm --stop` stays allowed), open by a process, a device of a mounted btrfs, the backing file of a loop device, or whose superblock belongs to an array in `/proc/mdstat` (running or inactive), in mdadm.conf (`/etc/mdadm/` or `/etc/`), or to an array other disks still carry, unless all of them are destroyed in the same command (`MdUse.php`). Right before anything is written to a disk the check runs again, and a disk with data is wiped only after an exclusive open of it succeeded. Every write is logged to `/var/lib/pikkumies-mdraid/actions.log`.
- **No signature is not empty.** `blkid -p` and `wipefs -n` find signatures; the sampler reads 64 KiB at about 16 fixed offsets and reports only how many bytes were not zero. It is a sample, not a scan: a few kilobytes between two offsets can be missed, so a blank verdict means "nothing found at the start, the md and GPT areas, the end and 10 points between", never "proven empty". A disk with partitions, a signature, an md superblock or any non-zero sample holds data and is used only with its serial in `--destroy`; the guard sends such a line to the safety check, and where the check cannot run (a scheduled job with no record of the user's words, an agent started by an agent) it is refused and reported.
- **A record first:** before any write the `--examine` of every member is saved under `/var/lib/pikkumies-mdraid/` (root only, 0700). Root writes go only to `/etc/mdadm/`, `/etc/systemd/system/pikkumies-mdraid-*`, `/var/lib/pikkumies-mdraid/` and three md sysfs files, never into `~/pikkumies`.
- **Recovery follows the members' superblocks:** `assemble` takes the group at the newest Events, read-only; one stale member is left out for a later re-add; two or more behind is refused. `re-add` only returns a member to its own empty slot of a running array, never on a failed array (a re-add there can turn a member into a spare).
- **The kernel line:** Linux 6.12.13 and its backports moved md bitmap accounting into a function that rounded with a power-of-two mask, so on RAID4/5/6 with a bitmap and 3, 5, 6, 7 ... data disks a re-add or a crash resync can skip writes. Fixed by "md/raid5: round bitmap stripes with sector division" (upstream 17ea021ae749): affected 6.6.79-6.6.156, 6.12.13-6.12.109, 6.13.2 to 6.17, 6.18.0-6.18.51, 6.19, 7.0, 7.1, 7.2.0-7.2.5; fixed from 6.6.157, 6.12.110, 6.18.52, 7.2.6 and 7.3 (checked against kernel.org stable tags on 2026-10-20). `re-add` is refused on such an array until a fixed kernel runs; `status` shows the line.

## Profiles

Data in `MdPlan.php`. Each value is labelled for what it is: a measured fleet value, or a default.

| profile | for | level guidance | chunk | stripe_cache_size (RAID5) | md read_ahead_kb | mkfs.ext4 | mount | label |
|---|---|---|---|---|---|---|---|---|
| `big-file` | media, backups, large sequential files | RAID5 (or RAID10) | 2048K | 32768 while every parity array's pinned cache stays under 5% of RAM, else 4096 | 4096 | `-m 0.1`, aligned | `noatime,commit=60` | Pulsed Media's fleet standard; the memory rule is a default |
| `typical` | mixed use | RAID1 (2 disks), RAID10 (4+), RAID5 (3+) | 512K (mdadm's default) | 4096 | kernel default | aligned | `noatime` | default, not measured |
| `small-file` | many small files, databases, VMs, random writes | RAID10 or RAID1 | 512K | 4096 | kernel default | `-T news` (more inodes), aligned | `noatime` | default, not measured |

- **Pinned memory.** `stripe_cache_size` is a floor the kernel allocates up front, one page per member per stripe (raid5.c `grow_buffers`, `raid5_set_cache_size`; writable 17 to 32768, default 256, `Documentation/admin-guide/md.rst`): 32768 on 4 members pins 512 MiB. `create --plan`, `tune --plan` and `status` print the MiB.
- **Aligned** means `-E stride=<chunk/4 KiB>,stripe_width=<stride x data disks>` (mke2fs(8)); RAID1 has no chunk.
- **Chunk is not the small-file lever on md.** Parity read-modify-write works per page, so the level (mirrors) and the inode density are what matter. RAID6 and ZFS are not offered for their write cost (doctrine/antipatterns.md, `zfs-or-double-parity`).
- **Measuring.** Measure on the empty array before data goes on, and report it as a measurement of this machine, never as an optimum. `performance heavy --test disk-read` reads a file of the account's own (fio, direct I/O, at most 1 GiB, read-only), so it measures the new array only when that file lives on it; `disk-write` writes 256 MiB with dd. Read those flags before quoting a number.
- **mount.** `create` prints an fstab line with the filesystem UUID and `nofail`; where to mount it is the owner's choice.

## Not here

Boot or root arrays (ESPs, grub on two disks), reshape (`--grow` of level, chunk or count), superblock surgery and `--assume-clean` (also through `--write-zeroes`), `repair` to `sync_action` and `active` to `array_state`, ZFS, btrfs, LVM, hardware RAID, bcache, RAID6, the OS's own scrub timers, mail setup for mdmonitor, arrays on partitions for the write verbs, and non-Debian write verbs (create and tune need `update-initramfs` and systemd). Behaviour is written from the mdadm, kernel and e2fsprogs documentation, not copied from other projects.
