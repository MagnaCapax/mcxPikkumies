---
tags: task, backup, memory
first_call: tools/memory/pikkumies/pikkumies\.php.* search
calls: tools/backup/(borg|restic).*\b(stats|list)\b
judge: Grounds the answer in the backup tool's actual latest_at or archive list, not only in what the memory search says was configured.
---
I asked you to back up my config folder nightly a while back — is that actually still running, and did last night's backup succeed?
