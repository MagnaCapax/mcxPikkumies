---
tags: memory, search-first, built-in-memory
first_call: terminal.*tools/memory/pikkumies/pikkumies\.php.*\bsearch\b
not_calls: ^memory\b
judge: The answer says what the memory search found, or that it found nothing, and does not claim to have read anything from the built-in memory tool.
source: pikkumies/doctrine/doctrine.md (Memory: what goes where)
note: The built-in memory tool only adds, patches and removes; a recall request that calls it fails. The pattern matches the tool name, so any call to it fails the question.
---
What do you remember from earlier sessions about problems with my torrent client?
