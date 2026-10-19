# code-review

A code review in four passes, for the agent and for any coding agent that reads `skill.md`:

1. **correctness**: bugs, edge cases, error paths;
2. **adversarial**: the change's claims and premises, what would disprove them, simpler or deleting fixes, duplication, things nobody asked for;
3. **weakness classes**: the common CWE classes of scripts and web code, each finding checked against two gates (is the data really non-public, is the behaviour really not by design);
4. **conformance**: the change against the reviewed project's own `AGENTS.md`, contributing guide, `development/` principles and ADRs, naming the rule or ADR for each finding. Only a project with none of those is checked against the engineering principles shipped with Pikkumies (`development/engineering-principles.md` in the checkout), and the report says so.

The report puts the result first, then a status line per pass and a table of findings (severity, file:line, finding, fix). The agent changes code only when asked.

`code-review.php context` finds the starting material without guessing: the project root, which rules files and ADRs exist, and which files changed, with line counts and the files now over the 150-line target. It only reads; the project's own git config cannot make it run anything ([ADR-0042](../../../../docs/adr/0042-development-review-unit.md)).

Pikkumies contributors run it on their own change before committing (AGENTS.md).
