# ADR-0113: A one-shot question waits for a gateway that is still starting

## Metadata
- Author: Väinämöinen, the orchestrating agent. This is the agent's decision, not an operator ruling.
- Date: 2026-11-13
- Status: Proposed. Extends [ADR-0109](0109-one-shot-progress-and-stats.md); keeps [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) (Hermes unpatched).

## Context
GH #12: a one-shot question on a freshly built runtime failed with `Gateway starting: deadline` before any output, zero tokens, no tool call, on 3 of 3 runs.

Facts, read in Hermes v0.22.x source (`hermes_cli/gateway_runtime.py`, `gateway_client.py`, `gateway/control_socket.py`, `gateway/status.py`):
- A `hermes chat` client first calls `ensure_gateway_runtime` with `DEFAULT_ENSURE_TIMEOUT = 60.0` s. If no gateway runs it spawns one, detached, and polls. At the deadline it returns `starting: deadline` and the client exits. The spawned gateway keeps booting.
- `ensure` never starts a second gateway while one is starting: a gateway that holds `gateway.lock` reads as `starting`. So a client run again meets the boot in progress and attaches once the gateway is up.
- The control socket (`gateway.sock`, or the path in `gateway.sock.path`) answers `identify` with a `state`; the client treats anything but `starting` (and the other refusals) as a verdict.
- The lock is a `flock` the gateway process itself takes (`gateway/status.py` `_try_acquire_file_lock`) and holds for its whole life. The client's spawn only starts the process, so between the spawn and the lock the process exists and the lock does not.
- Measured on a busy HDD host: a QA measurement of 2026-10-24 showed the gateway coming up 3 s after the client gave up, and a cold boot of about 14 minutes was measured in the investigation behind this ADR, against the fixed 60 s. In the issue's own log the control socket was listening 1 min 50 s after the gateway's `gateway.start` event.
- Hermes ends a wedged boot itself (`hermes_startup_watchdog.py`, armed at process entry), which ends the process and releases the lock.

## Options Considered
- **Patch Hermes' timeout.** Rejected: ADR-0033 keeps Hermes vanilla and updatable; a patch is lost or conflicts at the next `hermes update`.
- **A longer fixed deadline** (an environment variable or a config key, if Hermes had one). Rejected: it is a guess about how slow the disk is. Too short fails again on a slower host; too long hides a dead gateway. The 60 s is Hermes' choice and the real boot time is not ours to predict.
- **Fall back to a local run while the gateway starts** (the second idea in the issue). Rejected here: a local run is a different execution path from the gateway's, with its own session and tool behaviour, and the gateway would still boot beside it.
- **Wait for the gateway's observed state, then run the same command again.** Chosen.

## Decision
- `lib/GatewayWait.php`. After a Hermes run ends, the wait applies only when its error is exactly `Gateway starting: deadline` and the run spent no tokens and made no tool call (`StreamProgress::finishRun` hands the error and both counts to a hold callback). Such a run did nothing, so running it again repeats nothing.
- The wait ends on observed state, with no total deadline and no stall timer: **ready** when the control socket answers `identify` with a state other than `starting`; **gone** when the gateway lock is not held by another process, no `gateway run` process of this account for this `HERMES_HOME` exists, and no socket answers. A probe of the socket allows 2 s to connect and answer, the same as Hermes' own discovery. Progress goes to stderr as one line at the start and then one per 60 s of waiting, with the gateway log's last line, through the same renderer as the steps (off with `--no-progress`).
- `lib/HaltRun.php`: on a hold, wait; on **ready**, run the identical command again, and repeat; on **gone**, say the held error and end as before. The error is held only on the stream-json render path of a prompt-path question, which is the only path that sees it; the plain passthrough is unchanged. A question read from stdin (`--query-file -`) is not run twice, because the first run took the stdin. The failed run is not counted in the `--stats` figures.
- The error is never swallowed: when no re-run follows, it prints as always (ADR-0109: errors always print).
- Not done: `gateway.unmanaged_idle_exit_seconds`. An idle gateway ends itself, and a re-run that starts after that window would start a fresh cold boot. This is untested; set it only if QA shows the re-run client starting after the idle window.

## Consequences
- A one-shot question on a cold gateway returns its answer after the boot instead of failing, or fails with a different, specific Hermes error. It does not end with `Gateway starting: deadline` and zero output unless the gateway is gone.
- The wait is as long as the boot. A gateway that starts and never answers `identify` while its process lives is waited for until Hermes' own watchdog ends it.
- The match is on Hermes' error text. A Hermes release that rewords it turns the wait off silently, as before this ADR; the test with the exact text is the tripwire when Hermes is updated.
- A stale `gateway.sock` left by a dead gateway is not taken as ready: the socket must answer.
- Tests: `tests/GatewayWaitTest.php` (trigger conditions, waiting while the lock is held or a gateway process exists, ready, gone, progress cadence, the lock, socket and process probes), `tests/HaltRunRenderTest.php` (fails once with the deadline, then answers: one answer on stdout, progress on stderr, no deadline error; gone, tokens spent and stdin questions keep the error and run once).

## References
- GH MagnaCapax/mcxPikkumies#12.
- Hermes source: `hermes_cli/gateway_runtime.py` (`ensure_gateway_runtime`, `DEFAULT_ENSURE_TIMEOUT`), `hermes_cli/gateway_client.py`, `hermes_cli/gateway_runtime_start.py` (`spawn_unmanaged_gateway`), `hermes_cli/gateway_runtime_discovery.py`, `gateway/control_socket.py`, `gateway/status.py`, `hermes_startup_watchdog.py`.
