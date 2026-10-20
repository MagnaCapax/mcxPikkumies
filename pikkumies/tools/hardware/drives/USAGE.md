# drives

Health of the disks in this machine. Needs root. Counters are a screen; only a completed write pass gives a verdict.

| verb | arguments | changes | what it does |
|---|---|---|---|
| smart | `[--serial S]` | no | One record per disk, worst screen first: `screen` (🔴 health FAILED, 🟠 heavy: realloc ≥ 1000, pending ≥ 100 or uncorrectable ≥ 100, 🟡 any counter not zero, 🟢 every reported counter zero, ⚪ nothing measured), `device`, `serial`, `wwn`, `bytes`, `model`, `protocol` (sata, sas, nvme), `rotational`, `role` (array-member, holder, mounted, swap, array-signature, data, blank) and `role_detail`, `health` (the flag), `poh`, `realloc` (SAS: grown defects), `pending`, `uncorrectable` (SAS: uncorrected errors, NVMe: media errors), `reported_unc`, `crc`, `error_log`, `failing_now`, `selftest_last` (the drive's own log), `verify` (a pass running: HOLD with progress), `verdict` and `verdict_reason`, `grade` (A, B, C or BIN only after a completed write pass; otherwise `untested`, or `undetermined` while the verdict is HOLD or INCOMPLETE) and `grade_reason`, `next`. A sleeping disk is not woken. A field the drive does not report is null. When a verdict or a letter grade lands, a `lesson` record follows; a drive read while asleep keeps its last grade. |
| selftest | `<serial> short\|long` | loads | Starts the drive's own self-test (`smartctl -t`). Refused in a VM, while an md array resyncs or checks, or while a test runs. The result is `selftest_last` on a later `smart`. |
| verify | `<serial> [--with-data] [--fix-unreadable]` | yes | Starts a detached `badblocks -n` write pass on a standalone spinning disk (HOLD until it completes; `smart` shows progress and then the verdict). A disk with a filesystem or partition table needs `--with-data`. `--fix-unreadable --with-data` rewrites only the blocks the last completed pass could not read. |

```sh
D=~/pikkumies/tools/hardware/drives/drives.php
php $D smart --human
php $D smart --serial ZA1234AB
php $D selftest ZA1234AB short
php $D selftest ZA1234AB long
php $D verify ZA1234AB
php $D verify ZA1234AB --with-data
php $D verify ZA1234AB --fix-unreadable --with-data
```

- Without root every verb refuses with a hint. Inside a VM `selftest` and `verify` refuse: the disks belong to the host.
- `verify` refuses an array member, a disk in use (mounted, swap, LVM, dm-crypt, multipath, or open by any process: `fuser` as root), a disk with an array signature and no running array (signatures probed as root with `blkid -p`), flash (SSD, NVMe) and a disk with a pass already running. The role is checked again right before the pass starts. The refusal names the right write pass instead (the array's own `check` scrub for a member).
- Block size: 4096 above 2 TB, 8192 from 16 TB (the badblocks block counter is 32-bit). A pass whose bad-block list passes 1 MiB is stopped (a drive that dropped off the bus lists every block) and ends INCOMPLETE.
- State is kept in `~/pikkumies/local/drives/<SERIAL>/` (latest SMART document, events, pass files, lesson bodies); the numeric history goes to the telemetry store when this Pikkumies has it.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the table.
