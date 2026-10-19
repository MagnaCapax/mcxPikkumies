# pikkumies (memory)

The agent's own memory on this machine, in plain markdown files under `pikkumies/memory/` that never leave the machine and are never tracked by git:

- `lessons/<topic>/`: solved problems, one file each, with the date, the topic and where the knowledge came from;
- `reference/`: facts and data worth keeping;
- `research/`: longer findings.

The same search also covers the documentation copies downloaded from the configured sources (the Pulsed Media wiki and knowledge base by default). Search is the agent's first step on every task; a hook on the first turn lists matching titles and a gate holds back changes until a search has run ([ADR-0011](../../../../docs/adr/0011-documentation-sources-and-search-first.md), [ADR-0018](../../../../docs/adr/0018-memory-tool-unit.md)).

With performance logging on (`privacy.perfLogging`), the unit keeps its own counts on this machine, never sent: the time and result counts of each search, its words only when it found no lesson, and the time of each lesson save. `stats` shows them, and `pikkumies doctor` shows one line ([ADR-0041](../../../../docs/adr/0041-memory-metrics-save-reminder-and-wide-search.md)).

New lessons must link to related ones, so knowledge stays connected. A lesson saved with `--tool <file>` is linked to that file: the first time in a session a tool call uses it, the memory-check hook shows the agent the lesson before the call runs ([ADR-0081](../../../../docs/adr/0081-tool-linked-memories-and-an-agent-facing-soft-confirm.md)). Lessons are only ever added to, never rewritten. Other memory sources can be added later as sibling units under `tools/memory/`.
