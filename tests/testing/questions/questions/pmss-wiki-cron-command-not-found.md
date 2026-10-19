---
tags: source:pm-wiki, docs, basic
found: pm-wiki
family: pmss
first_call: tools/memory/pikkumies/pikkumies\.php.* search
contains: /usr/bin
contains_any: full path | PATH=
source: Fixing command not found in cron jobs — Pulsed Media wiki (pm-wiki) — https://wiki.pulsedmedia.com/index.php?title=Fixing_command_not_found_in_cron_jobs
note: that a crontab job looks only in /usr/bin and /bin, and the fixes (a full path, or a PATH= line in the crontab), are on this pm-wiki page (read from the downloaded copy 2026-10-19).
---
My cron job fails with "mytool: not found", but the same command works fine when I'm logged in over SSH. Why, and how do I fix it?
