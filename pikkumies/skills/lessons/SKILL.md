---
name: lessons
description: "Lessons: search how past problems on this machine were solved before you start troubleshooting, and save a lesson after you solve something non-obvious. Use this, not the built-in memory tool, for anything about how a problem got solved."
---

# Lessons

1. Before you start: search for the problem in plain words.
   `php ~/pikkumies/tools/memory/memory.php search "<what is wrong>"`
   Read the top results. Past fixes and mistakes are in there.
2. After you solved something non-obvious: write the lesson body to a temporary file (what was wrong, the real cause, the fix, how you verified it), then save it.
   `php ~/pikkumies/tools/memory/memory.php save "<short title>" /tmp/lesson.md --tags <a,b> --sources "<commands or files you used>"`
3. If `save` prints related lessons, link the relevant ones in the body as `[short description](file.md)`.

The built-in memory tool is small and goes into every turn, so it holds only short facts about the user and their preferences. Lessons live here, as files you can search.
