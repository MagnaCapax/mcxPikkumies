---
name: pre-edit-copy
event: pre_tool_call
description: Before a call rewrites a small existing file (write_file, patch, sed -i, a > redirect, truncate), keeps a copy of it as it was in a private tmp folder under the account's quota; never blocks or asks.
failClosed: false
timeout: 3
---
Reads `write_file` and `patch` (the path, and each file a V4A patch names, `lib/PatchPaths.php`) and `terminal` commands: `sed -i` / `--in-place`, a `>`, `>|` or `&>` redirect (never `>>`, never `2>&1`), `truncate`. A regular file of at most 1 MiB owned by this account (any owner as uid 0; another owner's file under `sudo sed -i` or `sudo truncate` through `sudo -n cat`, which never prompts) is copied to `local/tmp/pre-edit/<session id>/<its full path>` (`lib/LocalTmp.php`: folders 0700 of this account, never through a symlink; files 0600 created with `x`). The opened file must be the one checked (same device and inode, fstat size); the copy is written as `.part` and renamed only when every byte arrived. An identical copy is not made twice, a changed one gets a time suffix; at most 50 MB per session. The first copy of a session leaves one hook note saying where copies go, and removes session folders older than 7 days; an untrusted folder leaves one note that copies are off. No session id, a relative path without a working directory or a new file: no copy. Prints nothing. Fails open ([ADR-0099](../../../../docs/adr/0099-backup-safety-layer.md)).
