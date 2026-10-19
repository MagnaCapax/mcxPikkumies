# ADR-0024: Hermes features on at install: guard scan, checkpoints, security-guidance, background review, curator

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-15
- Status: Accepted

## Context
The operator's request of 2026-10-15: "make sure all the hermes cool shit is enabled upon install". The pinned Hermes v2026.9.24 ships a self-improvement loop (skills written from experience, a background review fork, a curator that prunes/archives them) and a few opt-in safety/quality features that Pikkumies' generated `config.yaml` left unset, so every install ran at Hermes' own out-of-the-box defaults rather than a deliberate choice.

Facts checked 2026-10-15 in the pinned source (`hermes_cli/config_defaults.py`, `hermes_cli/plugins_discovery.py`):
- `skills.guard_agent_created` defaults `False` (:1450): a static security scan of a skill the agent just wrote, before it can run again. The agent can run the same code unscanned via `terminal()` anyway, so this mostly blocks prose with risky keywords — pure gain, no model cost.
- `checkpoints.enabled` defaults `False` (:475-495): a per-turn working-directory snapshot (`/rollback`), size-capped (`max_snapshots: 20`, `max_total_size_mb: 500`, `max_file_size_mb: 10`) and auto-pruned (`auto_prune`, `retention_days: 7`) by Hermes itself. Pikkumies pins only `enabled`; the caps stay at Hermes' own values.
- `plugins.enabled` is an opt-in allow-list, `None` (nothing) until the user or an installer sets it (`_get_enabled_plugins`, plugins_discovery.py:97-103). The bundled `security-guidance` plugin (`plugins/security-guidance/`) is a static scan of file writes — no model call, no cost — and ships with Hermes but is inert until enabled.
- `auxiliary.background_review.enabled` already defaults `True` (:789): the post-turn self-improvement fork (save memory / patch a skill). `curator.enabled` already defaults `True` (:1475): background pruning/archiving of agent-created skills (never hub-installed ones), inactivity-triggered, no cron daemon.

## Options Considered
- **Leave everything at Hermes' defaults.** Two of the five (background review, curator) already match what this ADR pins; the other three (guard scan, checkpoints, security-guidance) would stay off with no record of that being a choice rather than an oversight.
- **Turn on every optional feature, including consolidation and cron-spawned scheduling.** Rejected (see Decision): consolidation is an aux-model cost with no measured benefit yet, and cron-spawned scheduling is an unattended-escalation risk (see [ADR-0025](0025-scheduled-jobs-per-minute-tick-under-no-new-privs.md)'s cron-tick design, which exists precisely because cron jobs run with nobody watching).
- **Pin only what changes behaviour, leave the two already-on features unset.** Rejected (round 4 of the design loop): an unset key silently tracks whatever Hermes' own default becomes on the next pinned-release bump ([ADR-0009](0009-hermes-from-a-pinned-release-tarball.md)); pinning the two headline features protects the operator's intent across an upgrade even though today's value matches.

## Decision
`HermesConfig::renderYaml()` adds, each a deliberate choice, not a restated default:
- `skills.guard_agent_created: true` — turn on the free static scan (off by default).
- `checkpoints.enabled: true` — turn on `/rollback` (off by default); Hermes' own size caps are left untouched.
- `plugins.enabled: ["security-guidance"]` — the only plugin enabled; the allow-list itself defaults to nothing.
- `auxiliary.background_review.enabled: true` and `curator.enabled: true` — pin the two already-on headline features so a future Hermes default change cannot silently turn them off underneath an existing install.

Deliberately **not** set, so a future contributor does not treat their absence as an oversight:
- `curator.consolidate` — stays at Hermes' own `False`. It is an LLM consolidation pass (umbrella-building across skills) with a real aux-model cost on every run; nothing in this repository has measured whether that cost is worth it yet. `hermes curator run --consolidate` remains available as a one-time manual override.
- `cron.allow_agent_scheduling` — stays at Hermes' own `False`. It lets a cron-spawned agent use the cronjob toolset to schedule MORE cron jobs itself ("cron-librarian" pattern) with nobody watching between ticks. ADR-0025 is Pikkumies' own answer to "how do due jobs get ticked at all"; letting an unattended run create new unattended runs is a separate, larger decision this ADR does not make.
- `auxiliary.background_review.max_input_tokens` — left unset (runtime derives a budget from the active model's context window); no number here has a measured basis yet, and the proxy accounting this launch is held behind ([ADR-0021](0021-web-access-off-unless-configured.md)'s launch hold) is what will eventually bound real cost, not a guessed token cap.
- `model`/model-selection keys anywhere in this block — AGENTS.md: never name a provider or model in code; model choice is entirely the deployer's config.
- Browser keys — ADR-0021 already covers browser/lazy-install behaviour; nothing here duplicates it.

## Consequences
- A fresh install gets the self-improvement loop (skills from experience, reviewed, pruned/archived) plus a free security scan and a free rollback point, with no extra model calls beyond what Hermes already runs by default for the two pinned-on features.
- Filesystem checkpoints write into `~/.hermes/checkpoints/` (own home, size-capped at 500MB): this counts toward the account's own disk quota on a shared PMSS host, same as any other Hermes state.
- `pikkumies doctor` reads the generated `config.yaml` back and prints one line per feature, so a write failure (disk full, a bad render) is visible rather than silently assumed.

## References
- ADR-0009 (pinned builds), [ADR-0015](0015-tool-units-one-skill-per-tool.md) (skills), [ADR-0020](0020-deletes-need-the-users-approval.md) (approvals), ADR-0021 (web access / lazy installs), ADR-0025 (the cron ticker this ADR deliberately does not turn into agent-spawned scheduling), [ADR-0026](0026-hermes-uses-only-its-own-logins.md) (adopt_external_logins, the third pin from the same request)

## Addendum 2026-10-15: measured Hermes process RSS; delegation concurrency stays at Hermes' default
Measured this session in a scratch `HERMES_HOME` under `/tmp/` (never the real `~/.hermes`), sampling `/proc/<pid>/status` `VmRSS` every ~30ms for the life of the process (`hermes-v2026.9.24/venv/bin/python3`, no provider endpoint/model/key configured — a genuinely empty account):
- `hermes cron tick` with zero due jobs (idle path): **~66-68 MB** RSS, three repeated samples (67564, 67836, 67584 KB).
- `hermes cron tick` dispatching one due job (building the job's agent context up to the point it raises "no model configured" and marks the run `failed` — before any provider contact): **~89-91 MB** RSS (91092 KB sampled once; the idle-path figure is the more repeatable baseline).
- No subagent/delegation process was ever spawned in any of these runs (the job never got far enough to call `delegate_task`), so this is a single-process measurement, not a fan-out one.

`tool_loop_guardrails.loop_caps.max_subagents` defaults to 50 (Hermes' own default, left unset by Pikkumies — round 3 of the design loop rejected inventing a tier-based number with no measured basis). The round-3 rule this addendum resolves: only add a tier-based cap if `10 x RSS` would exceed a small account's own memory limit. Using the higher measured figure (91 MB) as the conservative input: `10 x 91 MB ≈ 910 MB`, against the small-tier ceiling `Detect::TIER_THRESHOLDS['small']['memMb'] = 1024 MB` (`lib/Detect.php`). 910 MB does not exceed 1024 MB.
- **Decision: no tier-based `max_subagents` switch is added.** Hermes' own default (50) stays. This is a recorded measurement, not a guess: if a future Hermes release's per-agent RSS grows meaningfully (a different pinned version, ADR-0009), this number should be re-measured before trusting the same conclusion.
