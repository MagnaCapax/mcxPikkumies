---
name: code-review
description: "Code review of a change: correctness, adversarial check, weakness classes (CWE), conformance to the project's own rules and ADRs; reports, changes nothing unless asked."
---

# Code review

Use it when the user asks you to review code, a change, a commit, a branch or a patch, or asks whether a change follows the project's rules. Contributors to Pikkumies run it before every commit. You review and report; you change code only when the user asks you to, after the report.

## 0. Gather

1. Run `context` inside the project (`--path DIR` for another directory, `--base REF` for a branch or an older commit; ask the user which base when they named one). Add `--limit 0` when the status line says `truncated`.
2. Read every rules file and the ADR titles it lists. Read the newest ADRs whole, and any older ADR whose title touches what the change touches.
3. Read the whole diff that `compared` names (`git diff HEAD`, `git diff HEAD~1 HEAD` or `git diff REF`; untracked files are new, read them whole) and every changed file whole, not only the changed lines: a bug often sits next to the change.
4. Read the tests that cover the changed code, and the callers of every changed function (search for its name).

## 1. Correctness

Bugs, edge cases and error paths. For each changed function: empty, zero, missing, huge and malformed input; a failing command, file or network call; a second run (is it idempotent?); old data and old callers (does anything that worked before break?). Check that tests exist, test the real path, and can fail.

## 2. Adversarial

Write down what the change claims (what it fixes, what it guarantees) and what it assumes. For each:
- What would prove it wrong? Look for that.
- What was missed: a caller, a platform, a config, a concurrent run?
- Is there a simpler fix, or one that deletes code instead of adding it?
- Does it duplicate something the project, the OS or a library already does?
- Does it add anything nobody asked for (a setting, a limit, a feature)?

## 3. Weakness classes

Go through this list; skip what the change cannot touch.
- Command or argument injection: shell strings built from input, an input that starts with `-` read as an option.
- Path traversal and symlink races: `..`, absolute paths, following a link someone else controls, check-then-use.
- Unsafe temporary files: predictable names, world-readable modes, shared directories.
- Secrets in logs, error messages, argv or the environment (other users can read argv).
- Missing authorization: an action or a read that does not check who asks.
- Unbounded resource use: memory, disk, time, output, retries, recursion.
- Unsafe deserialisation or include: `unserialize`, `eval`, including a path built from input.
- Cross-tenant reads: one user's data, files or processes reachable by another.

Before you report one, pass two gates, and drop the finding if either fails:
- **Is the data actually non-public?** Reading what anyone may read is not a leak.
- **Is the behaviour not by design?** Read the ADR or comment that decided it; a documented, deliberate choice is not a weakness (you may still question the decision, as a minor).

Name the CWE number when you are sure of it.

## 4. Conformance

Check the change against the target project's own rules: the files `context` listed (AGENTS.md or another coding agent's instruction file, CONTRIBUTING, SECURITY, `development/` principles) and its accepted ADRs. Name the rule or the ADR for every finding (`AGENTS.md: camelCase`, `ADR-0020`). Typical checks: language and version limits, naming, file size target, tests with the change, an ADR for a significant change in behaviour, interface, security or workflow, and anything that works around an accepted ADR instead of superseding it.

Only when `context` says `fallback: true` (the project has no rules of its own), check against the bundled engineering principles at the `principles` path, and say in the report: "This project has no rules files; checked against the Pikkumies engineering principles." Never apply the bundled principles over a project's own rules.

## Report

First line: the result in a few words (for example "2 should-fix, 1 minor; no critical"). Then each pass on its own line with a status mark: ✅ no findings, 🟡 should-fix or minor findings, ❌ a critical finding. Then one table of findings:

| severity | where | finding | fix |
|---|---|---|---|
| critical | lib/Foo.php:42 | the user's path reaches the shell unescaped | escapeshellarg each word |

Severity: **critical** (data loss, a security hole, a broken build or old users broken), **should fix** (a real bug or a broken rule), **minor** (style, a clearer name, a missing comment). Each finding has a `file:line` you read and a source: the line itself, a rule or ADR, or a command you ran. Say what you did not check. No findings: say so plainly; never invent one to fill the table.
