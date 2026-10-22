---
name: pikkumies
description: "Memory, not the built-in memory tool: search first, save and append lessons; also searches this host's wiki, knowledge base and app manuals, downloaded. Search first on every task; the wiki and kb skills search those alone."
---

# Memory

**Search first.** Before you change anything or answer from what you remember, run `search` with a few technical words for the task (app names, error text, config keys). Then read the best page of each kind that matched (this provider, the app's manual, another provider) before you answer: the best lesson or official page comes with its key paragraph, and an excerpt is one paragraph. Name each page you use and its address; another provider's page may not apply here.

1. **Tiers.** Hits come in order: your lessons, then this host's provider and app docs, then other providers' docs (may not apply here). Web search is the last tier: the web tools stay blocked until this turn's search has run.
2. **No lesson settles it?** When the answer must come from this host's docs, the wiki, the kb or other providers' docs, use the `deep-research` skill: its first part reads the top hits with subagents side by side and checks each answer twice; its second part is the web, through a backend the user configured. Nothing found at all? The same search already retried with any of the words. If none is configured, answer from what you found and say the web was not searched. When the notes list the provider's support pages, give the user that address for questions about the provider's own policies.
3. **Trust:** this machine now (live) beats the provider's official documentation, which beats lessons, then research notes (verify them), then an app's own docs, then community pages, then your own recall (never a fact). For policies, prices and terms the official source decides. Say which source and trust level each fact came from.
4. **Save at once** when you learned something worth finding next time, when in doubt, and always after a search that found no lesson, settled or not (a reminder comes once if you forget): `save` with the topic, a title someone would search for, and where the knowledge came from. Link related lessons inside the text (the tool lists candidates), and link every source that has an address, with no limit: files, web pages, wiki and knowledge base articles, any URI. When the lesson is about a particular file (a tool's script, a config file), add `--tool <file>`: the next time a call uses that file, you are shown the lesson first.
5. **A hard task gets an SOP too.** When the task was really hard (several approaches before one worked, deep research, help from Väinämöinen, most of the session), save its full process as a separate memory after the lesson: `save --kind sop` with the headings `## When to use` (the requests it answers, in the user's words), `## Steps`, `## Checks` and `## What did not work`. It lands beside the lesson, titled "SOP: ...". An SOP that matches a later request: follow it, checking each step's precondition on this machine first.
6. **Append, never rewrite:** to add to a lesson, use `append`.
7. **Follow links:** `links <path>` lists what a lesson links to and what links to it; `links --broken` lists links that point at nothing.
8. **Never store** passwords, keys, tokens, or other people's data in memory.

Use Hermes' built-in memory only for the user's own preferences; lessons go here.
