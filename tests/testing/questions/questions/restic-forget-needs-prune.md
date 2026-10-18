---
tags: source:restic-docs, docs, app
family: generic
first_call: tools/memory/pikkumies/pikkumies\.php.* search
contains: prune
source: Removing backup snapshots — restic documentation — https://restic.readthedocs.io/en/stable/060_forget.html
note: the two-step forget-then-prune mechanic, where prune reclaims only the data no remaining snapshot references, is explained specifically on this restic page.
---
I used restic forget to delete some old snapshots, but my backup repository did not actually shrink afterward. Why not, and how do I actually free up the space?
