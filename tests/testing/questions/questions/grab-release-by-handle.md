---
tags: task, arr
calls: tools/arr/prowlarr.*\bgrab\b
judge: Grabs the exact handle the user named from the prior results, not a release it picked itself as "best"; if more than one torrent client is installed and running it picks one with --client, since the user said any running client will do, and says which.
source: pikkumies/tools/arr/prowlarr/USAGE.md (grab sends the named handle onward; auto-picks a client only when exactly one is installed and running, else refuses naming the choices)
note: a multi-turn question — the prior turn's numbered release list is assumed context; on a fresh account with no prior releases call, a correct agent re-runs releases first rather than guessing a handle.
---
Grab the second one from that list using whatever torrent client I've got running.
