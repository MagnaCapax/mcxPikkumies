---
name: telemetry
description: Local-only history of diagnostic and health measurements (quota, memory, traffic, torrent counts) so a chart can be drawn later; use `series` and `list`, never `record` for the agent's own curiosity.
---

# telemetry

This tool only reads what other tools already recorded; it never runs a diagnostic itself. `record` is wired into `system-status account`, `system-status health`, `storage stats` and every torrent client's `stats` verb -- running one of those is how a record gets added, not this tool.

- When the user asks for a trend, a history or a chart of their quota, memory, traffic or torrent counts over time ("how has my disk use looked this week", "chart my upload traffic"), run `list` to see which sources exist, then `series <source> <field>` for the numbers. Answer from the record: `value` is each bucket's mean, with `min`, `max` and `count` alongside it.
- Field names come from USAGE.md's table for each source; do not guess one.
- Never call `record` yourself: it is for the tools that already run a diagnostic, not for you to log a number you made up.
- With nothing on disk for a source, `series` returns an empty list and `list` does not mention it: say the history has not started yet (the account's own next diagnostic run starts it), never that the tool is broken.
- This is local-only: nothing a torrent-client stats run, a quota check or a health check already records is sent anywhere by this tool. See [ADR-0086](../../../../docs/adr/0086-local-only-telemetry-history-for-diagnostics-and-health-runs.md) if asked why it is not `privacy.telemetry`.
- `prune` deletes data on request only (the safety check decides, doctrine/safety.md); without `--keep-days` it does nothing, because the default is to keep everything.
