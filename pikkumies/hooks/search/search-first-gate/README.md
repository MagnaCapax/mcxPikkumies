# search-first-gate

Search first (ADR-0011, ADR-0028). Until the agent has run `tools/memory/pikkumies/pikkumies.php search` in the session, tool calls that act are blocked with the command to run. Reading files, asking the user and the memory tool's `search`, `list` and `sources` verbs stay allowed; only the memory search opens the gate (ADR-0037). Lessons come before documentation (ADR-0043): reading or searching `local/docs-cache/`, and the `wiki` and `kb` tools' `search` and `list`, wait for the memory search.

- The search touches one marker file per session under `local/state/search-first/` (files older than 7 days are removed).
- `search-first` touches `<marker>.armed` when a later turn asks something new; the gate stays closed until a search is newer.
- `searchFirst.disabled` in `local/config.php` turns it off.

It fails open: if it errors or times out the call runs. The agent's user can write the marker files, so this is a habit prompt, not a security boundary.
