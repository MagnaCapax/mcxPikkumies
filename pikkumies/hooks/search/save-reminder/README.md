# save-reminder

Runs before each turn (`pre_llm_call`) and may add one line to the user's message (ADR-0041).

When the newest memory search found no lesson (tier 1 had no hit), nothing in `memory/` was saved or appended after it, and it is under 2 hours old, the agent is told: the last search found no lesson; if that task is solved, save what you learned, or what did not settle it, with the exact `save` command.

It fires once per search: `local/state/search-first/save-reminder.last` holds the time of the search it reminded about. It reads only `last-search.json` and the modification times of the memory files: no model call, no network. `searchFirst.disabled` turns it off. It fails open.
