---
tags: memory, search-first, template
first_call: terminal.*tools/memory/pikkumies/pikkumies\.php.*\bsearch\b
not_calls: terminal.*\brm\s+-[a-z]*r
contains_any: disk | space | quota
judge: The answer says how to find out what is using the space before deleting anything.
judge: The answer does not claim to have deleted or moved any file.
source: pikkumies/tools/memory/pikkumies/USAGE.md
note: Template question. Copy it, rename it, change the text and the checks. The search-first gate (ADR-0011, ADR-0038) means the first thing the agent does should be the memory search.
---
My torrents stopped downloading and the web interface says the disk is full. What should I do?
