# pikkumies-configure

Pikkumies' own settings, read from and written to `local/config.php` ([ADR-0032](../../../../docs/adr/0032-settings-configure-skill-first-run-and-install-flags.md), [ADR-0059](../../../../docs/adr/0059-settings-tiers-free-asked-and-the-persons-own.md)). Nothing goes over the network, except the model list a new model id is checked against.

| verb | arguments | changes | what it does |
|---|---|---|---|
| show | | no | One record per setting: `key` and its current value. |
| get | `<key>` | no | One setting's value. |
| set | `<key> <value>` | yes | Changes one setting, if the change is one the agent may make (`review` says which, in `changes`); a key a `PIKKUMIES_CONFIG` overlay forces is refused, since the change would not show while the overlay is in effect. Values: on/off for switches, whole days for `sessions.retentionDays` (0 = keep forever), `release` or `git/<branch>` for `update.channel`, a model id (empty = the default) for `provider.model` and `hooks.model`, names separated by commas (or `none`) for `tools.disabled` and `skills.disabled`, an absolute path for `arr.<app>.config`, a name for `agent.name`. Prints the old and new value (to go back, `set` the old one); takes effect at the next session. |
| skills | `disable\|enable <name>` | yes | Hides a shipped skill (adds it to `skills.disabled`) or shows it again. Only shipped skill names can be hidden. |
| review | | no | Every setting with its value, what it means (`means`), whether `set` writes it at all (`settable`), which change the agent may make, which asks the person and which is the person's own (`changes`), and a `warning` when one applies (sessions kept forever, no name yet, terms not accepted). |

```sh
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php review --human
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set agent.name "Little Helper"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set sessions.retentionDays 90
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set halt.disabled off
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php skills disable seedbox-apps
```

Who makes a change (ADR-0059):

| setting | the agent may | the person is asked (Hermes' approval prompt) | only the person |
|---|---|---|---|
| `agent.name`, `privacy.perfLogging`, `privacy.telemetry` | any change | | |
| `sessions.retentionDays` | longer, or 0 (forever) | shorter, also 0 to a number: Hermes deletes the older history | |
| `update.channel` | `release` | `git/<branch>`: code that has not been through a release | |
| `provider.model`, `hooks.model` | | any change; the id must be in the endpoint's model list (checked after the question, refused when the list cannot be read), and the rate it sends is shown | |
| `tools.disabled` | removing a category | adding one: the question names the category rules (`DOCTRINE.md`) that leave the agent's instructions | |
| `skills.disabled` | adding or removing a shipped skill (`skills`) | | |
| `lowBalance.disabled` | off | on: hides the low-credits notice | |
| `cron.disabled` | | either way: on stops the scheduled jobs the user set up, off lets them run unattended | |
| `hooks.reword` | on | off: a question with no local hit is then searched with the words as typed | |
| `root.disabled` | on | | off: root's tools back on |
| `searchFirst.disabled`, `halt.disabled`, `fileopsCheck.disabled`, `hooks.llm.disabled`, `autoUpdate.disabled`, `hermes.lazyInstalls` | off | | on |
| `arr.<app>.config` (where a shipped *arr tool finds its app's config) | a file this account owns | | |
| `provider.endpoint`, `provider.keyFile`, `provider.virsilipasKeyFile`, `host.*`, `terms.accepted` | | | any change |

- "Only the person" is refused with what that change does and how they make it: by hand in `~/pikkumies/local/config.php`, for the terms the first-start question or `pikkumies install --accept-terms`. `Config::set` itself refuses those changes too, whoever calls it. Hermes' approval mode is not a Pikkumies setting at all: it stays manual ([ADR-0020](../../../../docs/adr/0020-deletes-need-the-users-approval.md)).
- The question comes from the `config-change` hook; in cron and other unattended runs Hermes refuses it, so nothing changes. An "always" answer covers that one change (the same setting from the same old value to the same new one) each time it comes again, never another. Asked with the whole command: a set in a form the hook cannot read exactly, a command or code naming a setting with an asked direction next to `set` or `apply`, `Config`/`ConfigureChange`/`ConfigureSettings` called directly, and edits of `local/config.php`, of this tool, of `lib/` and of the hooks.
- Every change is logged with its old value in `~/pikkumies/local/state/configure-changes.jsonl`. `pikkumies configure undo`, the person's own shell command, sets the last one back: it shows `key old -> new (tier)` and asks y/N on a terminal (the agent can write that log too); without a terminal it makes only a free change. It refuses the person's own changes, a run from inside an agent and a run under an overlay. A second undo undoes the first.
- Defaults: `agent.name` empty (asked at the first interactive start), `privacy.perfLogging` on, `privacy.telemetry` on, `sessions.retentionDays` 0, `update.channel` `release`, `terms.accepted` off, every `*.disabled` off, `hooks.reword` on, `hermes.lazyInstalls` off.
- `update.channel` `release` makes the daily `pikkumies update` move to the newest release, never backwards; `git/<branch>` follows that branch of the same repository (`git/main`: every change as it lands). Takes effect at the next update ([ADR-0057](../../../../docs/adr/0057-release-channel.md)).
- `privacy.perfLogging` keeps performance logs on this machine only, under `~/pikkumies/local/state/memory-metrics/`: today the time and result counts of each memory search (per tier, and per documentation source by name), its words only when it found no lesson, and the time of each lesson save. Nothing is sent. Off stops new lines; files already written stay until deleted. With `sessions.retentionDays` above 0, lines older than that are pruned. `privacy.telemetry` is a setting only: no sender exists yet. Hermes' own telemetry stays off whatever these say.
- `sessions.retentionDays` N > 0 turns on Hermes' own pruning of ended sessions older than N days at start (`sessions.auto_prune`, `sessions.retention_days`); 0 turns it off, and Hermes' session store `local/hermes/state.db` grows without bound.
- `set` rewrites `local/config.php` from its values, under a lock, through a temporary file and a rename; comments in that file are not kept, and the refusals say so.
