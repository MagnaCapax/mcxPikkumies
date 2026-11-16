# ADR-0114: The daily update runs upstream's `hermes pm gc`, so a superseded dependency generation does not stay

## Metadata
- Author: Väinämöinen, the orchestrating agent. This is the agent's decision, not an operator ruling.
- Date: 2026-11-16
- Status: Proposed. Keeps [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) (Hermes unpatched, its updater run only on request) and [ADR-0082](0082-web-search-opt-in-keyless-ddgs.md) (the build adds the ddgs extra). Supersedes one point of ADR-0033: `ensure()` on a complete install ran no pm at all; the daily update now runs `hermes pm gc` there. It still runs no git and no pm install, so it never updates or rebuilds a complete install.

## Context
GH #10: the runtime's file count does not fit small seedbox file quotas.

Facts:
- A build runs `pm.cli install` and then `pm.cli install --extra ddgs` (`lib/HermesInstall.php`, ADR-0082). The second call makes a new dependency generation and selects it. pm keeps the previous one: on a fresh 150 GiB test account (2026-11-14) it held 8,583 inodes, 11% of the account's 75,000-file quota. Each later `hermes pm install --extra` (for example `pikkumies websearch on` on an older build) supersedes one more.
- Hermes v0.22.5 `collect_generations(project, min_age_seconds=86400)` (`hermes_cli/runtime_state.py`) removes every generation that is not selected, whose `.lease-managed` marker is at least a day old, and that no running Hermes leases. It runs under Hermes' own runtime lock and skips when another process holds it. Venv generations always get the marker (`pm/packages.py`).
- Upstream calls it from `hermes pm gc` (`pm/cli.py` `cmd_gc`) and after every update sync (`hermes_cli/venv_sync.py`). `pm gc` also removes store entries nothing references (`_gc_store`: download caches, package versions from an older lock, expired partials).
- Pikkumies never runs Hermes' updater by itself (ADR-0033), so on an account whose owner never runs `pikkumies run update` nothing ever calls it.

## Options Considered
- **Remove the old generation ourselves.** Rejected: which generation is selected, leased or still being published is upstream state; a deletion of our own would have to copy upstream's rules and would break when they change.
- **One install call instead of two.** Rejected: pm refuses `--without` with `--extra`, and `--extra` alone skips the default package closure (`pm/cli.py`), so the build would not be the default install.
- **Drop the build-time extra.** Rejected: it reverses ADR-0082's "`on` works at once", and later extras supersede generations anyway.
- **Run `pm gc` once per build, with a stamp file.** Rejected: it misses the generations later extras supersede, and it saves a daily run whose cost is small (measured below).
- **Ask upstream for a shorter window.** Rejected: the day is upstream's guard for readers of an old generation.
- **The daily update runs upstream's `hermes pm gc`.** Chosen.

## Decision
- `HermesInstall::ensure()`, on a complete install, when `$unattended` (the daily update's post-update step), runs `hermes pm gc` after the aside cleanup: the install's own launcher, the bootstrap environment (`HERMES_HOME` is Pikkumies'), from the tree, at idle priority (`CronTick::lowPriority`). Its output goes to `local/install.log` after a timestamped line.
- It does not run while a build holds the build lock, or while an update holds the Hermes lock exclusively; it takes the Hermes lock shared, the way a start does, for as long as it runs. A failed gc changes nothing the update reports.
- A person-started `ensure()` (`pikkumies install`, the installer, the panel) runs no gc, so nobody waits on it.
- Nothing in Pikkumies deletes a file here: what goes is what upstream's collector decides.

## Consequences
- A build's superseded generation is removed by the first daily update after it is a day old; until then the account carries both.
- One extra process a day on each account with a complete install, at idle priority. Its run time and what it removed are in the install log.
- The store sweep is upstream's: if upstream's idea of a referenced store entry changes, the daily run follows it. The acceptance run checked that a gc leaves the install starting and later extras installing.
- Tests: `tests/HermesInstallGcTest.php` (the daily update runs one gc with the bootstrap environment from the tree at idle priority and logs it; a person-started ensure runs none; none while an update holds the lock; a failing gc leaves the status line; none without a complete install).

## References
- GH MagnaCapax/mcxPikkumies#10.
- Hermes v0.22.5 source: `hermes_cli/runtime_state.py` (`collect_generations`, `lease_directory`), `hermes_cli/venv_sync.py`, `pm/cli.py` (`cmd_gc`, `_gc_store`), `pm/packages.py`.
