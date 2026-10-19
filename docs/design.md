# What Pikkumies is meant to be

- **It runs as you.** By default it works under the user's own account, inside that user's slice of the server. Root access is one more capability layer on top of that. When the agent detects sudo or root deterministically, it can do full server administration. When it doesn't, it manages only that slice. A config switch turns the root layer off.
- **It knows where to look.** Basic memory (lessons), documentation sources it downloads and searches locally (`pikkumies/config/documentationSources.md`, [ADR-0011](adr/0011-documentation-sources-and-search-first.md)), and a small doctrine: verify before claiming, cite the source and its trust level, ask before anything destructive. On the first turn of a task it searches before it acts.
- **It grows by tool units.** Every capability is one directory under `pikkumies/tools/<category>/<tool>/`: a skill, its tool, USAGE.md and README.md. Categories may nest to any depth, and tools, hooks and category doctrine are all found by one walker ([ADR-0055](adr/0055-recursive-discovery.md)). Every tool prints JSON Lines for the agent and `--human` for people. All units load on every machine; the agent is told what the machine allows, and each skill checks that it applies ([ADR-0015](adr/0015-tool-units-one-skill-per-tool.md) to [ADR-0017](adr/0017-human-output-option.md)).
- **It asks before deleting data.** A torrent's files, a backup prune or an rclone sync go to the user for approval in the chat ([ADR-0020](adr/0020-deletes-need-the-users-approval.md)). Bulk permission, owner and rename commands are re-checked once, and their targets are listed to a gzipped file first, so a mistake can be checked against what was there ([fileops-check](../pikkumies/hooks/guard/fileops-check/README.md), [ADR-0048](adr/0048-fileops-check-recheck-and-file-list.md)). It configures itself: settings that tighten something it changes when you ask, those that loosen something, cost credits or delete history are asked the same way, and turning a safety layer off stays yours ([config-change](../pikkumies/hooks/guard/config-change/README.md), [ADR-0059](adr/0059-settings-tiers-free-asked-and-the-persons-own.md)). Before it connects to another machine over SSH it says why, and your allow and deny lists decide where ([SSH](ssh.md), [ADR-0068](adr/0068-ssh-connections-need-a-stated-intent-and-the-persons-allow-and-deny-lists.md)).
- **Checks per tool, and your own.** A hook in `hooks/tools/<tool>/` runs only for that tool's calls, and the same units under `~/pikkumies/local/hooks/` are checks of your own on one machine: they run after the shipped ones, never block by failing, and an update never touches them. When several checks ask about one call, you see every question at once, and when together they are too long to show (a local check next to a long one such as feedback's) the call is blocked, naming the checks; a local hook runs only after a start by you printed that it is new ([tool hooks](../pikkumies/hooks/tools/README.md), [ADR-0054](adr/0054-tool-hooks-and-local-hooks.md)).
- **It learns, can undo, and can run tasks later.** Hermes' own self-improvement is on from install: after a turn it may save a memory or write or patch a skill, and every skill it writes is scanned before it runs again. File edits are snapshotted, and `/rollback` in a session restores them. Ask it to do something on a schedule and the job runs from a per-minute line in your crontab, without the privileges sudo would give ([ADR-0024](adr/0024-hermes-features-on-at-install.md), [ADR-0025](adr/0025-scheduled-jobs-per-minute-tick-under-no-new-privs.md)). `cron.disabled` in `local/config.php` removes that line.
- **Your model, your keys.** It talks to any OpenAI-compatible endpoint. A deployment chooses its own provider and keys.
- **It is not only for sysadmin work.** General investigation and research are in scope too.

## Layout

```
pikkumies/            the runtime (~/pikkumies)
  bin/pikkumies       entrypoint
  lib/                core PHP
  tools/<category>/<tool>/  tool units: skill.md, USAGE.md, README.md, <tool>.php; categories may nest
                      (tools/<category>/<sub>/<tool>/), each with its own README.md and DOCTRINE.md; INDEX.md is generated
  skills/             core skills not yet moved into units (Hermes SKILL.md format)
  hooks/<category>/<hook>/  hook units: hook.md, README.md, <hook>.php, nested the same way; orchestrator.php runs them per event; INDEX.md is generated
                      hooks/tools/<tool>/<hook>/ runs only for calls of that tool (ADR-0054)
  config/             documentationSources.md: the wikis and knowledge bases it searches
  doctrine/           soul, voice, doctrine, safety (in SOUL.md); antipatterns.md, common mistakes read on demand (ADR-0063); SOP/ for procedures
  memory/             your lessons, one markdown file each (never tracked)
  hermes/             Hermes Agent: an unmodified upstream git checkout (never tracked); its Python, tools and dependencies are in local/hermes/
  include/            install state, never tracked: the Hermes ready marker and build lock
  local/              your config, machine profile and agent state (never tracked); local/hooks/ holds your own hook units
docs/                 install, commands, design and name pages; adr/ holds the architecture decisions
tests/                php tests/run.php
```

[Back to the README](../README.md)
