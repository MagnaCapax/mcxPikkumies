# ADR-0098: One standalone host-upgrade and kernel-reboot SOP

## Metadata
- Author: MCX contributors
- Date: 2026-10-20
- Status: Accepted (reviewed 2026-10-20: SOP reconciled line-for-line against the originating maintenance-campaign design; host-power ops human-gated; PASS)

## Context
The operator requested a minimal procedure for Linux host upgrades and kernel reboots,
with generic checks first and a Proxmox VE major-upgrade layer afterward. It distils a
maintenance-campaign review (source retained privately by the maintainers, not in this repo).
Pikkumies runs on the managed host and cannot require any external checkout at runtime.
Its existing server-admin skill already handles root, package and service operations;
the missing part is the ordered maintenance checklist, not another executable.

## Options Considered
- Link to the sibling SOPs: shortest, but unusable on a standalone installation.
- Separate generic and Proxmox SOPs: smaller files, but splits a single maintenance
  decision across documents and makes the inherited reboot gates easier to miss.
- New upgrade tool/hook: adds state and platform-specific automation with no need.
- One SOP, reached from server-admin: chosen; inline the irreducible checks and
  conditional commands, keeping official platform upgrade tools in charge.

## Decision
- Add `pikkumies/doctrine/SOP/upgrade-a-host-and-reboot.md` and link it from the
  existing server-admin skill. This extends that unit's procedure (ADR-0015), not
  the skills index or command interface. The SOP is its documentation; no new unit.
- Preserve the lesson's G1–G9 then P1–P7 split. Keep rollback, storage/boot health,
  supported package paths, md-check pause/resume, detached stop+reboot, shutdown
  budgets, external verification, fix mapping and serial coordination explicit.
- Inline Proxmox readiness, two windows, guest machine pins, bcache/initrd checks,
  package traps, verified BMC access and the read-only storage-recovery router.
- Scope fleet discipline to one host at a time; campaign coordination remains with
  the caller. Reuse systemd, platform checkers, package tools, boot tooling and
  Hermes command logging. Runtime journals/backups stay under `pikkumies/local/`.
- Retain existing OS privilege checks, owner authorization for shared downtime,
  secret handling and destructive-action approval; an SOP grants no authority.
- Reject unsafe source generalizations: no reject-all LVM filter on real LVM,
  no guessed boot disk, no blanket microcode exception, no fixed future kernel,
  and no 90-second forced reboot. Recovery uses the actual shutdown budget and
  repeated evidence. A fallback kernel is not a userspace-major-upgrade rollback.

## Consequences
- A standalone installation has the maintenance gates in one place, with no new
  PHP, shell program, config, hook, dependency or automated power operation.
- The checklist is slightly above the ~150-line target to keep one coherent
  procedure; it does not duplicate the distro's full upgrade instructions.
- Historical campaign kernel versions and traps remain observations to verify,
  not promises about future releases. The operator/platform guide selects targets.
- Review validates the documentation and links; the existing PHP suite checks
  repository contracts. No host upgrade/reboot is executed to test this document.

## References
- [SOP](../../pikkumies/doctrine/SOP/upgrade-a-host-and-reboot.md),
  [server-admin](../../pikkumies/tools/server-admin/server-admin/skill.md),
  [engineering principles](../../development/engineering-principles.md).
- ADR-0004 (OS privileges), ADR-0015 (existing tool unit), ADR-0020 (approval),
  ADR-0036 (documentation), ADR-0042 (review), ADR-0044 (command logging).
- Provenance (a maintenance-campaign review and its source SOPs) is retained privately by the
  maintainers. It is not a runtime dependency and is deliberately not part of this public repository.
