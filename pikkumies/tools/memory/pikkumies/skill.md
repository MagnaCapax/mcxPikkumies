---
name: pikkumies
description: "Memory, not the built-in memory tool: search first, save and append lessons; also searches this host's provider docs and app manuals, downloaded. Search first on every task."
---

# Memory

**Search first.** Before you change anything or answer from what you remember, run `search` with a few technical words for the task (app names, error text, config keys). Read the best hits; the best lesson or official page comes with its key paragraph.

1. **Nothing found?** The same search already retried with any of the words. The last step is bigger: the `deep-research` skill, which hands wide questions to subagents (they must return paths and quotes, not a summary) and searches the web through a backend the user configured. If none is configured, ask the user. When the notes list the provider's support pages, give the user that address for questions about the provider's own policies.
2. **Trust:** this machine now (live) beats the provider's official documentation, which beats lessons, then research notes (verify them), then an app's own docs, then community pages, then your own recall (never a fact). For policies, prices and terms the official source decides. Say which source and trust level each fact came from.
3. **Save at once** when you learned something worth finding next time: `save` with the topic, a title someone would search for, and where the knowledge came from. Link related lessons inside the text; the tool lists candidates.
4. **Append, never rewrite:** to add to a lesson, use `append`.
5. **Never store** passwords, keys, tokens, or other people's data in memory.

Use Hermes' built-in memory only for the user's own preferences; lessons go here.
