---
name: memory
description: Search past lessons before solving a problem, and save a lesson after solving one. Use at the start of any troubleshooting task and whenever you learned something worth keeping.
---

# Memory

1. Before you start: search for the problem in plain words.
   `php ~/pikkumies/tools/memory/memory.php search "<what is wrong>"`
   Read the top results. Past fixes and mistakes are in there.
2. After you solved something non-obvious: write the lesson body to a temporary file (what was wrong, the real cause, the fix, how you verified it), then save it.
   `php ~/pikkumies/tools/memory/memory.php save "<short title>" /tmp/lesson.md --tags <a,b> --sources "<commands or files you used>"`
3. If `save` prints related lessons, link the relevant ones in the body as `[short description](file.md)`.

Short facts about the user go to your built-in memory, not here. Lessons are for "how this got solved".
