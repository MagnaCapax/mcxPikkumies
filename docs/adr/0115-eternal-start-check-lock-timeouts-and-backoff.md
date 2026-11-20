# ADR-0115: Serialise Eternal start checks and back off after failure

## Metadata
- Author: Coding agent, implementing the requested incident fix.
- Date: 2026-11-19
- Status: Accepted

## Context
A 2026-11-19 production incident on shared seedbox hosts exposed overlapping
start checks. Enabling Eternals adds a config fact and changes the verification
ID, so accounts with keys recheck their unchanged Hermes checkout. On busy HDD
hosts the checks exceeded the one-minute tick interval and failed repeatedly.
The incident report counted 276 hook-test processes across 15 accounts and load
up to 1209; the pile-up returned after reboot.

`Eternal::ensure()` deliberately runs before `cron.lock`. Readiness probes the
shared Hermes lock, which excludes an update but cannot serialise readers.
Verification had neither command timeouts nor failure backoff.

## Options Considered
- Move ensure behind `cron.lock`: changes supervision during scheduled jobs.
- Make the Hermes lock exclusive: conflicts with running sessions and changes
  the runtime/update locking contract.
- A separate exclusive check lock, command timeouts and persisted retry delay:
  chosen; reuses the existing lock helper and Eternal backoff calculation.

## Decision
- `Eternal::ready()` takes `local/start-check.lock` exclusively without waiting
  using `EternalLock::take()`. It holds the close-on-exec handle across the retry
  decision, `checkBeforeStart()` and retry-state write or removal. A busy or
  unavailable lock returns `not-ready` with reason `busy`, running no check.
  The existing shared `HermesInstall::lock(false)` probe stays unchanged.
- Each command in `HermesInstall::verify()` runs through coreutils
  `timeout -k 10 300` (manual runs on a busy disk have taken over 270 seconds, so a shorter cap would fail healthy checks), before the complete setpriv/env prefix when present.
  Status 124 or 137 fails verification with a command-specific timeout problem,
  even if the command printed a successful check result before timing out.
- A failed Eternal start check writes `fails` and `nextCheck` (Unix seconds) to
  `local/start-check.json`. Retry delays use `Eternal::backoffTicks()` in minutes:
  1, 2, 4, 8, 16, then 30. Before that time readiness returns `not-ready` with
  reason `start-check` without checking. A pass removes the failure record.
- Direct `HermesInstall::checkBeforeStart()` callers keep their existing retry
  behaviour; their commands receive the same timeouts. All `Eternal::ready()`
  callers share the check lock and backoff. The host marker behaviour is unchanged.

## Consequences
- Minute ticks cannot multiply a slow Eternal check within an account. Failed
  checks stop retrying every minute as their failure count grows, across ticks
  and reboots. The lock file is retained; closing the handle releases its lock.
- A repaired installation can wait up to 30 minutes for automatic recovery.
  No new setting, daemon, cross-account lock or runtime dependency is added;
  coreutils timeout is already used by scheduled jobs.
- Regression coverage: `tests/EternalStartCheckTest.php` exercises readiness,
  lock ownership and persisted retries; `tests/HermesInstallTest.php` exercises
  timeout statuses for both commands with the setpriv/env prefix.

## References
- Incident report supplied with this change: 2026-11-19,
  source baseline `d1c013d`.
- [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md): runtime lock and start verification.
- [ADR-0075](0075-eternals-primary-ask-and-ui.md): ensure before the job lock and Eternal backoff.

## Amendment 2026-11-20: the reboot stagger runs before the start check
`Eternal::ensure()` called `ready()` before `bootStaggerReady()`, so only the Eternal launch was staggered: after a
host reboot every account's minute tick ran its start check, a cold Hermes launch from that account's own checkout,
in the same minute. On a shared host with spinning disks those cold reads queue behind each other, and the start
checks were among the processes waiting on disk after a boot. `ensure()` now checks the stagger first,
so in the first 600 s after boot an account's start check, its readiness transition and its update scheduling wait
for its 0-300 s slot as the launch already did. Outside that window nothing changes. Guard: `tests/EternalTest.php`
(ensure staggers after a reboot before the start check runs).
