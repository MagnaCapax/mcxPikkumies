# search-first-gate

Search first (ADR-0011, ADR-0028). Until the agent has run `tools/memory/pikkumies/pikkumies.php search` in the session, tool calls that act are blocked with the command to run. Reading files, asking the user, and the memory tool's `search`, `list` and `sources` verbs stay allowed.

- The search touches one marker file per session under `local/state/search-first/` (files older than 7 days are removed).
- `search-first` touches `<marker>.armed` when a later turn asks something new; the gate stays closed until a search is newer.
- `searchFirst.disabled` in `local/config.php` turns it off.

It fails open: if it errors or times out the call runs. The agent's user can write the marker files, so this is a habit prompt, not a security boundary.
