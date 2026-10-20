---
name: fileops-check
event: pre_tool_call
description: Lists the targets of bulk permission, owner, attribute and rename commands to a gzipped file first; blocks them once so the agent tells the user what they change, and sends to the ask policy (which runs or refuses and reports, ADR-0101) an mv that replaces a file or a recursive change of the home, ~/.ssh or ~/pikkumies.
failClosed: false
timeout: 5
---
Reads `terminal` commands and `execute_code` code (`lib/FileopsClassify.php`). Blocks once: recursive chmod/chown/chgrp, chmod/chown/chgrp run by xargs, find -exec of chmod/chown/chgrp/mv, chattr, rename, setfacl; the identical command in the same session within 10 minutes then runs. A question for the ask policy (`rule_key` `pikkumies-fileops:`; `kind` `delete` when mv replaces a file): mv onto an existing file under the home, a recursive change of the home, `~/.ssh` or the pikkumies directory, more than 50000 entries. Before answering it writes `local/logs/fileops/<sha1 of the tool call id>.jsonl.gz` (`lib/FileopsList.php`). Fails open: guard-destructive still runs. Off with `fileopsCheck.disabled` ([ADR-0048](../../../../docs/adr/0048-fileops-check-recheck-and-file-list.md)).
