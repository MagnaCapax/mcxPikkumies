# telemetry

Local-only history of diagnostic and health measurements ([ADR-0086](../../../../docs/adr/0086-local-only-telemetry-history-for-diagnostics-and-health-runs.md)), one compact numeric record per run, kept forever unless `prune` is run by hand. Nothing here is sent anywhere.

| verb | arguments | changes | what it does |
|---|---|---|---|
| record | `<source> --json <object>` | yes | Internal: appends one record for `<source>` now, when `privacy.perfLogging` is on (`local/state/telemetry/<source>/YYYYMMDD.jsonl`, UTC day). `<object>` is one JSON object of `name: number` fields; a non-numeric value, a bad name, `ts` (set automatically) and anything past 32 fields are dropped, never an error. At most one write per source per minute and at most 1 MiB per source-day file: a write past either limit is skipped and counted as a drop, not an error (see "Growth limits" below). Wired into the tools below; call it by hand only to test or to add a record from a script of your own. |
| series | `<source> <field> [--days N] [--bucket hour\|day]` | no | A time series ready for a chart: one record per bucket, oldest first, `ts` (the bucket's own UTC start), `value` (the bucket's mean), `min`, `max`, `count`. `--days` 1 to 3650 (default 7); `--bucket` `hour` (default) or `day`. An unknown source or a field nothing wrote returns an empty list, never an error. |
| usage | `[--days N] [--kind tool\|skill\|unit] [--human]` | no | How many times a Hermes tool, a skill, or a Pikkumies unit's verb ran, from what `hooks/trace/usage-count` wrote ([ADR-0087](../../../../docs/adr/0087-tool-skill-and-verb-usage-counts-kept-on-this-machine.md)): one record per name (or name + verb for `unit`) with `kind`, `name`, `verb` (`unit` only, else `null`), `calls`, `ok_pct`, `last` (UTC), sorted by `calls`. `--days` 1 to 3650 (default 30); `--kind` narrows to `tool`, `skill` or `unit` (default: all three). With `privacy.perfLogging` off, an empty list and a `note` saying so and where to turn it on. `--human` is a table with 10-cell bars, as memory's `stats --human` renders. |
| list | | no | One record per source on disk: `source`, `days` (day-files present), `bytes`, `oldest`, `newest` (YYYYMMDD), `failures` (times `record` was asked to write and the write itself, or taking the source's own lock, did not work -- e.g. disk full, or a concurrent writer held the lock too long), `dropped` (times a write was skipped by the per-minute rate limit or the per-file size cap -- not a failure). A source `record` has never written, or has only skipped because `privacy.perfLogging` was off, is not listed at all. |
| prune | `[--keep-days N]` | deletes | Deletes day-files older than `N` days, for every source on disk. Without `--keep-days` (or `N` 0 or less) it deletes nothing: telemetry is kept forever by default, and this verb is opt-in. The safety check runs it when the user's own words cover it, and refuses it otherwise. |

```sh
php ~/pikkumies/tools/system/telemetry/telemetry.php list
php ~/pikkumies/tools/system/telemetry/telemetry.php series storage quota_used_percent --days 14 --bucket day
php ~/pikkumies/tools/system/telemetry/telemetry.php record storage --json '{"quota_used_percent":87.3}'
php ~/pikkumies/tools/system/telemetry/telemetry.php usage --kind unit --human
php ~/pikkumies/tools/system/telemetry/telemetry.php prune --keep-days 365
```

## Sources and fields

Each source is one directory under `local/state/telemetry/`, written by the tool named, never by this one on its own:

| source | written by | fields |
|---|---|---|
| `system-account` | `system-status account` | `disk_used`, `disk_soft` (bytes, the plan quota), `disk_used_pct`, `mem_used` (bytes), `mem_pressure_pct`, `traffic_upload_30d` (bytes), `traffic_used_pct`, `proc_used` (count), `cpu_quota_pct` |
| `system-health` | `system-status health` | `up_s` (machine uptime), `quota_used_pct`, `quota_server_full` (1/0), `mem_oom_kills`, `clients_listening`, `clients_total`, `findings_count` (0 = nothing wrong) |
| `storage` | `storage stats` | `quota_used_bytes`, `quota_limit_bytes`, `quota_used_percent`, `fs_free_bytes`, `fs_full` (1/0) |
| `torrent-rtorrent`, `torrent-qbittorrent`, `torrent-deluge` (one per client that is enabled) | that client's `stats` verb (`tools/torrent-clients/lib/Torrents.php`, shared by every client unit) | `torrents` (count), `downloading`, `seeding`, `paused`, `checking`, `queued`, `error`, `stalled`, `unknown` (counts by state), `down_rate`, `up_rate` (bytes/s), `quota_used_bytes`, `quota_free_bytes` |

A field missing from a given record (for example a non-PMSS host has no quota to report) is simply absent from that line, not a zero; `series` skips a line with no numeric value for the field asked for.

- `record` and the four tools above all go through `privacy.perfLogging` (on by default): off, nothing is written and nothing here is an error about it. This is the same local-only-metrics setting [ADR-0041](../../../../docs/adr/0041-memory-metrics-save-reminder-and-wide-search.md) uses for memory-search metrics; `privacy.telemetry` is a different setting, reserved for a future external sender, and this tool never reads it (see the ADR).
- The remote `serve` forced command (`tools/seedbox/services/serve.php`) allows only `series` and `list` for this tool: a peer can read the diagnostic history of a service account it was given (`system-account`, `system-health`, `storage`, `torrent-<client>`), never write one or prune it. `record` is never on `serve`'s allow-list, but a torrent client's own `stats` verb (which *is* remote-callable) records as a side effect, so the growth limits below apply to a remote peer calling `stats` too, at exactly the same rate as a local run -- there is no separate "remote" limit, because a single limit that applies to every caller is simpler and cannot be bypassed by whichever caller it was not written for. `series` and `list` being on the allow-list is *not* a promise that a peer may read everything these verbs can name: see "Usage counts" below for what they can never read, whatever `serve` permits for the verb itself.

## Usage counts (`usage-tool`, `usage-skill`, `usage-u-<tool>`)

Written by `hooks/trace/usage-count`, never by hand ([ADR-0087](../../../../docs/adr/0087-tool-skill-and-verb-usage-counts-kept-on-this-machine.md)), after every tool call: one line, `ts` plus exactly one other field, 1 (succeeded) or 0 (failed) -- no command, path, argument or session id, ever.

| source | field is | written when |
|---|---|---|
| `usage-tool` | the Hermes tool name (`terminal`, `skill_view`, `read_file`, ...) | every tool call |
| `usage-skill` | the skill name | a `skill_view` call that names one |
| `usage-u-<tool>` (one per Pikkumies unit actually used) | the verb -- only when it is one of that unit's own verbs (its USAGE.md verbs table), else `other` | a `terminal` call whose command runs that unit's own `*.php` script: the `php` call starts the command or follows `&&`/`;`/`\|`, and the path resolves (relative to the call's own `cwd`, a leading `~/` against HOME) under `Units::toolsDir()` |

**Never remote-readable.** Even though `series` and `list` are the two verbs `serve` allows for this tool, every `usage-*` source reads back empty from both when the environment carries `PIKKUMIES_SERVE=1` (security review F-TEL/F4) -- set by `serve.php` for every tool it runs, never by a local call. A peer `serve` was given access to may read a service account's diagnostic history; it may never read what tools, skills or verbs ran on this machine.

A name is lowercased, "-" and "/" become "_", and becomes `other` if it still fails `TelemetryLog::FIELD_RE` (over 32 characters, an MCP or plugin computed name, an odd skill name). Read these back with `usage`, not `series` (one usage line never repeats the same field in a way `series`' mean/min/max would say anything useful about; `usage` sums calls and successes instead).

## Growth limits

Kept forever is the default (the operator's own choice), but nothing here can grow without bound:

- **At most one write per source per minute, for a diagnostic snapshot source** (`system-account`, `system-health`, `storage`, `torrent-<client>`). A second `record` call for the same source inside that minute is skipped and counted as a drop, never a failure. This is what keeps a remote `serve` peer calling `stats` on a loop from growing telemetry any faster than an ordinary local diagnostic run would -- the limit is per *source*, not per caller, so there is nothing to tell apart.
- **No per-minute limit for a counter source** (`usage-tool`, `usage-skill`, `usage-u-<tool>`): many small writes a minute is what these are for, not abuse, and writing to them is never reachable through a remote `serve` peer (`record` is not on `serve`'s allow-list; reading them is a separate matter, see "Usage counts" above for `PIKKUMIES_SERVE`).
- **At most 1 MiB per source-day file, for every source alike.** A write that would push a day's file over that is skipped and counted as a drop, never a failure.
- **Taking a source's own lock never blocks.** A handful of short, non-blocking tries, then give up; a source whose lock stays held (a stuck or very slow concurrent writer) counts a failure and returns at once, rather than stalling whatever tool recording rides along with.
