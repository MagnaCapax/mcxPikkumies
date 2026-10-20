# SOP: Upgrade a Linux host and reboot into a target kernel

Use for an authorized host upgrade or kernel reboot. Read both sections first on Proxmox.
This is a checklist for the existing server-admin skill, not an unattended upgrade command.
Confirm OS-granted root access and announce its use; shared-host downtime needs the owner's explicit yes.
An authorized campaign covers its named hosts/windows; do not ask again between successful units.
Search local lessons with the memory skill first, then check the platform's current official upgrade guide.
Commands below are templates: resolve devices, versions, units and guest IDs from this host before execution.
Use the distro's equivalents outside Debian/systemd; never install GRUB over another boot scheme.
Keep a private journal/backups under `~/pikkumies/local/maintenance/<unique-run>/` (0700, files 0600).
Record authorization, host, targets, timestamps, checks/exit codes, changes and recovery/verification results.
Keep secrets out; Hermes already logs commands, so add evidence/results, not another command logger.

## GENERIC-ALL-LINUX: upgrade + kernel-reboot checks (G1–G9)

**G1 — Rollback first, before packages.** Record `uname -r`, `/etc/os-release`, `lsblk -f`,
`findmnt /`, boot/ESP mounts, `ip -br address`, active services and storage topology.
Back up `/etc` (including package sources, fstab, network, bootloader, mdadm/LVM settings),
boot configuration and mounted ESP contents; verify readable backups and the restore procedure.
Retain the known-good kernel package, image, initrd and selectable bootloader entry; exclude it from autoremove.
Test console/rescue access and identify how to select that fallback. A previous kernel cannot undo a userspace
major upgrade: require restorable system/data backups too, with a recovery route independent of this session.

**G2 — Health gate before packages AND each reboot.** Read `cat /proc/mdstat` raw; redundant arrays
must have all `[U...]`, no `_`, `(F)` or `inactive`; match expected arrays against `lsblk -f`/`mdadm --detail`.
Check `/sys/block/md*/md/degraded` is zero where present. No md arrays is N/A, not a missing-check failure.
Hold on resync/recover/recovery/reshape/repair; never cancel these. A plain `check` goes through G4.
Degraded/suspect storage → P7's read-only recovery router FIRST, on any host; never upgrade/reboot it here.
For ZFS/hardware RAID, use its native health report too. Gate the machine actually rebooting, not a guest's host.
Run `findmnt --verify --verbose`, `findmnt -T /home`, `df -h / /home /boot`, `df -i / /home /boot`;
resolve stale fstab UUIDs, unexpected/missing mounts and insufficient upgrade/backup space before continuing.
Check `sshd -t`. Identify boot devices by mounts/UUID/by-id, never by drive speed or enumeration order.
For BIOS GRUB: `debconf-show grub-pc` must name the right by-id install devices, with no failed install;
run `grub-install --recheck /dev/disk/by-id/BOOT-DISK` on each actual boot disk, then `update-grub`.
For UEFI GRUB: verify the mounted ESP, back it up, record `efibootmgr -v`; use the platform's EFI install
procedure (`grub-install --recheck` where appropriate), then `update-grub`; verify EFI files and BootOrder.
For GRUB, `stat -c %s /boot/grub/grub.cfg` must exceed 1000 and `grub-script-check /boot/grub/grub.cfg`
must pass; inspect fallback files/entry before packages, and TARGET kernel+initrd/entry after installation.
For other loaders use native validation; on managed Proxmox ESPs use `proxmox-boot-tool status` and
`proxmox-boot-tool refresh` instead of an unmanaged GRUB install.

**G3 — Supported package path.** Resolve the target (G8 for security fixes) and coordination (G9) before packages.
Snapshot sources and simulate the documented transaction; investigate errors, holds and removal of boot,
network, SSH or platform packages. No second package manager, lock deletion or bypass of a failed wrapper.
If the platform provides its own upgrade wrapper, use it exactly as documented, one major step at a time;
if it exits silent/failed, update the wrapper itself and retry once, then stop. Let that wrapper own the
upgrade/reboot; prepare all reboot gates beforehand, never launch a competing reboot. Proxmox uses P1/P2;
other hosts use their distro's documented upgrade path. Never substitute a hand-rolled `apt-get dist-upgrade`.
Before apt on passthrough/bcache hosts, prevent unsafe LVM PV scans: ONLY with independently verified
no host PV/VG/LV (mounts, device-mapper and storage config agree), back up `/etc/lvm/lvm.conf`, set
`global_filter = [ "r|.*|" ]` in its existing `devices` section and verify effective config with
`lvmconfig --type current devices/global_filter`. Do not discover this by scanning the suspect devices.
If real LVM exists, preserve required PVs with a platform-supported exclusion filter; never apply reject-all.
After packages: `dpkg --audit` must be clean; check `dkms status` if installed, target modules and
`/boot/vmlinuz-TARGET` + `/boot/initrd.img-TARGET`; fix missing initrd via the supported initramfs tool.
Verify required storage, filesystem, controller and NIC drivers exist for TARGET (including built-ins).

**G4 — Pause only an md check.** For each confirmed `sync_action=check`, record array UUID and
`sync_completed`; `echo frozen > /sys/block/mdX/md/sync_action`, record the resulting `sync_min`, then
`echo idle > /sys/block/mdX/md/sync_action`; verify idle. Record/respect mdcheck timers so it cannot restart
before reboot. After boot, map UUID to the current array, restore recorded `sync_min`, run
`echo check > /sys/block/mdX/md/sync_action` and verify progress; restore any timer state changed for the pause.
Never apply this to resync/recovery/repair. If reboot is abandoned, resume the paused check and timers.

**G5 — Hand stop + reboot to the host together.** Finish G1–G4, G6 and any P gates before stopping work.
Record the intended workload stop list and automatic/manual return plan. As root, launch ONE host-side batch:
`systemd-run --unit=host-maintenance-UNIQUE /bin/sh -c 'systemctl stop UNIT; systemctl reboot'`
(replace UNIT with approved units; omit stop if none; use a unique unit and capture its journal locally).
Record the reboot-request time inside the batch, immediately before `systemctl reboot`; ensure the child
started/logged. It must attempt reboot even if a graceful stop fails; never put reboot behind `&&` or `set -e`.
Without systemd-run, use `nohup setsid sh -c 'STOP; systemctl reboot' </dev/null >PRIVATE-LOG 2>&1 &`
only after verifying detached children survive logout; replace STOP with the reviewed stop commands.
The whole sequence must survive lost SSH/agent context; never stop workloads in a separate agent turn.

**G6 — Recovery respects shutdown time.** Before G5 read `systemctl show -p DefaultTimeoutStopUSec`
and `systemd-analyze cat-config systemd/system.conf` (includes drop-ins); inspect relevant units'
`TimeoutStopUSec` too. Record a recovery threshold beyond workload-stop + actual shutdown budget + POST,
not a fixed 90 seconds; a 900s stop budget needs at least 25 minutes, longer for overrides/serial stop jobs.
Ping up / SSH refused can be healthy shutdown. Never force-reboot, SysRq or BMC-reset inside that budget.
After the threshold, require TWO observations minutes apart: console static with no progress, OR (console
unreadable) ping up, expected TCP ports such as 22/8006 refused AND independent evidence uptime did not reset.
TCP refusal alone cannot prove unchanged uptime. Unknown uptime/budget means gather console/recovery evidence.
Elapsed time alone never permits reset; POST/progress means wait. Only a confirmed hang with authorization
for that recovery permits BMC reset; log evidence/action. Use console to select fallback/rescue if boot fails.

**G7 — Verify after EACH boot.** `uname -r` must equal recorded TARGET; check OS/package versions,
boot ID/uptime changed, G2 storage/mounts, network addresses/routes, `systemctl --failed`, and
`systemctl is-active UNIT` for baseline services; inspect `journalctl -b -p err -n 100` for new failures.
Verify workloads individually against baseline; allow their normal staggered startup, no mass restart.
Check actual service endpoints from TWO genuinely different external networks (record vantage/time/result).
Localhost, this host's public IP from itself, or two shells on one network do not count. Use existing approved
probes/coordinator; send no private logs. If either vantage is unavailable, report verification pending.
Keep fallback until health is verified; on regression investigate/restore via console, do not advance the campaign.

**G8 — Prove the security fix.** For a security update, before choosing TARGET map its exact package version
to the vendor advisory, changelog or fix commit, including backports/package revision and kernel branch.
Record the source and installed/target versions; “newer” is insufficient. A later cumulative release may carry
the fix without repeating its changelog entry. Recheck the installed package AND running kernel after boot.

**G9 — Serial maintenance.** Coordinate the authorized host/window with its owner/campaign coordinator;
one host at a time, verify G7 before the next. Hold for a real collision (guest migration, peer maintenance,
array recovery), not load/PSI alone. Continue the authorized security campaign after successful verification;
do not invent a new approval gate between hosts. Journal any actual blocker and who owns its resolution.

## PROXMOX-VE-SPECIFIC: adds to G1–G9 (P1–P7)

**P1 — Readiness.** Check `pveversion -v`, `pvesm status`, `qm list`, `pct list`, backups and, if clustered,
`pvecm status`/quorum and HA/migration state. Follow the current official guide for the exact major hop.
Run `pve7to8 --full` or `pve8to9 --full` as applicable; resolve every failure and understand warnings.
Require 0 unresolved FAILURES. Only a verified package-lookup microcode checker artifact may be annotated
as such (confirm CPU/package/repository facts); never dismiss a real missing microcode update or other failure.

**P2 — Two windows, each with all reboot gates and verification.** For 8→9: A = bookworm catch-up to
latest supported 8.x and reboot newest same-major kernel; only after G7, B = official trixie migration,
documented apt upgrade and reboot into verified PVE 9 TARGET. Derive today's kernel from the supported
repositories/advisory; never pin to a historical kernel number from a past upgrade.
Keep the 6.8/previous known-good fallback. Update the archive keyring before using its deb822 Signed-By;
check suites/components (including non-free-firmware), subscription choice and duplicate/old sources.
`apt update` must exit 0; review `apt -s dist-upgrade` before the guide's actual `apt dist-upgrade`.
No removal of proxmox-ve, pve-manager, openssh-server or ifupdown2. For BIOS grub-pc preseed only verified
by-id boot disks, comma-SPACE separated (`debconf-set-selections`); recheck actual GRUB installs via G2.

**P3 — Guest continuity before A.** Back up `/etc/pve` and verify restorable guest backups. Record running
VMs/containers, pending changes and `onboot` values. Confirm each VM's RUNNING QEMU `-machine` versioned type
(corroborate with `qm config VMID --current`); pin that exact supported type with `qm set VMID --machine TYPE`
before A's packages: its QEMU bump already changes defaults. Never derive the pin from pending config.
Inside G5's single detached batch enumerate graceful stops: `qm shutdown VMID --timeout 180` and
`pct shutdown CTID --timeout 180`, then `systemctl reboot`; no separate stop turn, no forced `qm stop`.
Arrange `onboot: 1` for workloads expected to return automatically (record/restore temporary changes).
After boot: `systemctl is-active pveproxy pvedaemon pvestatd pvescheduler`, port 8006, storage/quorum,
all expected guests/services and running machine types; start any planned manual guests one at a time.

**P4 — bcache-on-md.** Map layers/UUIDs with `lsblk -f`, `mdadm --detail`, `bcache-super-show BACKING`;
check `/sys/block/bcacheX/bcache/{state,dirty_data}`. Attached cache → drain writes; inside reboot A's
detached batch, AFTER guests stop and dirty_data=0, `echo 1 > /sys/block/bcacheX/bcache/detach`.
Bound the detach wait (120s), check state `no cache`/zero cache-set UUID. On incomplete detach, still reboot A
on the known same-major kernel to restore guests; record the failure and HOLD B until resolved.
No detach while guests still write or dirty data remains; never force-run a backing device with a missing dirty cache.
Before reboot B: `lsinitramfs /boot/initrd.img-TARGET`, then `unmkinitramfs /boot/initrd.img-TARGET PRIVATE-DIR`;
verify bcache.ko (possibly compressed), `69-bcache.rules`, required RAID/controller modules and mdadm.conf.
Inspect extracted ARRAY identities against live arrays: no duplicate UUID/name lines, none missing.
Repair source config and rebuild initrd, re-extract/recheck. MD-on-bcache is a different, unproven topology;
stop for a topology-specific plan rather than applying this detach recipe blindly.

**P5 — Recheck package traps.** Without an enterprise subscription, disable the recreated
`/etc/apt/sources.list.d/pve-enterprise.sources` stanza: replace `Enabled: true`, or ADD `Enabled: false`
if absent; read back and rerun `apt update`. Preserve valid subscribed repos.
Save the existing md-check cron/timer policy before packages: mdadm 4.2→4.4 may move `/etc/cron.d/mdadm`
to `.dpkg-bak` and re-enable monthly `mdcheck_start.timer`/`mdcheck_continue.timer`. Restore the recorded
approved schedule (including 3x/year if configured). Only for a prior cron-only policy, restore its backed-up file
and `systemctl disable --now mdcheck_start.timer mdcheck_continue.timer`; verify cron and `systemctl list-timers`.
For missing module names, check `modinfo -k TARGET MODULE`, target `modules.builtin`/kernel config and
documented replacements; prove each required driver, never waive the whole audit for renamed crypto helpers.
If a reboot hangs in final shutdown on an older kernel, collect bounded journal/console observations; G6 still governs reset.

**P6 — Tested BMC rollback path before EACH reboot.** From an independent authorized management vantage,
test authenticated `ipmitool -I lanplus -H BMC -U USER -a chassis power status` and actual console access.
No plaintext password in argv/logs. If LAN credentials drifted, an authorized in-band KCS reset of the
EXISTING account may repair access; use the hardware's documented secure credential procedure, retest LAN.
Never add a user, factory-reset the BMC or weaken authentication. Unverified recovery access → hold.

**P7 — Recovery router first (also G2).** Degraded array or suspect drive → pause maintenance, read-only
`mdadm --detail /dev/mdX`, `smartctl -x /dev/disk/by-id/DEVICE`, bounded `journalctl -k -n 200`;
record serial/WWN, affected arrays/redundancy and first timestamped errors. A SMART flag, `(F)` or pending
sector screens a suspect, never proves media death. `DID_BAD_TARGET`/same-slot double-drop routes to
path/cable/backplane diagnosis and authorized reseat/DC work. No pull, destructive test, force assembly or
reset under this SOP; preserve data and obtain a separate recovery plan, then restore health before maintenance.

The design decision and provenance for this SOP are recorded in its ADR; no other files are needed at runtime.
Live references: [Proxmox 8→9](https://pve.proxmox.com/wiki/Upgrade_from_8_to_9),
[md](https://docs.kernel.org/admin-guide/md.html), [bcache](https://docs.kernel.org/admin-guide/bcache.html),
[systemd timeouts](https://www.freedesktop.org/software/systemd/man/latest/systemd-system.conf.html).
