# ADR-0041: Memory metrics, a save reminder, and the wide search in deep-research

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Extends ADR-0018 (memory unit), ADR-0031 (search tiers) and ADR-0032 (`privacy.perfLogging`, which until now kept nothing).

## Context
The operator's three requests of 2026-10-18, verbatim:
- "/vplan that when it comes time to search memory + kb + wiki + other docs it's all subagent driven side by side, parallel to be as fast as possible and adversarially double check, iteratively (say 3 round loop) to verify the results."
- "/vplan that when ever a search like that happens a memory lesson is stored about it"
- "/vplan that we collect performance metrics for the memory, how many times searched, how many hits, how mnay times hits, how often misses, how many lessons in the memory etc."

What was found:
- The memory search is local and takes about a second: it ranks the cached files (`DocSources::search`). Reading the hit pages and checking them is the slow part, so parallel subagents speed up reading and checking, not the lookup.
- The deep-research skill already used memory first, `delegate_task` for wide questions, exact quotes and a saved result, but only for the web (tier 4).
- `recordSearch` kept one `last-search.json` per account (for the search-first gate): no history, no counts.
- `privacy.perfLogging` existed, default on, but nothing logged.

## Options Considered
- **A new wide-search skill.** Rejected: a second skill for one procedure; deep-research already holds the delegation, quote and save rules.
- **Run the subagents from the search command itself.** Rejected: the command cannot spawn agent children, and every search would spend credits.
- **Metrics in the existing hook log.** Rejected: that log keeps 7 days and no payload; search counts need months and the search words.
- **Monthly JSON Lines behind `privacy.perfLogging`, a `stats` verb, a once-per-search reminder hook, and a first part in deep-research (chosen).**

## Decision
- **Metrics** (only while `privacy.perfLogging` is on), under `local/state/memory-metrics/` (directory 0700, files 0600), `DocSources::appendMetric`:
  - `searches-YYYYMM.jsonl` (UTC), one line per memory search, from `recordSearch`: `ts`, `words` (the search text, at most 500 bytes), `mode`, `tiers`, `hits` per tier, `paths` (first 3), `ms`.
  - `saves-YYYYMM.jsonl`, one line per `save`: `ts`, `kind`, `topic`, `path`, `after_miss` (the newest search, under 2 hours old, had no tier-1 hit).
  - Kept until deleted; nothing is sent. The perfLogging texts in `pikkumies-configure` and `docs/install.md` say what is kept.
- **`stats [--days N] [--human]`** (memory unit, default 30 days): searches; with any hit and with a lesson (tier-1) hit, count and %; misses and the last 10 miss texts; lessons on disk by kind and topic; lessons saved and how many followed a miss; documentation pages per source. `--human` is a table with 10-cell bars (doctrine/voice.md, Reports). `pikkumies doctor` prints `memory: N lessons · M searches in 30 days · P% found something · K misses`, without the search part when there are no search metrics.
- **Save at the end of a search.** A search with no tier-1 hit ends its `next` with the exact `save` command. The hook unit `hooks/search/save-reminder` (`pre_llm_call`, fail open, 5 s) adds one line on a later turn when the newest search had no tier-1 hit, no memory file changed after it, and it is under 2 hours old. A marker (`save-reminder.last`, the search time) makes it fire once per search. It reads `last-search.json` and file times only, so it works with perfLogging off; no model call, no network. `searchFirst.disabled` turns it off.
- **Wide search** (deep-research, part 1, tiers 1-3): whenever a lesson does not settle the question, one memory search; round 1, one `delegate_task` child per source group among the top hits (at most 4) returning answer, exact quote and path; round 2, one checker child per answer that re-reads the quote and checks it against this machine read-only (confirmed, contradicted or unsupported); round 3 only for what is contradicted or missing; at most 3 rounds; then the answer with sources and always a saved lesson, settled or "not settled". The web stays part 2.

## Consequences
- The memory's use can be measured: how often a search finds a lesson, which questions miss, and whether misses turn into lessons.
- The search words are kept on disk while perfLogging is on (default on). They stay in the account's own `local/state`, mode 0600, and are never sent; turning the setting off stops new lines, and the files can be deleted by hand.
- Each wide search spends the user's credits on up to 4 children a round and 3 rounds. The local lookup does not get faster; reading and checking run side by side. The skill relies on Hermes running 4 children at once, to be confirmed on the first live run.
- The reminder is account-wide like `last-search.json`: a miss in one session can remind another session within 2 hours. It is a prompt for the agent's habits, not a security boundary (AGENTS.md).

## References
- ADR-0011, ADR-0018, ADR-0030 (hook units), ADR-0031, ADR-0032.
- `pikkumies/lib/DocSources.php` (`recordSearch`, `appendMetric`), `pikkumies/tools/memory/pikkumies/` (`MemoryMetrics.php`, `MemoryStats.php`, `Lessons.php`, `pikkumies.php`), `pikkumies/hooks/search/save-reminder/`, `pikkumies/tools/research/deep-research/skill.md`, `tests/MemoryMetricsTest.php`.
