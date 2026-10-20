---
name: session-rewrite
description: "Rewrite account-rename path prefixes in a STOPPED torrent client's own session files (rtorrent, qbittorrent, transmission), raw-preserving; dry-run by default, --apply backs up first."
---

# session-rewrite

Use this only when a torrent client's account was renamed (its home folder's path prefix changed) and its
OWN session files still point at the old path. It never touches a running client -- it refuses outright
while one is found running for this account -- and it never moves any torrent data itself.

1. **Dry-run first, always.** `rewrite --client rtorrent|qbittorrent|transmission --from <old prefix> --to <new prefix>` lists every session file and key it would change, with no `--apply`. Say in your message what it would change before anything else.
2. **The client must be stopped.** If it refuses saying the client is running, stop the client the way this provider documents it (never by killing the process) and try again; this tool never stops a client for you. When it cannot be stopped from here, say in your answer that the rewrite needs the client stopped, and change nothing.
3. **`--apply` only after the dry-run looks right.** It backs the whole session directory up first (`local/state/backups/`), then rewrites exactly the keys the dry-run listed. It never touches a torrent's "info" dictionary (where one exists) and verifies that before writing anything.
4. **deluge is refused.** Its on-disk state (`torrents.state`) is a Python pickle, not bencode, and this tool never edits it. Use the live `deluge repath <id>... --to <dir>` instead, once Deluge is running again with the data already at the new path.
5. **This never moves data.** `--to` describes only the NEW path prefix the session files should point at; the actual files must already be there (or get copied there separately) before the client is started again.
