---
tags: task, storage
calls: tools/storage/rclone.*\bcheck\b
not_calls: tools/storage/rclone.*\b(sync|move|purge|delete)\b
judge: Uses copy (not sync or move) and says nothing already on the remote is deleted.
---
Copy my Movies folder to my rclone remote called 'backup' without deleting anything already there.
