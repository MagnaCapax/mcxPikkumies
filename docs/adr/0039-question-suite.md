# ADR-0039: A question suite outside CI asks the installed agent real questions against several models and grades them

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Builds on ADR-0038 (the `PIKKUMIES_CONFIG` overlay and the live source check).

## Context
The request of 2026-10-18: a suite of questions, run outside CI because the provider keys are not in CI and every run spends tokens, that tests various models and where adding a question is easy. ADR-0038 made the search-first gate strict and gave one file to force the provider, model, light model and host family per run; nothing yet asked the agent a real question end to end and looked at what it did.

Forces:
- What matters is the whole agent as a user starts it: the `pikkumies` launcher, the generated Hermes configuration, the hooks and the gate. Hermes' `--format stream-json` prints every tool call and the final answer with token counts, so a run can be graded from its output alone.
- The agent's terminal tool runs as the OS account. A throwaway HOME does not confine it: a command can still read and change the account's real files (an earlier test run searched the real home).
- `pikkumies install` and `post-update` write the account's crontab through `crontab` on PATH. Setting `autoUpdate.disabled` or `cron.disabled` does not keep them away from it: `Update::schedule` then removes the tagged line from the real crontab.
- Tokens cost money; the operator named the cost.

## Options Considered
- **Hermes' own batch runner.** It drives the bare agent, not the launcher, so the hooks, the gate and the generated configuration are not what is tested, and it does not grade. Rejected.
- **Run the questions in CI.** The keys are not in CI, every run spends tokens, and a model's answers vary between runs, which makes a poor pass/fail gate. Rejected by the operator.
- **One install per target.** Each install builds the runtime (minutes) and may pick up a newer Hermes, so targets would be compared on different versions. Rejected: one install per run serves every target.
- **Force the cron settings off for the run.** Deletes the account's real cron lines (see above). Rejected: settings stay as shipped, and a fake `crontab` is first on PATH instead.
- **A separate tool that reads Hermes' hook logs for the tool order.** The stream already shows every tool call in order. Rejected.

## Decision
- `tests/testing/questions/run.php` (outside the CI test glob `tests/*Test.php`) with `lib/Questions.php` (parse, select, lint, the gate's read-only list, the stale-fact guard), `lib/Checks.php` (the checks and the self-test), `lib/Sandbox.php` (throwaway install, snapshot and reset, removal), `lib/Asker.php` (running commands with a timeout, asking, stream parsing, killing strays), `lib/Targets.php` (the targets file, the forced settings and the command environment), `lib/Judge.php` and `lib/Report.php`, each kept near the repository's 150-line file target. Documented in its `README.md`.
- **Questions** are Markdown files in `questions/`: front matter of `key: value` lines (a key may repeat), then the question. Checks: `calls`, `first_call` (the first tool call that is not on the search-first gate's read-only list, read from the gate file so the two never differ), `not_calls` (regular expressions over the tool name and its input as JSON with slashes unescaped), `contains`, `contains_any`, `not_contains` (any case, over the final answer) and `judge` lines. `source` and `note` are for people. An unknown key, a file with no check or a bad regular expression is a lint error.
- **Stale-fact guard.** A question tagged `source:<name>` with `contains` lines is checked against the downloaded copy of that source after its family's refresh; when a `contains` value is no longer there, the question is not asked and is reported as `stale`, counted apart in the scorecard. A changed page then shows up as a question to fix, not as the agent getting worse.
- **Self-test.** `--self-test` lints every question file and grades the known-bad records in `samples/` (one per check type), each of which must fail its named check while its good twin passes. It needs no network and runs first in every asking run, so a broken question file never costs a token.
- **Isolation.** One throwaway root per run under the system temp dir: a copy of the checkout's tracked files (never `local/`), installed with its own `pikkumies install`, HOME in the root, a fake `crontab` first on PATH, provider, Hermes, SSH-agent and XDG variables dropped from the environment. Each target is forced through the ADR-0038 overlay. Before each question the root goes back to its snapshot (new files in HOME, the lessons, the downloaded documentation and Hermes' state deleted, links unlinked and never followed, Hermes' session database restored), and every process of the account whose environment has the throwaway HOME is killed after each question. The real `crontab -l` is compared before and after; a difference ends the run with exit code 3.
- **Interlock.** An asking run refuses unless the account's real home has `.pikkumies-qa-user`, and always refuses root: the operator states that the account holds no fleet or SSH keys. `--list`, `--self-test` and `--regrade` ask nothing and need no marker.
- **Cost.** `--max-tokens N` is required for an asking run (`0` = no limit, chosen on purpose). The agent's and the judge's tokens are summed; no new question starts once the ceiling is reached.
- **Judge.** An optional OpenAI-compatible model from the targets file. The answer goes to it as delimited data; it replies one JSON line per criterion, and a YES counts only with a quote that is in the answer. Judge and code scores are separate columns.
- **Results** go outside the repository, private (directory 0700, files 0600), with every key masked: `results.jsonl`, the masked transcripts, and a scorecard per target and per target and tag.

## Consequences
- A model, a pikkumies change or a new Hermes can be compared on the same questions with one command; adding a question is one file.
- The suite must run as a dedicated account; on any other account it refuses. This is a deliberate cost: the OS is the only boundary for what the agent's terminal can reach.
- Results are a report, not a gate: answers vary between runs, and the judge is a model's opinion, so it never mixes with the exact code checks.
- The documentation each host family downloads is part of the snapshot for the rest of the run, so a later family's questions can also see an earlier family's documentation (tier 3 of ADR-0031).
- A run stopped with Ctrl-C skips the final crontab comparison and the cleanup; the README says to compare `crontab -l` with the saved copy by hand.

## References
- ADR-0011, ADR-0031, ADR-0038 (search-first gate, search tiers, overlay and live source check), ADR-0025 and ADR-0013 (the crontab lines), ADR-0033 (the runtime build).
- `tests/testing/questions/README.md`.
