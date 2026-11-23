# ADR-0126: A step bound on every run (`agent.max_turns` pinned) and a time limit on the one-shot's resumes

## Metadata
- Author: Väinämöinen, the orchestrating agent. This is the agent's decision, not an operator ruling. The numbers are judgements and are labelled as such below.
- Date: 2026-11-23
- Status: Proposed. Keeps [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) (Hermes unpatched) and [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md) (the managed layer); adds to the cap on `/goal` in [ADR-0047](0047-halt-guard.md), which stays as it is.

## Context
GH #30: a non-interactive run that could not finish its task kept going until something outside Pikkumies stopped it. The observed run made 30 tool calls over about 25 minutes, changed no file, gave no answer, and ended when its client process was killed (exit 143). Each step took about 50 seconds, so counting steps alone would not have ended it soon.

Facts, read in Hermes v0.22.5 (the newest release this Pikkumies has checked, `HermesInstall::NEWEST_CHECKED`), and `v0.22.7` and `v0.23.2` for the two keys:
- `hermes_cli/config_defaults.py`, section `agent`: `max_turns` is `None`, which `resolve_turn_limit` (`hermes_cli/config.py`) reads as unlimited ("caps caused silent mid-task truncation", the file says). `run_budget_seconds` is `None` (off). Before this ADR Pikkumies wrote neither (`lib/HermesConfig.php` only said that `goals.max_turns`, the `/goal` loop's own limit, stays at Hermes' default).
- `max_turns` is a hard stop. When the turn has used its model requests, `agent/chat_completion_helpers.py` `handle_max_iterations` asks the model for a last answer "summarizing what you've found and accomplished so far, without calling any more tools".
- `run_budget_seconds` is not a hard stop. At 80% of the time `agent/conversation_loop.py` `_maybe_inject_run_budget_wrapup` adds one notice to the newest tool result, and `cap_to_run_budget` shortens provider stale timeouts. Nothing ends a model that ignores the notice. It applies to every turn of every session that carries it, interactive ones and long-running Eternal windows included.
- `hermes chat` runs through a gateway. Its client refuses `--run-budget` (`hermes_cli/gateway_chat.py` `_UNSUPPORTED`: "use `agent.run_budget_seconds` in config.yaml"), takes `--max-turns` as a creation option, and a resume that repeats a creation option with a different value is refused (same file, `check_resume_policy`). `agent.max_turns` is read from the effective config, managed layer included (`gateway/session_policy.py`, `gateway/run.py` `_bridge_max_turns_from_config`: "managed overlay applies or it reverts").
- The halt guard ([ADR-0047](0047-halt-guard.md)) resumes a one-shot whose answer it judges unfinished, up to 3 times, and each resume is a new Hermes turn with a new step count.

What a legitimate run looks like, measured on one deployment's completed non-interactive support questions: 23 runs, 25 to 397 seconds, and for the 19 that kept a list of their tool calls, 2 to 33 calls (median 12). Four more ended at an older outside limit of about 400 seconds, so their real length is unknown. The sample says only that nothing that completed needed more than about 33 tool calls or about 6.6 minutes; it is small and from one deployment.

## Options Considered
- **Launcher flags** (`--max-turns`, `--run-budget` added by `lib/HaltRun.php`). Rejected: the gateway client refuses `--run-budget`, and a resume with a `--max-turns` that differs from the one the session was created with is refused, so a caller's own flag would break the halt guard's resumes.
- **A hard wall-clock limit at the launcher** (kill the client at a deadline). Rejected: killing the client is not shown to stop the gateway's turn or its spend (Hermes can detach a client without cancelling, `hermes_cli/gateway_chat_view.py`), and GH #26 reports a Hermes process tree that outlives SIGTERM. A bound that only ends the caller's wait is not what the issue asks for.
- **Pin `agent.run_budget_seconds` too.** Rejected: the key is per session, not per kind of run, so it would send the wrap-up notice into every interactive turn and every Eternal window after the time, which reshapes long tasks far beyond the issue's non-interactive runs, and it is only a notice.
- **Seed `agent.max_turns` in the user's own `config.yaml`.** Rejected: the seed reaches new installs only, and ADR-0062 says a default that must reach every install goes in the managed layer.
- **Do nothing and rely on the halt guard.** Rejected: the halt guard judges answers, and the run in the issue never produced one.
- **Pin `agent.max_turns` in the managed layer, and give the one-shot loop its own time check (chosen).**

## Decision
- `lib/RunBound.php` and two settings in `Config::defaults()`: `run.maxTurns` = **100** model requests per turn and `run.budgetSeconds` = **1200** seconds. `0` switches one off (Hermes' own, unlimited); a value that is not a whole number is the default, never "no bound". They are set by hand in `local/config.php` and are not in `Config::SETTABLE`: a bound the agent could raise is not a bound.
- `HermesConfig::writeConfig` appends `RunBound::yaml()` (`agent.max_turns`) to the managed `config.yaml` only. It is rebuilt at every start, so every existing install gets it at its next start and `hermes config set` on it is refused, like the other pins (ADR-0062 pin list: add it). `pikkumies run chat --max-turns N` still wins for one call.
- `HermesInstall::CONFIG_FACTS` gains `agent.max_turns`, so a Hermes whose defaults file no longer names it is refused rather than run unbounded. The text also occurs under `goals`, so the fact is a weak one.
- `lib/HaltRun.php` reads `run.budgetSeconds` for the one-shot loop only: when the judge says "continue" and the one-shot has already used that many seconds since it started (cold-gateway waiting counted), it stops there, says on stderr that the run used its time limit and is not finished, and returns the exit code of the run it has. The one wrap-up turn is not affected. This is what makes the time a bound on the whole question and not on each of up to five Hermes turns. It sets nothing in Hermes, so interactive sessions and Eternal windows never see it.
- The numbers are a judgement, not a measurement of a limit: 100 is about 3 times the most tool calls of a completed run above, and 1200 seconds is about 3 times the longest. A deployer whose tasks are longer sets them higher or to 0.
- What the person sees at the step limit: Hermes' last-answer request, and `soul.md` step 6 ("make sure what was asked is done or say clearly what is missing"). No new text is added to the doctrine.

## Consequences
- A run that cannot finish stops at the step limit with a summary of what it got to, instead of running on. At about 50 seconds a step the limit is far from quick on a slow upstream (100 steps is over an hour), so it is the backstop that ends a run for certain, not a short one.
- The halt guard's resumes each get their own 100 steps. A one-shot is bounded by the time (no resume starts after `run.budgetSeconds`) and, at most, by 5 Hermes turns of 100 steps. A single turn is not cut off at the time; only the next resume is withheld. There is no wall-clock limit inside a Hermes turn.
- Interactive sessions and Eternal windows get the step limit too, per message: a legitimate turn of more than 100 requests ends with a summary and the person says "continue". This is a change for existing users; `run.maxTurns` set to 0 in `local/config.php` restores the old behaviour.
- `pikkumies run chat` without `--format stream-json` is the plain passthrough and gets the pin from the config, but no halt-guard gate.
- Adding a fact changes the start-check id, so each install is checked once more at the next start (ADR-0062).
- Not verified end to end: the fake-Hermes tests show what Pikkumies writes and when `HaltRun` stops; whether a real gateway reads the key from the managed layer and ends a real run at the step limit was read in the Hermes source and needs one run on a testing machine with a real gateway (ADR-0113 records a case where fakes passed and a real gateway did not).

## References
- GH MagnaCapax/mcxPikkumies#30.
- Hermes source (v0.22.5, same keys in v0.22.7 and v0.23.2): `hermes_cli/config_defaults.py`, `hermes_cli/config.py` `resolve_turn_limit`, `hermes_cli/gateway_chat.py`, `gateway/session_policy.py`, `gateway/run.py`, `agent/conversation_loop.py`, `agent/chat_completion_helpers.py`.
- [ADR-0047](0047-halt-guard.md), [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md), [ADR-0109](0109-one-shot-progress-and-stats.md), [ADR-0113](0113-one-shot-waits-for-a-starting-gateway.md).
