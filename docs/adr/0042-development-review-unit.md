# ADR-0042: The code-review unit: one review in four passes, the project's own rules first

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Adds the tool category `development` ([ADR-0015](0015-tool-units-one-skill-per-tool.md) tool units).

## Context
The request of 2026-10-18, verbatim: "[plan] the tools/development/ toolset, doctrine etc. for pikkumies, lightweight of ours here -- but we need atleast code review, liightweight adversarial review, review cwe, code quality review that code confroms to our ruels, ADRs, engineering principles."

What was found:
- Pikkumies had no way to review code. Users ask it to look at their scripts, and contributors to this repository had rules in `AGENTS.md` and decisions in `docs/adr/` but nothing that checked a change against them before a commit.
- A sample of this repository's recent commits kept feature ADRs up well, while some security changes touched no ADR (`development/engineering-principles.md`, rule 9). That is a conformance gap a review catches.
- The four asks share all their input: the diff, the changed files, the tests, the project's rules. Only the questions differ.
- A user's project has its own rules, or none. Holding a user's code to this repository's rules would be wrong; holding it to nothing when it has no rules would leave the conformance pass empty.
- `review` is a Hermes built-in name (`tests/ContractTest.php`), so the unit cannot be called that.
- A git repository's own config can run commands on read-only git calls: `core.fsmonitor` runs on `git status` (checked with git 2.47 in this session). The project under review may be someone else's code.

## Options Considered
- **Four units (code review, adversarial review, CWE review, conformance review).** Four skills in the index on every turn, four copies of "read the diff and the rules". Rejected.
- **A review that applies the Pikkumies engineering principles to every project.** Wrong for projects with their own rules. Rejected.
- **A script that judges the code itself (linters, pattern lists).** Language-specific, large, and still blind to intent; the agent reads code better than a pattern list. Rejected.
- **No script, skill text only.** The agent would guess which rules files exist and which files changed, and would read a hostile repo's git config while doing so. Rejected for a small read-only helper.
- **One unit, four passes, the project's own rules first, a read-only helper (chosen).**

## Decision
- **Unit** `pikkumies/tools/development/code-review/` (`skill.md`, `USAGE.md`, `README.md`, `code-review.php`, `CodeReviewGit.php`). The skill is the procedure; any coding agent can follow it by reading `skill.md`.
- **Four passes, reported separately**: correctness; adversarial (claims, premises, what would disprove them, simpler or deleting fixes, duplication, unrequested additions); weakness classes (a short CWE checklist, each finding through two gates: is the data really non-public, is the behaviour really not by design); conformance.
- **Conformance reads the target project's own rules first**: root Markdown files named in capitals (AGENTS.md, CONTRIBUTING.md, SECURITY.md, a coding agent's instruction file; not README, CHANGELOG, LICENSE, NOTICE, HISTORY), `.github/CONTRIBUTING.md`, `development/*.md` and `docs/adr/`. Each finding names its rule or ADR. Only a project with none of these is checked against `development/engineering-principles.md` of the Pikkumies checkout, and the report says it fell back. A pattern finds the agent instruction files, so no agent product is named in the code.
- **Report** per `doctrine/voice.md` Reports: result first, a status line per pass, a findings table (severity, file:line, finding, fix). Severity: critical, should fix, minor. The unit reviews; it changes code only when the user asks.
- **Helper** `code-review.php context [--base REF] [--path DIR]`: the project root, the rules files and ADRs, the fallback flag and the principles path, and the changed files with added and removed lines, current length and the 150-line target. Default change: uncommitted changes and untracked files, or `HEAD~1..HEAD` on a clean tree; `--base` compares a ref to the working tree.
- **Read-only and safe on someone else's repo**: only `git rev-parse`, `git diff --numstat` and `git status --porcelain`, every word escaped (`Update::exec`, since PHP 7.3 `proc_open` takes no argv array); a ref starting with `-` is refused; `core.fsmonitor=false`, `--no-ext-diff`, `--no-textconv`, `GIT_OPTIONAL_LOCKS=0`. Files are read only to count lines: regular files, not symlinks, up to 2 MiB.
- Contributors to this repository run the unit before committing (`AGENTS.md`).

## Consequences
- One skill covers all four asks, for users' code and for this repository's own changes.
- The review is the agent's reading, guided by a checklist: it can miss what a dedicated scanner would find. The CWE list is short on purpose and is not a security audit.
- The fallback principles exist only in a full checkout (the install is one, [ADR-0001](0001-layout-install-and-update.md)); without the file, `principles_found` is false and the skill has no fallback text.
- The helper reuses `Update::exec` rather than adding another command runner.

## References
- ADR-0001 (layout), ADR-0015 (tool units), [ADR-0016](0016-jsonl-is-the-default-output.md) and [ADR-0017](0017-human-output-option.md) (output).
- `AGENTS.md`, `development/engineering-principles.md`, `pikkumies/doctrine/voice.md`, `pikkumies/tools/development/code-review/`, `tests/ReviewUnitTest.php`.
