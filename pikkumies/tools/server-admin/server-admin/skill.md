---
name: server-admin
description: Full server administration (packages, services, users, firewall) when root is available. Use only after checking that root is available, and only for the step that needs it.
---

# Server administration

Root is one more capability layer, not a different job.

1. Check `root.available` with the system-status skill. If false, stop: this skill does not apply.
2. Say that you will use root, and why, before the first privileged command.
   For a host upgrade or kernel reboot, read `~/pikkumies/doctrine/SOP/upgrade-a-host-and-reboot.md` before changing packages or stopping workloads.
3. Read before changing: `systemctl status <unit>`, the unit's journal, the config file.
4. Back up any config before editing it.
5. One change at a time; verify after each (`systemctl is-active`, the service answers).
6. Never reboot or stop shared services on a multi-user machine without the owner's explicit yes.
