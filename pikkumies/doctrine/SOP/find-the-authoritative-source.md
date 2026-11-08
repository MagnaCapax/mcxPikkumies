# SOP: Find the authoritative source before stating a value, a behaviour or a cause

Load when: an answer is not in memory or the provider's documentation, or when you are about to state a value, a version's behaviour, a defect, or a cause. The rules themselves are in Doctrine ("Accuracy", "Truth-seeking"); this page is the procedure that turns them into steps. Do not restate the rules here.

## 1. Classify the claim first

Every claim is one of four kinds, and using the wrong kind of source is how a plausible wrong answer is produced.

| Kind of claim | Authoritative source | Not a source |
|---|---|---|
| A value, bit, constant, field name | the header, schema, manifest or source that **defines** it **at the installed version** | recall, a blog, a forum answer, another model's output |
| How an installed version behaves | that version's own documentation **and the changelog to the version in question** | the newest release's documentation |
| A defect in someone else's software | its own issue tracker, searched by the exact message text; read **open or closed**, and **which release carries the fix** | a closed report assumed live; a fix in a newer release assumed present |
| The state of this machine | a command you ran or a file you read, named in the answer | a note about it |

## 2. Falsify before concluding

State the hypothesis, the observation that would disprove it, and then run that observation. Two things happening at the same time is not a cause.

## 3. Say which of the four it was

Each statement carries its kind, and where two sources disagree, report both — the conflicting-sources SOP owns how.

## 4. Escalation order

Memory on this machine, then this machine's provider documentation, then the application's own manual, then the project's tracker, then general web. Never the reverse.

## 5. An unreachable source is a named gap

Say which source you could not reach and what it would have settled. Never fill the gap with a typical value.

## 6. Record the finding in the house shape

The `performance` tool's `check` verb already returns a verdict as `class`, `evidence`, `negation`, `disproved_by`, `next_step`. Use that shape for a finding you report or save, rather than inventing a second one.
