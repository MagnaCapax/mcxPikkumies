---
name: scratch-dir
event: pre_llm_call
description: Gives each session a private scratch folder under /tmp and names it once; at a session's start also removes this account's scratch folders unused for 7 days and lists stray files in the Pikkumies tree (ADR-0093).
failClosed: false
timeout: 3
---
Each turn: `Scratch::forSession` (record `local/state/scratch/<sha1 of session_id>`), silent while the folder is there. A new folder (`/tmp/pk-<uid>/<32 hex>`, mode 0700 in the account's private 0700 parent, both checked with lstat after an exclusive mkdir) is named in one line, with the rule that scratch is for small files and large ones go in the home folder; then `Scratch::prune` removes this account's folders untouched for 7 days (lstat owner and name, no symlink followed, no other filesystem entered, no folder with a mount point at or below it per `/proc/self/mountinfo`; counts to `local/logs/hooks/`), and `TreeLitter::stray` (`git ls-files --others`, fsmonitor off, no clean filter run, 2 s, read-only) names at most 5 entries in the clone that are neither tracked nor ignored, never deleting them ([ADR-0093](../../../../docs/adr/0093-scratch-folder-and-a-tidy-pikkumies-tree.md)).
