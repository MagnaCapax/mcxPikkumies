# ADR-0029: Tool doctrine: tools/<category>/DOCTRINE.md is loaded into SOUL.md

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-16
- Status: Accepted. Amended by [ADR-0076](0076-local-notes-for-a-tool-or-category.md): the user's local notes for a category follow the shipped tool doctrine in SOUL.md, and are left out first when SOUL.md would pass its size limit.

## Context
The operator's request of 2026-10-16: "let's also do tools/*/DOCTRINE.md which is loaded upon the dynamic start as part of the doctrine ... ofc we will still have separate static doctrine and agent.md type of stuff, but we need to load up like that."

Each tool category already states rules for the agent in its README (storage "Safety rules", media-servers "Safety rules (binding for every unit)", the torrent clients' ids and delete flow, the backup password and restore rules). Those READMEs are written for people and run to 6-14 KB, so the agent sees them only if it opens one. The skills index shows about 100 bytes per tool ([ADR-0015](0015-tool-units-one-skill-per-tool.md)).

`SOUL.md` is regenerated on every `install`, `update` and `run` ([ADR-0003](0003-hermes-integration.md), [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)) and Hermes sends it to the model on every turn. Hermes cuts a context file past 20 000 characters (`agent/prompt_builder.py`, `CONTEXT_FILE_MAX_CHARS`). [ADR-0010](0010-soul-and-voice.md) kept the whole `SOUL.md` at or under 8000 bytes, but only one profile (PMSS with root) was tested: the shared and generic profiles rendered 8069-8188 bytes on 2026-10-16.

## Options Considered
- **Load the category rules through a first-turn hook.** Only the first turn of a task would see them, and the operator asked for them "as part of the doctrine".
- **Put the rules in each tool's skill.md.** They load only when the skill is opened, after the agent has already picked an approach, and one rule set would repeat in every unit of the category.
- **One DOCTRINE.md per category, appended to SOUL.md at generate time.** Chosen: it is the operator's layout, it follows `tools.disabled` like the skills do, and it costs bytes only for the categories that have rules.

## Decision
- A category may have `pikkumies/tools/<category>/DOCTRINE.md`: plain Markdown rules for the agent, no heading (the category name becomes the heading), at most 1200 bytes. It sits beside the category README, not inside a tool unit, so `Units::all()` never reads it as a unit.
- `Units::doctrines($disabled)` returns `[category => text]` for every category not in `Generate::disabledCategories($config)` (so `tools.disabled`, and `root.disabled` for server-admin), sorted by category; empty files are skipped.
- `HermesConfig::renderSoul()` = capabilities, then the static doctrine in its existing order (soul, voice, doctrine, safety), then `## Tool doctrine` with one `### <category>` per enabled category. The static doctrine stays as it is: DOCTRINE.md adds to it, it does not replace it.
- Budgets, tested for every profile renderSoul gets (PMSS with root, PMSS shared with and without root, generic without root, generic shared): the core (everything but the tool doctrine) at most 8000 bytes, also with the longest agent name ([ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md)); the whole SOUL.md at most 16 000; each DOCTRINE.md at most 1200. The core was brought under 8000 by tightening text, not by raising the cap: shorter capability lines (the PMSS line only when yes, the Host line only when it is not PMSS, no repeated "own files" on shared hosts without root, a shorter footer), and fewer words in voice.md and safety.md with every rule kept.
- Seeded from rules the category READMEs already state, condensed, nothing invented: `torrent-clients`, `storage`, `backup`, `media-servers`. A category whose README states no agent rules gets no DOCTRINE.md.

## Consequences
- The agent sees each enabled category's rules on every turn, before it opens a skill. About 3 KB for the four seeded files.
- Turning a category off removes its rules from SOUL.md at the next start, the same as its skills.
- A rule now lives in the README (for people, with the reasoning) and in DOCTRINE.md (condensed, for the agent); a change to one needs the other. The tests check the sizes, not that the two agree.
- Room left: about 5 KB under the 16 000 total for more categories' rules.

## References
- ADR-0003 (SOUL.md assembly), ADR-0010 (soul and voice, the 8000-byte budget), ADR-0015 (tool units, `tools.disabled`), ADR-0022 (regenerated on every run), ADR-0032 (the agent's name line in SOUL.md)
- Tests: `tests/DoctrineTest.php`, `tests/CoreTest.php` (soul budget)
- Hermes Agent v2026.9.24: `agent/prompt_builder.py` (`CONTEXT_FILE_MAX_CHARS`)

## Amendment 2026-10-17: the size budgets measure Hermes' real cut, not chosen numbers

The 1200-byte cap per DOCTRINE.md, the 8000-byte core and the 16 000-byte total were never measured against anything. They came from reading Hermes' `CONTEXT_FILE_MAX_CHARS = 20 000` as the limit, but that is only the floor. Hermes cuts a context file past 6% of the model's context window in characters (4 per token), clamped to 20 000..500 000, and an explicit `context_file_max_chars` in config.yaml overrides it (`agent/prompt_builder.py`, `_dynamic_context_file_max_chars`). The window comes from the provider's model list, or Hermes' fallback of 256 000 tokens (`agent/model_metadata.py`), which gives 61 440 characters; a 1M-token window gives 240 000. The 20 000 floor applies only when no window is known at all. Past the cap Hermes keeps the first 70% and the last 20% and tells the agent to read the whole file, so the static doctrine near the top survives and the tail (tool doctrine) is what is lost.

Fitting text to the chosen numbers cost real rules: a load-bearing steer was dropped once (2026-10-14, restored with a test), the accuracy rules were condensed, and a torrent rule was cut to a pointer. The maintainer, 2026-10-17: "1200 bytes is abysmal safety constraint, make it more like 16k".

- `HermesConfig::SOUL_MAX` = 61 440 bytes for the whole SOUL.md, every profile, with the longest name and two notices (bytes are at least characters, so this errs safe). The separate 8000-byte core budget is gone.
- `HermesConfig::DOCTRINE_MAX` = 16 384 bytes per DOCTRINE.md.
- A rule that does not fit is a reason to look at the limit, not to shorten the rule. Before cutting text to fit any size check, find what the check protects and measure it.
- Load-bearing sentences are pinned by tests (the built-in memory steer, the accuracy rule), so a later edit fails loudly instead of losing them.

## Amendment 2026-10-18: DOCTRINE.md in any category directory ([ADR-0055](0055-recursive-discovery.md))

- `DOCTRINE.md` may sit in any category directory, also a nested one (`tools/email/imap/DOCTRINE.md`). It goes into SOUL.md under `### <category path>`.
- It is shown only while at least one enabled unit lies below it, so rules whose units are all switched off leave SOUL.md with them.
- `Units::doctrines()` returns `[category path => text]`. A `DOCTRINE.md` inside a tool unit is still not read.
