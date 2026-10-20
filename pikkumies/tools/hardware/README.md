# hardware

Tools for the physical machine Pikkumies runs on, for a dedicated server where the agent has root: `drives` (disk health, self-tests and write-pass verification). Later units for memory or network cards would sit here too. Each unit's USAGE.md lists its own verbs; the category has no shared verb table ([ADR-0015](../../../docs/adr/0015-tool-units-one-skill-per-tool.md)). The rules the agent follows are in `DOCTRINE.md`; the decision behind them is [ADR-0094](../../../docs/adr/0094-drive-health-screen-verdict-and-grade-on-one-server.md).

This is not the `storage` category: storage is the account's space and quota and never needs root; hardware is the disks themselves and always does.

## Safety rules (in the code and in the skills)

1. Every verb needs root (`root.available` in system-status). Without it each verb refuses with a hint; on a shared or managed server the provider watches the disks.
2. Reading never wakes a sleeping disk (`smartctl -n standby`).
3. Nothing writes to a disk that is mounted, used as swap, held by md, LVM, dm-crypt or multipath (sysfs `holders/`), named in `/proc/mdstat`, open by any process (`fuser`), or carrying an array or volume signature (probed with `blkid -p`). `badblocks` itself refuses only mounted devices, so the tool checks the rest (badblocks(8), `-f`).
4. A disk with any other filesystem or partition table needs `--with-data`, which the delete guard sends to the safety check: an interrupted `-n` pass can lose the block under test.
5. Write passes are `badblocks -n` (non-destructive read-write) only; a full-disk `-w` pass and `-f` are never run. `--fix-unreadable` writes only the blocks a completed pass could not read.
6. Only `smartctl`, `badblocks`, `blkid -p` and `fuser -s` run as root; parsing, state and every file write happen as the account.
7. The guard refuses raw `badblocks -w`, `hdparm` writes, erases and trims, `blkdiscard`, `nvme format|sanitize|write-zeroes`, `sg_format`, `sg_sanitize`, `mkswap` on a device, output redirected, copied or tee'd onto a disk, and md `repair` (into `sync_action` or `mdadm --action=repair`).

## References

- smartmontools: smartctl(8), the `-j` JSON output, `-n standby`, `-t short|long` (https://www.smartmontools.org/).
- e2fsprogs: badblocks(8), the `-n`, `-w`, `-b`, `-f` and `-o` options and the `last_block first_block` arguments (https://man7.org/linux/man-pages/man8/badblocks.8.html).
- Linux md: `md/sync_action` values `check` and `repair` (https://docs.kernel.org/admin-guide/md.html).
