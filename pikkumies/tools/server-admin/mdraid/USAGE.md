# mdraid usage

Linux software RAID (md) on a machine where Pikkumies has root. Every verb except `status` needs root, and the tool reaches it itself (Detect::rootAccess): as uid 0 it runs each program directly, with passwordless sudo it runs each program as `sudo -n`, otherwise it refuses with the reason. Always run `php .../mdraid.php ...`, never `sudo php`. Disks are named by their `/dev/disk/by-id` name or their serial, never `sdX`. Output is JSON Lines; `--human` for people.

| verb | arguments | changes | what it does |
|---|---|---|---|
| status | `[--array mdN] [--profile P]` | no | Every array: level, state, rebuild progress, members by by-id and serial, each member's superblock (Events, Update Time, Role) with the N-1 verdict and the stale member named, mdadm.conf against the running arrays, mdmonitor and MAILADDR, the kernel line for parity arrays with a bitmap, and the tunables (against a profile with `--profile`). Works without root, with less. |
| disks | | no | Every whole disk: by-id name, serial, size, partitions, signatures, an md superblock and the array it names, mounts, holders, fstab/crypttab/swap references, sampled reads as counts of non-zero bytes, and a verdict: `blank`, `holds-data` (needs `--destroy`) or `in-use` (never taken). |
| create | `--level 1\|5\|10\|0 --disks <id,...> --profile big-file\|typical\|small-file [--array mdN] [--plan] [--destroy <serial,...>] [--no-redundancy] [--accept-size-loss]` | deletes | Builds a data array: the disk checks again, each disk's `--examine` saved, `--destroy` disks wiped, `mdadm --create` (metadata 1.2, internal bitmap when redundant), `mkfs.ext4 -n` then mkfs with stride and stripe_width, this array's line in mdadm.conf, `update-initramfs -u`, read back. `--plan` prints all of it and stops. RAID0 needs `--no-redundancy`. |
| add | `mdN <disk> [--destroy <serial>]` | deletes | Puts a new or wiped disk into a degraded array (the same disk checks as create). |
| replace | `mdN <old member> <new disk> [--destroy <serial>]` | deletes | `mdadm --replace --with`: redundancy stays while the copy runs. A member already marked faulty is removed and the new disk added. Refused on a degraded array (fill the slot first). |
| re-add | `mdN <member>` | yes | Returns a former member to its own empty slot of a running, degraded array. Refused on a failed array, and on parity RAID with a bitmap and a non-power-of-two data-disk count while the kernel lacks the bitmap rounding fix. |
| assemble | `mdN [--writable]` | yes | Starts a stopped array read-only from the members at the newest Events (one stale member is left out; two or more behind is refused). `--writable` switches an assembled read-only array to read-write, after the data was looked at. |
| check | `mdN` | yes | Starts a scrub (`sync_action` check). Refused on a degraded or busy array. |
| idle | `mdN` | yes | Stops a running check or resync, for example before a reboot. |
| tune | `--profile P [--array mdN] [--plan]` | yes | Writes the profile's stripe cache and read-ahead into one oneshot systemd unit, applies them now and reads them back. |

```sh
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php status
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php status --array md0 --profile big-file
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php disks
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php create --level 5 --disks ata-EXAMPLE_A,ata-EXAMPLE_B,ata-EXAMPLE_C --profile big-file --destroy EXAMPLEB
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php add md0 ata-EXAMPLE_D --destroy EXAMPLED
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php replace md0 ata-EXAMPLE_B ata-EXAMPLE_D --destroy EXAMPLED
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php re-add md0 ata-EXAMPLE_B
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php assemble md0
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php assemble md0 --writable
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php check md0
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php idle md0
php ~/pikkumies/tools/server-admin/mdraid/mdraid.php tune --profile big-file --array md0
```

A build on blank disks needs no `--destroy`; run `create ... --plan` first to see the exact commands and what each disk holds. A `--destroy` serial must belong to a selected disk, and a disk that holds data is refused until its serial is there; the refusal names what was found, so the one line to the user carries the evidence. The guard sends any line with `--destroy` to the safety check before it runs ([ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md), [ADR-0095](../../../../docs/adr/0095-md-raid-build-recover-and-tune-through-one-tool.md), [ADR-0101](../../../../docs/adr/0101-refuse-and-report-never-ask.md)); where the check cannot run (a scheduled job with no record of the user's words, an agent started by an agent) it is refused and reported.
