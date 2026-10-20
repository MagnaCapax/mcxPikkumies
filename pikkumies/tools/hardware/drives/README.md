# drives

Disk health for the machine Pikkumies runs on, when the agent has root (a dedicated server). It reads SMART, starts the drives' own self-tests, runs a write pass on a standalone spinning disk, and keeps three things apart: the **screen** (counters and flags, which only decide what to test next), the **verdict** (only from a completed write pass) and the **grade** (only for a qualified drive). Verbs are in USAGE.md, the rules in `../DOCTRINE.md`, the decision in [ADR-0094](../../../../docs/adr/0094-drive-health-screen-verdict-and-grade-on-one-server.md).

## What it reads

Disks come from `lsblk -J -b` (as the account: names, serials, WWN, size, rotational, signatures, mount points; no disk is touched). Each disk's role comes from `/proc/mdstat`, sysfs `holders/`, `/proc/self/mounts`, `/proc/swaps` and the signatures lsblk reports. SMART comes from `smartctl -j -x -n standby <device>` as root (smartmontools 7 JSON):

| field | SATA (ATA) | SAS (SCSI) | NVMe |
|---|---|---|---|
| health | `smart_status.passed` | `smart_status.passed` | `smart_status.passed` |
| realloc | attribute 5 raw | `scsi_grown_defect_list` | null |
| pending | attribute 197 raw | null | null |
| uncorrectable | attribute 198 raw | read + write + verify `total_uncorrected_errors` | `media_errors` |
| reported_unc | attribute 187 raw | null | null |
| crc | attribute 199 raw | null | null |
| error_log | `ata_smart_error_log` count | null | null |
| failing_now | attributes with `when_failed` "now" | | `critical_warning` (separate field) |
| selftest_last | `ata_smart_self_test_log` newest entry; in progress from `ata_smart_data.self_test.status` | `scsi_self_test_0` | `nvme_self_test_log` |

A raw value is the leading number of smartctl's `raw.string` (smartctl applies the vendor's raw format there). A field a drive does not report stays null: unknown is never shown as zero, and a drive with an unknown field cannot be shown to be Grade A.

## The write pass

`verify` runs, detached and as root, `badblocks -nsv [-b 4096|8192] /dev/<disk>`: every block is read, written with test patterns, compared, and written back (badblocks(8), non-destructive read-write mode). Just before it starts, the disk's signatures are probed as root with `blkid -p` (the udev view lsblk gives can miss an unassembled array member), the role is checked again, and `fuser -s` as root checks that no process has the disk or a partition open (a VM, an export or a raw-device program would lose writes made during the pass). It is started through `flock -n` on the drive's `pass.lock` (one pass per disk, and "running" survives pid reuse) and `sh -c` as the account: badblocks' stdout, the list of bad blocks, goes through `head -c` into `pass.bb` (a drive that drops off the bus would list every block; the list stops at 1 MiB and the pass dies on the closed pipe), its stderr into `pass.log`, its exit code into `pass.exit`. Never `-o` (root would write the file), never `-f`, never `-w` on a whole disk.

From `pass.log` the tool reads the progress line (`12.34% done, ... (R/W/C errors)`) and the summary (`Pass completed, N bad blocks found. (R/W/C errors)`: read, write and compare errors). `-n` skips a block it cannot read and never writes it, so a pass with read errors only proves nothing about writing those blocks; `--fix-unreadable` then runs `badblocks -wv -b <same size> <device> <block> <block>` once per listed block (at most 4096).

The verdict is decided by the next `smart` after the pass ends, from the summary and from SMART samples the tool takes before the pass and right after it:

| completed pass | verdict |
|---|---|
| write or compare errors | FAILED (media) |
| pending did not fall and more than 100 sectors were reallocated | FAILED (media) |
| this pass and the previous one (within 30 days) each consumed spares on defects | FAILED (media) |
| a second pass after "regrow" reallocated again | FAILED (media) |
| read errors only | HOLD: `--fix-unreadable`, then a full pass |
| pending cleared but 100 or more reallocated during the pass | HOLD: one more pass |
| no errors, pending zero or falling, fewer than 100 reallocated | RECOVERED |
| no errors but pending did not move | HOLD: another pass |
| ended without "Pass completed", or the list passed 1 MiB, or the drive is gone | INCOMPLETE (link or cable), unless it stalled on write errors with the drive present: FAILED |

Independent of a pass: 5 or more consecutive failed long self-tests at one LBA over at least two weeks of power-on time (the drive's own log, SATA), or reported-uncorrectable errors rising more than 10 an hour in service after a RECOVERED pass (from the history; fewer than two samples is `insufficient_history`, no verdict), give FAILED (media).

## Grades

A letter only after a completed write pass: RECOVERED gives A, B or C, FAILED gives BIN. Before that the grade is `untested` (no pass yet, however clean or new the drive) or `undetermined` (HOLD or INCOMPLETE): SMART counters and self-tests are reported by the drive itself and never stand in for the pass. A drive read while asleep keeps its last grade (nothing was measured, so nothing changed).

- **A**: every counter, the error log and the self-test log are measured and clean (SAS: grown defects and uncorrected errors zero; NVMe: media errors and critical warning zero).
- **B**: anything on the SMART log below the heavy line, CRC-only included, or a counter the drive does not report.
- **C**: heavy wear: realloc 1000 or more, or uncorrectable 100 or more.
- **BIN**: verdict FAILED.

The C line and the other thresholds are defaults chosen for this tool, not standards; they are constants in `DrivesRules.php` and stated in the ADR. A grade is a quality tier, not a verdict, and says nothing about what to do with a drive.

## History and lessons

State is per drive in `~/pikkumies/local/drives/<SERIAL>/`: `smart.json` (the latest document), `events.jsonl` (pass start and end with the before and after samples, self-test starts, grades), the pass files, and lesson bodies. One numeric sample per `smart` call (power-on hours and the counters) goes to the shared telemetry store of the `telemetry` unit (`tools/system/telemetry`), under the source `drive-<serial>`, when that unit is part of this Pikkumies; without it, the history rule above answers `insufficient_history`. When a verdict or grade lands, `smart` prints a `lesson` record with the `save` command for the memory tool; the tool never writes memory itself.

## Not here

Full-disk `-w` burn-in, writes to a live array member, array re-add, scrub or repair automation, reseating and cabling, hardware RAID controllers, scheduled collection or alarms, more than one machine.
