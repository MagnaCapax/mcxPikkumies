# pikkumies-configure

Pikkumies' own user settings, read from and written to `local/config.php` (ADR-0032). Nothing goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| show | | no | One record per setting: `key` and its current value. |
| get | `<key>` | no | One setting's value. |
| set | `<key> <value>` | yes | Writes one setting: `agent.name` (1-32 letters, digits, spaces, dots, dashes, underscores; empty = "Pikkumies"), `privacy.perfLogging` and `privacy.telemetry` (`on`/`off`), `sessions.retentionDays` (whole days, 0 = keep forever). Every other key is refused. Takes effect at the next session. |
| review | | no | Every setting with its value, what it means (`means`), whether `set` may change it (`settable`) and a `warning` when one applies (sessions kept forever, no name yet, terms not accepted). |

```sh
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php review --human
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set agent.name "Little Helper"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set sessions.retentionDays 90
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set privacy.telemetry off
```

- Defaults: `agent.name` empty (asked at the first interactive start), `privacy.perfLogging` on, `privacy.telemetry` on, `sessions.retentionDays` 0, `terms.accepted` off.
- `privacy.perfLogging` keeps performance logs on this machine only, under `~/pikkumies/local/state/memory-metrics/`: today the time and result counts of each memory search, its words only when it found no lesson, and the time of each lesson save. Nothing is sent. Off stops new lines; files already written stay until deleted. With `sessions.retentionDays` above 0, lines older than that are pruned. `privacy.telemetry` is a setting only: no sender exists yet. Hermes' own telemetry stays off whatever these say.
- `sessions.retentionDays` N > 0 turns on Hermes' own pruning of ended sessions older than N days at start (`sessions.auto_prune`, `sessions.retention_days`); 0 turns it off, and Hermes' session store `local/hermes/state.db` grows without bound.
- `terms.accepted` is set only by the person: the first interactive session asks, or `pikkumies install --accept-terms`. `set terms.accepted` is refused.
- `set` rewrites `local/config.php` from its values, under a lock, through a temporary file and a rename; comments in that file are not kept.
