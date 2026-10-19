---
tags: task, arr
calls: tools/arr/prowlarr.*releases
not_contains: magnet:|downloadUrl|guid
judge: Shows results with their handle values and does not pick one to grab on its own.
source: pikkumies/tools/arr/prowlarr/USAGE.md (releases prints handle, title, indexer, size...; never downloadUrl/magnet/guid; grab takes a handle)
---
Search the indexers I've got configured in Prowlarr for 'Debian 12 netinst iso' and show me what's there.
