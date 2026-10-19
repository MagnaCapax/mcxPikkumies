---
tags: task, storage, account, budget
family: pmss
first_call: tools/system/system-status/system-status\.php.*\baccount\b
max_calls: 2
not_calls: terminal.*\b(df|du|free)\s+-
judge: Gives the disk space used and left, memory in use against its limit, and upload traffic in the last 30 days against its limit, from live output on this machine.
source: PMSS etc/skel/bin/userShowLimits (disk, memory and traffic sections), read through system-status account (ADR-0058).
note: Live run 2026-10-18: a basic disk/memory/quota question cost about 26k credits, 23 s and 9 tool calls (memory search first, then df/free/du and wiki text). One account call answers all three.
---
How much disk space, memory and traffic do I have left?
