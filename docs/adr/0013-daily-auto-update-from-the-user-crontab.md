# ADR-0013: Daily auto-update from the user's crontab

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-08
- Status: Accepted

## Context
The request of 2026-10-08: "should insert auto-update cron job for the user too when pikkumies installed". Pikkumies runs as the user, without root or systemd units, and `pikkumies update` already fast-forwards safely: it stops and changes nothing when a tracked file was edited (ADR-0001), regenerates the agent config, and builds a newly pinned Hermes release in the background (ADR-0009).

## Options Considered
- **Platform installer writes the cron line** (for example a PMSS wrapper). Every platform would repeat the crontab handling, and in a shell wrapper that is logic, not glue.
- **A user systemd timer.** The user manager is not a dependable part of every host; cron is.
- **`pikkumies install` keeps one tagged line in the user's own crontab**, and `pikkumies update` keeps it in step with the config.

## Decision
- `install` and `update` call `Update::schedule()`, which keeps exactly one line tagged `# pikkumies-update` in the user's crontab: a daily `pikkumies update`, its output in `local/update.log` (last run only).
- Minute and hour come from the uid, so the users of one machine do not all fetch at once.
- Every other crontab line is kept byte for byte. Nothing is written unless `crontab -l` succeeded or reported that there is no crontab yet; any other failure leaves the crontab alone and is reported.
- `autoUpdate.disabled` in `local/config.php` removes the line at the next `install` or `update`.
- A path containing `%` (which cron reads as a newline) is not scheduled.

## Consequences
- Installs stay current without anyone running update; a refused update (local edits, diverged history) is visible in `local/update.log` and `pikkumies update`.
- Removing Pikkumies by hand should also remove the tagged line (`crontab -e`), or set `autoUpdate.disabled` and run `pikkumies update` first.
- Only tested against a fake `crontab` in the suite; on a host the first `install` is the live check.

## References
- ADR-0001 (layout, install and update), ADR-0009 (pinned Hermes builds)
