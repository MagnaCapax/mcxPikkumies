# search-first

Runs before each turn (`pre_llm_call`) and adds text to the user's message ([ADR-0011](../../../../docs/adr/0011-documentation-sources-and-search-first.md), [ADR-0028](../../../../docs/adr/0028-search-first-every-turn-and-a-light-model-for-hooks.md)).

- **First turn:** the instruction to run `tools/memory/pikkumies/pikkumies.php search` before acting, and the titles, trust levels and paths of local documentation that share half the words of the message.
- **Later turns:** only when the message asks something new (four or more content words, or a question mark, and not a slash command). It re-arms `search-first-gate` for the session and lists matching titles; silent when there are none.
- **Reword:** a question with no local match is sent to the agent's own provider (the light model, `lib/HookLlm.php`) for a few English search terms, and the copies on disk are searched again. Off with `hooks.reword = false` or `hooks.llm.disabled = true`.

Never page text, at most 1500 bytes. `searchFirst.disabled` turns it off. It fails open.
