# ADR-0106: A hard task leaves a separate SOP memory beside its lesson

## Metadata
- Author: Väinämöinen (for Aleksi Ursin / MCX)
- Date: 2026-10-21
- Status: Accepted. Extends [ADR-0018](0018-memory-tool-unit.md) (memory kinds) and [ADR-0041](0041-memory-metrics-save-reminder-and-wide-search.md) (the save reminder and the save hint after a search with no lesson).

## Context
The operator's request of 2026-10-21, verbatim: "whenever a task was really hard, the agent pikkumies should make a separate memory about the full process, and make it SOP level followable for that same kind of request".

What was there:
- `save` knew three kinds: lesson (`lessons/<topic>/`), reference and research. A lesson records how a problem got solved, in whatever shape the agent chose.
- doctrine.md told the agent to keep "a procedure you will repeat" as "a skill, or an SOP in doctrine/SOP/". doctrine/SOP/ is tracked by git: a file the agent writes there is never searched as memory, and an update that ships a file of the same name stops on it.
- Nothing asked for the process of a hard task to be written down step by step for the next time.

## Options Considered
- **Doctrine only** (tell the agent to write steps somewhere). Rejected: nothing makes the result followable, and it cannot be told apart from a lesson.
- **Steps inside the normal lesson.** Rejected: the operator asked for a separate memory.
- **A new `memory/sops/` directory.** Rejected: every reader of memory (search tiers, the link graph, tool-linked memories, the services list, related-lesson detection at save) would need to learn the new directory, for no gain over a separate file beside the lesson.
- **`--kind sop` stored under `reference/`.** Rejected: procedures mixed with fact sheets, labelled as reference in search.
- **A Hermes local skill per hard task.** Rejected for this: the operator named a memory, and every skill adds its description to every prompt. A procedure that should run as code is still a skill (make-a-skill SOP).
- **`--kind sop` beside the task's lessons, with required headings (chosen).**

## Decision
- `save --kind sop` writes `memory/lessons/<topic>/YYYYMMDD-sop-<slug>.md`, like a lesson, with `kind: sop` in the frontmatter and the title prefixed "SOP: " (not doubled when given). The body must have the headings `## When to use` (the requests it answers, in the user's words), `## Steps`, `## Checks` and `## What did not work`, matched as whole heading lines, case-insensitive; a save without one is refused and names each missing heading. The usual rules still hold: a link to a related memory (normally the task's lesson) or `--no-related`, sources, append-only.
- Related memories are looked up by the title as given, without the added "SOP: ".
- doctrine.md: a really hard task (several approaches before one worked, deep research, help from Väinämöinen, most of the session's work, or the user says so) gets its lesson and, separately, an SOP saved this way, never a file in doctrine/SOP/. Search first: an SOP among the hits for the same kind of request is followed step by step, each step's precondition checked on this machine before it runs; the safety rules still apply.
- The save hint at the end of a search with no lesson, and the save reminder hook, add one sentence (`MemoryMetrics::SOP_HINT`): if the task was hard, also save the SOP with `--kind sop`. No new trigger or threshold: a search that found no lesson is already the sign of a task without a known answer.
- No change to stats, search tiers or directories: an SOP is a lesson-folder file and is counted as a lesson.

## Consequences
- The next agent asked for the same kind of thing finds the SOP at tier 1 with the lesson, recognisable by its title, and has steps and checks to follow instead of rediscovering the process.
- The heading check enforces the shape only. Whether the steps are complete and correct is the writing agent's job; the doctrine asks for each step's command and a check for it.
- Deciding that a task was hard is the agent's judgment, prompted by the doctrine line and the two hints. A deterministic hard-task trigger was not built: there is no measured threshold to base one on.
- An SOP stays on the account, under memory/, which updates never touch; the existing never-store-secrets rule applies.

## References
- `pikkumies/tools/memory/pikkumies/Lessons.php` (`save`, `SOP_HEADINGS`), `MemoryMetrics.php` (`SOP_HINT`), `pikkumies.php` (search `next`), `pikkumies/hooks/search/save-reminder/save-reminder.php`, `pikkumies/doctrine/doctrine.md`, `pikkumies/tools/memory/pikkumies/{skill.md,USAGE.md}`, `pikkumies/memory/README.md`, `tests/LessonsTest.php`, `tests/MemoryMetricsTest.php`.

## Amendment 2026-10-22: offering a hard-won SOP to Pulsed Media
The operator's request of 2026-10-22, verbatim: "we should detect when pikkumies learned something genuinely valuable and difficult to acheive, it suggests sending feedback so other pikkumies can be taught about it -- it needs to be genuinely difficult, like 3+ outside sources minimum and 100+ turns, multiple failures, etc."

That request gives the measured threshold the Consequences above lacked, so the countable part of "hard" is now counted.
- `save --kind sop` adds a `share` line to its result (`Lessons::share`): `met` when the SOP's sources and text hold at least 3 distinct web addresses (a fragment and trailing punctuation dropped, compared case-insensitively), this run has at least 100 model requests (the run file's `reqs`, [ADR-0096](0096-goal-review-v2-drift-check-by-time-and-tokens.md), delegated agents included), and What did not work lists at least 2 items; `unknown` when only the request count is missing (no launcher run, as in the desktop app or a gateway) and the other two hold; else `not met`. Each count is shown.
- Readings. "Outside sources" are pages with a web address: wiki and knowledge base articles, vendor and upstream pages, issues; files on this machine and memory links do not count. The run file's own `outside` counter was not used: it counts lookup calls, so three searches of one page would read as three sources. "Turns" are read as the agent's model requests, the count the run file keeps: one user message usually takes several requests, so 100 requests is reached well before 100 user messages, which a seedbox chat almost never reaches. "Multiple failures" are the failed approaches the SOP lists, one item each; a prose paragraph counts 0, so the line errs towards not offering.
- doctrine.md, Memory: when the line says met (or unknown, and the session clearly ran past 100 model requests) and what was learned would help other Pikkumies, not only this account, the answer ends with the existing feedback line of "Tell Pulsed Media", under that line's rules (once per session, never after a no, a statement naming `/feedback`), saying it may help other Pikkumies and promising nothing. Sending stays the user's act; the feedback carries their words and the conversation, which holds the work and the SOP. Whether a send becomes documentation every install reads (the wiki and knowledge base are the official tier) is Pulsed Media's decision, so the line promises nothing.
- Rejected: a separate trigger in "Tell Pulsed Media" (that line is about what is broken and is already the longest in doctrine); attaching the SOP file to the feedback draft (the conversation already carries it, and the draft text is the user's own words); asking at the exit survey (too late, terminal-only); goal-review (a drift check with its own model cost); doctrine only (the agent cannot count its own model requests).
- The agent writes the links and items it is counted on. The line only prompts a suggestion the user decides on; it grants nothing.
- References: `Lessons.php` (`share`, `SHARE_*`), `pikkumies/doctrine/doctrine.md`, `pikkumies/tools/memory/pikkumies/{skill.md,USAGE.md}`, `tests/LessonsTest.php`.
