---
name: server-admin
description: Full server administration (packages, services, users, firewall) when root is available. Use only after checking that root is available, and only for the step that needs it.
---

# Server administration

Root is one more capability layer, not a different job.

1. Check `root.available` with the system-status skill. If false, stop: this skill does not apply.
2. Say that you will use root, and why, before the first privileged command.
   For a host upgrade or kernel reboot, read `~/pikkumies/doctrine/SOP/upgrade-a-host-and-reboot.md` before changing packages or stopping workloads.
   To harden, secure or tune a server (SSH, firewall, web server and PHP workers, kernel settings, services it does not need), read `~/pikkumies/doctrine/SOP/harden-a-linux-server.md` first.
3. Read before changing: `sudo systemctl status <unit>` (as root; without root a system unit's status is refused for privacy, because it shows other accounts' processes: use `systemctl is-active <unit>`), the unit's journal, the config file.
4. Back up any config before editing it.
5. One change at a time; verify after each (`systemctl is-active`, the service answers).
6. Never reboot or stop shared services on a multi-user machine unless the task needs it: review it first (will it come back, who uses it now), say it in one line, then do it and check that everything came back (doctrine/safety.md).
