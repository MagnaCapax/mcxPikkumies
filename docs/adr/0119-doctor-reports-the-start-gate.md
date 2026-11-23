# ADR-0119: Doctor reports the start gate's refusals

## Metadata
- Author: Coding agent, implementing GH #17.
- Date: 2026-11-23
- Status: Accepted. Extends [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) and [ADR-0115](0115-eternal-start-check-lock-timeouts-and-backoff.md).

## Context
`pikkumies doctor` read the model refusal ([ADR-0110](0110-refuse-start-without-a-default-model.md)) and the config keys, but
never the checks `run` makes before it launches Hermes: the launcher (`HermesInstall::bin()`) and the start check
(`HermesInstall::checkBeforeStart()`: config keys, the managed layer and the delete guard run through Hermes). A tree whose
launcher could not start its interpreter was refused by every start, with "(exit 127)" in the text, while doctor printed no
problem. ADR-0033 promises the user can see the reason of a refused start with doctor; it could not.

## Options Considered
- Copy the checks into doctor: two lists that drift, which is how the gap appeared.
- Run `verify()` in doctor on every call: answers differently from a start, which skips a checkout it already verified.
- Doctor calls the gate itself, under the locks a start check respects: chosen.

## Decision
- `HermesInstall::startRefusal()` returns why a start would be refused after the runtime exists, or `''`: a missing or
  non-executable launcher, else the problems of `checkBeforeStart()`. It is the start's own call, so it answers as a start
  does, including skipping a checkout that already passed (and recording a pass, as a start does).
- Doctor, for an installed Hermes, prints `Not starting: <reason>` after the `hermes on PATH` line, with the environment the
  start uses (`HermesConfig::envArgv()`, as `Eternal::ready()` passes). When nothing is refused it prints nothing and its exit
  code is unchanged (0, as for the model refusal).
- It does not run while an update holds the Hermes lock (`run` says that itself) or while another start check holds
  `local/start-check.lock`; a check never doubles one in progress (ADR-0115). It leaves the Eternal retry record alone.

## Consequences
- A refused start can no longer sit beside a clean doctor for the launcher and start-check refusals.
- Doctor can take as long as a start check when the checkout moved since its last pass (timeouts of ADR-0115 apply); an
  unchanged checkout costs nothing.
- While it runs, doctor holds the shared Hermes lock like a session, so `pikkumies run update` refuses for that time.
- Not covered, because they are not state doctor can read without writing: a failure while `generate` writes the Hermes
  config, the terms prompt, a dashboard without a login, and a running update.

## References
- `tests/HermesInstallTest.php` (start refusal), `tests/DoctorStartGateTest.php`
