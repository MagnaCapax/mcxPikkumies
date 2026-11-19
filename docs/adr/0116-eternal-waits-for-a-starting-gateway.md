# ADR-0116: An Eternal waits for a starting gateway instead of ending its window

## Metadata
- Author: Väinämöinen (session 0cff0db8), incident follow-up to ADR-0115.
- Date: 2026-11-19
- Status: Accepted

## Context
After ADR-0115 stopped the start-check pile-up, the Eternals on a busy HDD seedbox host still flapped.
- Hermes' client waits a fixed 60 s for a cold gateway: `DEFAULT_ENSURE_TIMEOUT` in `hermes_cli/gateway_runtime.py` (v0.22.5).
- On that host a cold gateway took 3-7 minutes to come up, with I/O pressure full avg10 at 50-90%.
- When the client gave up, the resident `pikkumies run --eternal` ended, because it runs Hermes once. The window closed. `ensure()` then started a new window after backoff.
- Each new window read that account's Python and Hermes tree cold from disk again. That added to the load that made the boot slow in the first place.
- ADR-0113 already solves the same 60 s deadline for one-shot runs with `GatewayWait`. The Eternal path did not use it.

## Decision
- The resident runs Hermes in a loop guarded by `Eternal::rerunAfterGateway()`. Hermes runs again only when all of these hold:
  - the run was an Eternal;
  - Hermes exited non-zero, with no signal;
  - fewer than `Eternal::GATEWAY_RERUNS` (3) reruns have happened;
  - the account's gateway was starting at the moment of exit: identify answered `starting`, or there was no answer while `gateway.lock` was held or a `gateway run` process was booting;
  - `GatewayWait::run()` then reported `ready`.
- Any other exit goes to `Eternal::afterExit()` unchanged.
- The wait ends on Hermes' own lock and socket, never on a Pikkumies timer (ADR-0113). A wedged boot ends through Hermes' own startup watchdog, which also ends the wait.

## Consequences
- A slow gateway boot keeps the window, the run lock and the resume pointer. It does not count as a failure.
- A rerun's Python and Hermes reads are warm in the page cache from the run that just exited.
- A rerun only happens when the gateway really was starting, so a Hermes that fails for another reason against a ready gateway still ends at once.
- Not covered: pacing across accounts. After a reboot, every account on a host still starts its own gateway inside the 0-300 s boot stagger. Pikkumies runs as each account with no shared state, so pacing needs a host-wide design of its own.
- Not covered: an exit 0 after a lost gateway is still recorded as the person's quit (`afterExit`), which clears the resume pointer. This needs its own investigation.
- Regression coverage: `tests/EternalGatewayRerunTest.php`.

## References
- [ADR-0113](0113-one-shot-waits-for-a-starting-gateway.md): GatewayWait for one-shot runs.
- [ADR-0115](0115-eternal-start-check-lock-timeouts-and-backoff.md): start-check lock, timeouts and backoff.
- [ADR-0075](0075-eternals-primary-ask-and-ui.md): Eternal supervision.
