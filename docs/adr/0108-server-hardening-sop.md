# ADR-0108: Pulsed Media's server hardening and tuning standard as an SOP

## Metadata
- Author: Väinämöinen (for Aleksi Ursin / MCX)
- Date: 2026-10-22
- Status: Accepted

## Context
The operator's request of 2026-10-22, verbatim: "add some SOPs doctrine information to pikkumies that when hardening a standard linux server, for whatever purpose, the standards of APF+BFD (if any BFD), typical settings we apply, typical way to managing php-fpm workers, threads on nginx. lgihttpd, apache and performance settings, typical security precautions (and linked to wiki why not fail2ban and why root login with password is OK and sometimes necessary)".

What was there: the server-admin skill named the firewall in its scope but had no procedure, and nothing in Pikkumies said what Pulsed Media runs. Asked to harden a server, an agent falls back on the generic checklist (fail2ban, key-only SSH, a changed port, ping blocking), which Pulsed Media rejects because those measures lock out the people who run the server and add failure points without real security gain. The rejection is public on the Pulsed Media wiki (Fail2ban). doctrine.md also tells the agent never to fill a gap with a typical value, so the settings had to come as Pulsed Media's own, labelled as starting points.

Sources for the values: Pulsed Media's billing web host configuration (Apache event MPM, php-fpm, sysctl, APF and BFD), the PMSS templates for seedbox servers (sshd with root password login kept, nginx workers, lighttpd FastCGI sizing of four PHP threads per CPU, sysctl, logrotate), the operator's PHP worker rule of 2026-10-14 ("It needs to be at least four times the number of cores. Cores being busy is less damaging than no access at all."), and the wiki pages Fail2ban and Installing Advanced Policy Firewall and Brute Force Detection.

## Options Considered
- Wiki links only, from the server-admin skill. Rejected: the wiki has no worker-sizing, Apache, nginx or kernel page, and the request named those settings.
- A section in doctrine.md. Rejected: doctrine.md is in every prompt; this is read only when hardening.
- A skill or tool that applies the settings. Rejected: values must be sized per machine, and the server-admin skill already does root changes one at a time.
- One SOP, reached from server-admin, linking the wiki for install steps and the reasons (chosen), as [ADR-0098](0098-host-upgrade-and-kernel-reboot-sop.md) did for host upgrades.

## Decision
- Add `pikkumies/doctrine/SOP/harden-a-linux-server.md`: before changing anything (read, back up, the addresses never to block, a second session, one firewall manager), SSH (root password login kept and never disabled to harden, a strong unique password, no fail2ban, no changed port, no ping blocking, key-only only after keys work everywhere), APF with BFD optional, web stack sizing (PHP workers at least four times the CPU threads, then fitted to RAM and database connections; php-fpm, nginx, Apache, lighttpd), kernel and network starting points, log caps, patching and verification from a new session.
- The server-admin skill points to it beside the upgrade SOP.
- Root password login is turned on only when the user asks: doctrine/safety.md forbids weakening authentication to make something work, and turning it off to harden is what the standard rejects.
- BFD is optional ("if any BFD") and starts only after the user's and the provider's addresses are in its ignore list, with the wiki page's threshold: it bans on failed logins, the same way fail2ban locked out Pulsed Media's own servers.
- Per-address rate limits and connection caps are left out, except against an attack seen in the logs: they block real users behind shared addresses.
- The APF wiki page's suggestion to combine APF with fail2ban is overridden in the SOP.

## Consequences
- An agent asked to harden a server applies Pulsed Media's standard, sized on that machine, instead of the generic checklist.
- The values are starting points that change with Pulsed Media's own configuration; the SOP says so, and the agent checks each one on the machine.
- Two wiki pages contradict the standard (the APF page's fail2ban suggestion; the OpenSSH page's generic checklist); fixing them is a separate wiki change.

## Amendment 2026-10-29: services the server does not need
The operator's request of 2026-10-29, verbatim: "probably generalized server hardening (but let's forget the fail2ban bullshit, let's keep root ssh login etc. we can be opinionated here, this is for sysadmins and ease of life, peopel can change the shit if they want -- so more improtant is checking there is no stupid services for a linux server running like CUPS and unnnecessary daermons and shit like that)".

The SOP had no step for what runs on the server. Test installs on 2026-10-29 found the gap in practice: an open-source seedbox installer on Debian 12 left packagekit (a desktop package helper) running on a headless server.
- Add section 7, "Services: run only what the server is for": list running, enabled and listening units; sort each into workload, OS-essential or not needed here, with common examples to recognise rather than a list to delete; check reverse dependencies and the user's own words before stopping; `disable --now` plus `mask`, sockets and timers too; verify from a new session with `free -m` before and after. Never the VM guest agent, the time service, or what the panel or hypervisor needs.
- A local mail server is left alone: cron and alerts often send through it, and stopping it fails silently.
- No package purge as part of hardening (it also deletes configuration and cannot be undone like a mask); a purge needs the user's own words, as the safety check in doctrine/safety.md requires for anything that cannot be taken back. Otherwise the answer lists the candidates. An unidentifiable unit stays running and is named in the answer, since the agent does not ask the user for permission (doctrine/safety.md).
- "Patch, then verify" becomes section 8. The SSH rules (root password login kept, no fail2ban) are unchanged.
- The server-admin skill's pointer names the new scope.

## References
- `pikkumies/doctrine/SOP/harden-a-linux-server.md`, `pikkumies/tools/server-admin/server-admin/skill.md`, `pikkumies/doctrine/safety.md`, [ADR-0098](0098-host-upgrade-and-kernel-reboot-sop.md).
- https://wiki.pulsedmedia.com/wiki/Fail2ban, https://wiki.pulsedmedia.com/wiki/Installing_Advanced_Policy_Firewall_and_Brute_Force_Detection
