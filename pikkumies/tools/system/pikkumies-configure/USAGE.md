# pikkumies-configure

Pikkumies' own settings, read from and written to `local/config.php` ([ADR-0032](../../../../docs/adr/0032-settings-configure-skill-first-run-and-install-flags.md), [ADR-0059](../../../../docs/adr/0059-settings-tiers-free-asked-and-the-persons-own.md)). Nothing goes over the network, except the model list a new model id is checked against.

| verb | arguments | changes | what it does |
|---|---|---|---|
| show | | no | One record per setting: `key` and its current value. |
| get | `<key>` | no | One setting's value. |
| set | `<key> <value>` | yes | Changes one setting, if the change is one the agent may make (`review` says which, in `changes`); a key a `PIKKUMIES_CONFIG` overlay forces is refused, since the change would not show while the overlay is in effect. Values: on/off for switches, whole days for `sessions.retentionDays` (0 = keep forever), `release` or `git/<branch>` for `update.channel`, a model id (empty = the default) for `provider.model` and `hooks.model`, names separated by commas (or `none`) for `tools.disabled` and `skills.disabled`, host names, addresses or `*`/`?` patterns separated by commas (or `none`) for `ssh.allow` and `ssh.deny` (the whole list is replaced), an absolute path for `arr.<app>.config`, a name for `agent.name`, whole seconds (or 0 for off) for `hooks.every.<unit name>`. Prints the old and new value (to go back, `set` the old one); takes effect at the next session. |
| skills | `disable\|enable <name>` | yes | Hides a shipped skill (adds it to `skills.disabled`) or shows it again. Only shipped skill names can be hidden. |
| note-add | `<tool or category> --text <note>` (or `--text-file <file>`) | yes | Adds one of the user's own rules for one tool or tool category, as a dated line in `local/tools/<path>/DOCTRINE.md` ([ADR-0076](../../../../docs/adr/0076-local-notes-for-a-tool-or-category.md)). `<tool or category>`: a category path (`torrent-clients`, `email/imap`), a tool's name (`rtorrent`) or `category/tool`. One note is one line of at most 2000 bytes; text that looks like a password, key or token is refused. A tool's notes are added to its skill, a category's to the tool doctrine in SOUL.md, from the next start by the user. No question is asked: `note-remove` takes a note back. |
| notes | `[<tool or category>]` | no | The notes, one record each: `path`, `n` (the number `note-remove` takes), `note`, and `state`: `in use` (recorded at the last start) or `waiting for the next start`. A note file that is not read is one record with its `problem` (no such shipped tool or category, a symlink, too big). |
| note-remove | `<tool or category> <n>` | yes | Removes note `n` and prints it, with the command that adds it back. The file goes when its last note does. |
| review | | no | Every setting with its value, what it means (`means`), whether `set` writes it at all (`settable`), which change the agent may make, which asks the person and which is the person's own (`changes`), and a `warning` when one applies (sessions kept forever, terms not accepted). |

```sh
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php review --human
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set agent.name "Little Helper"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set sessions.retentionDays 90
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set halt.disabled off
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php skills disable seedbox-apps
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set ssh.allow "nas.example.org,*.home.example"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set ssh.deny "*.example.net"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php note-add rtorrent --text="Restart it only through the rtorrent tool, never by hand"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php notes
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php note-remove rtorrent 1
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
| `feedback.ask` | off | on: lets Pikkumies now and then ask whether you want to send feedback (the question sends nothing; [ADR-0056](../../../../docs/adr/0056-feedback-what-pikkumies-sends-to-pulsed-media.md)) | |
| `cron.disabled` | | either way: on stops the scheduled jobs the user set up, off lets them run unattended | |
| `hooks.reword` | on | off: a question with no local hit is then searched with the words as typed | |
| `root.disabled` | on | | off: root's tools back on |
| `searchFirst.disabled`, `halt.disabled`, `fileopsCheck.disabled`, `hooks.llm.disabled`, `hooks.async.disabled`, `autoUpdate.disabled`, `hermes.lazyInstalls` | off | | on |
| `hooks.every.<unit name>` (seconds between a background check's runs, 60 to 3600; 0 turns it off; unset is the unit's own default, [ADR-0067](../../../../docs/adr/0067-interval-hook-units.md)) | a guard check: more often; any other: less often, or 0 | any other: more often (each run may call the model on the user's credits) | a guard check: less often, or 0 |
| `arr.<app>.config` (where a shipped *arr tool finds its app's config) | a file this account owns | | |
| `ssh.allow` (SSH hosts reached without a question, [docs/ssh.md](../../../../docs/ssh.md)) | removing a host | adding one | |
| `ssh.deny` (SSH hosts always refused; wins over `ssh.allow`) | adding a host | | removing one |
| `ssh.blockUnknown` (refuse SSH hosts on neither list) | on | off: unknown hosts are asked about again | |
| `provider.endpoint`, `provider.keyFile`, `provider.virsilipasKeyFile`, `host.*`, `terms.accepted`, `hooks.mutationCheck.holdsPerTurn` | | | any change |

- "Only the person" is refused with what that change does and how they make it: by hand in `~/pikkumies/local/config.php`, for the terms the first-start question or `pikkumies install --accept-terms`. `Config::set` itself refuses those changes too, whoever calls it. Hermes' approval mode is not a Pikkumies setting at all: it stays manual ([ADR-0020](../../../../docs/adr/0020-deletes-need-the-users-approval.md)).
- The question comes from the `config-change` hook; in cron and other unattended runs Hermes refuses it, so nothing changes. An "always" answer covers that one change (the same setting from the same old value to the same new one) each time it comes again, never another. Asked with the whole command: a set in a form the hook cannot read exactly, a command or code naming a setting with an asked direction next to `set` or `apply`, `Config`/`ConfigureChange`/`ConfigureSettings` called directly, and edits of `local/config.php`, of this tool, of `lib/` and of the hooks.
- Every change is logged with its old value in `~/pikkumies/local/state/configure-changes.jsonl`. `pikkumies configure undo`, the person's own shell command, sets the last one back: it shows `key old -> new (tier)`, makes a free change without asking, and asks y/N on a terminal before an asked one (the agent can write that log too), which it refuses without a terminal. It refuses the person's own changes, a run from inside an agent and a run under an overlay. A second undo undoes the first.
- Defaults: `agent.name` empty (the agent is Pikkumies; never asked), `privacy.perfLogging` on, `privacy.telemetry` on, `sessions.retentionDays` 0, `update.channel` `release`, `terms.accepted` off, `feedback.ask` off (offered once at the first interactive start), every `*.disabled` off, `hooks.reword` on, `hermes.lazyInstalls` off, `ssh.allow` and `ssh.deny` empty, `ssh.blockUnknown` off.
- `update.channel` `release` makes the daily `pikkumies update` move to the newest release, never backwards; `git/<branch>` follows that branch of the same repository (`git/main`: every change as it lands). Takes effect at the next update ([ADR-0057](../../../../docs/adr/0057-release-channel.md)).
- `privacy.perfLogging` keeps performance logs on this machine only, under `~/pikkumies/local/state/memory-metrics/`: today the time and result counts of each memory search (per tier, and per documentation source by name), its words only when it found no lesson, and the time of each lesson save. Nothing is sent. Off stops new lines; files already written stay until deleted. With `sessions.retentionDays` above 0, lines older than that are pruned. `privacy.telemetry` is a setting only: no sender exists yet. Hermes' own telemetry stays off whatever these say.
- `sessions.retentionDays` N > 0 turns on Hermes' own pruning of ended sessions older than N days at start (`sessions.auto_prune`, `sessions.retention_days`); 0 turns it off, and Hermes' session store `local/hermes/state.db` grows without bound.
- A list change that both adds and removes takes the stricter tier: replacing `ssh.deny` with other hosts is the person's, even though adding a host is free.
- Notes are not settings and have no tiers: each `note-add` and `note-remove` is logged with its text in `~/pikkumies/local/state/note-changes.jsonl`. A note never changes shipped text; it is added after it, under "Local notes (written on this machine at the user's request; they never override safety.md or the shipped doctrine)". Notes for a path with no shipped tool or category, notes under a symlink, and a file over 16384 bytes are not read; `notes` and `pikkumies doctor` name them. They are read only while `~/pikkumies/local` is closed to others, like local hooks ([ADR-0054](../../../../docs/adr/0054-tool-hooks-and-local-hooks.md)).
- `set` rewrites `local/config.php` from its values, under a lock, through a temporary file and a rename; comments in that file are not kept, and the refusals say so.
