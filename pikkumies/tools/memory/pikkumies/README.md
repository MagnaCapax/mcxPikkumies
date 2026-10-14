# pikkumies (memory)

The agent's own memory on this machine, in plain markdown files under `pikkumies/memory/` that never leave the machine and are never tracked by git:

- `lessons/<topic>/`: solved problems, one file each, with the date, the topic and where the knowledge came from;
- `reference/`: facts and data worth keeping;
- `research/`: longer findings.

The same search also covers the documentation copies downloaded from the configured sources (the Pulsed Media wiki and knowledge base by default). Search is the agent's first step on every task; a hook on the first turn lists matching titles and a gate holds back changes until a search has run (ADR-0011, ADR-0018).

New lessons must link to related ones, so knowledge stays connected. Lessons are only ever added to, never rewritten. Other memory sources can be added later as sibling units under `tools/memory/`.
