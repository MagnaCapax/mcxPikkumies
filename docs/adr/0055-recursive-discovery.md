# ADR-0055: Tools, hooks and tool doctrine are found at any depth

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends [ADR-0015](0015-tool-units-one-skill-per-tool.md) (layout, `tools.disabled`), [ADR-0029](0029-tool-doctrine-loaded-into-soul.md) (where DOCTRINE.md sits) and [ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md) (which directory is a hook unit).

## Context
The operator's request of 2026-10-18: "hooks, tools, doctrine etc. all of that stuff should be recursively dynamic in certain formats, that makes development easy and the systme as flexible and dnyamic as possible, with minimalistic core code, while maximizing easy , structured flexibiltiy".

Until now every reader was fixed at two levels. `Units::all()` globbed `tools/*/*/skill.md`, `Units::doctrines()` globbed `tools/*/DOCTRINE.md`, `HookUnits::all()` took every `hooks/*/*` directory, and `Generate` cleaned up `*/*/SKILL.md`. Four tests had the same globs. A tree like `tools/email/imap/<tool>/` was not read at all, and a nested skill left behind by a removed unit would never be deleted.

Hermes already reads nested skills, checked 2026-10-18 against Hermes v2026.9.24:
- It walks a skills directory with `os.walk` and takes every `SKILL.md` (`agent/skill_utils.py`, `iter_skill_index_files`).
- A skill at `email/imap/fetch/SKILL.md` gets the category `email/imap` (`agent/prompt_builder.py`: `"/".join(parts[:-2])`).
- Its skill list is cached for 30 seconds (`tools/skills_tool.py`, `_SKILLS_CACHE_TTL_SECONDS`).

So the work is all on the Pikkumies side.

## Options Considered
- **Keep two levels.** No code, but every deeper layout needs new globs in four places and four tests, and the cleanup glob has to be found too.
- **A walker in each reader.** Three or four copies of one rule, which drift.
- **One walker in `lib/` with a marker file per tree (chosen).** One rule, used by tools, hooks, doctrine and the cleanup.
- **For hooks, only a directory with `hook.md` is a unit.** Rejected: a unit whose `hook.md` is lost or misnamed would turn into a category and its check would stop running. ADR-0030 makes such a unit broken, and a broken unit blocks every tool call.

## Decision
- **`lib/Tree.php`** walks `tools/` and `hooks/`:
  - Directories directly under the root are always categories (`tools/<category>/`, `hooks/<category>/`).
  - Below that, in `tools/`, a directory with `skill.md` is a unit.
  - In `hooks/`, a directory is a unit when it has `hook.md` or `<name>.php`, or has no subdirectory. Without a valid `hook.md` it is a broken unit and blocks every `pre_tool_call`, as before.
  - Any other directory is a category, named by its path (`email/imap`).
  - The walk never goes into a unit, and skips `lib/` and dot-directories.
  - It goes 8 levels deep. A symlinked directory, a directory it cannot read, or a category at level 8 that still has subdirectories comes back as one broken unit with that one reason. It is never dropped and never walked further.
- **Users of the walker.** `Units::all()`, `Units::doctrines()`, `HookUnits::all()` and the stale-skill cleanup in `Generate`. A nested unit's skill is `local/generated/skills/<category path>/<tool>/SKILL.md`. The cleanup looks through the whole generated directory and removes directories that become empty, up to the generated directory itself.
- **Tool doctrine.** A `DOCTRINE.md` in any category directory goes into SOUL.md under `### <category path>`. It is shown only while at least one enabled unit lies below it. A `DOCTRINE.md` inside a unit is not read; a test keeps shipped units free of one.
- **`tools.disabled`.** Each entry is a category path and also covers everything below it. Paths match by whole segments, so `media` does not switch off `media-servers`. Existing category names work as before. One function decides it, `Units::isDisabled()`, used by generate and by the doctrine.
- **Names.** A unit name is still unique across the whole tree and must not be a Hermes name. `Units::all()` now reports a reserved name, or a second unit with a name already taken, as an error, so generate skips it and says so. The first unit with the name keeps working. The reserved list moved from `tests/ContractTest.php` to `Units::RESERVED_NAMES`.
- **Order.** Units are sorted by category, then name. A category is compared with a trailing slash, so each category's units stay together and categories keep the order the old glob gave them. Within a category, names are in plain `strcmp` order: `pk` now comes before `pk-x`, where the old glob of `pk/skill.md` put it after. No shipped category has such a pair. Each index prints one heading per category path.
- **Tests** take their unit lists from `Units::all()` and `HookUnits::all()` instead of globs. A new contract test checks that every shipped hook unit has no errors.

## Consequences
- A category can be split into subcategories by adding directories. No code change is needed.
- Two-level trees are unchanged. On the shipped tree, the generated skills, `tools/INDEX.md`, `hooks/INDEX.md`, the tool doctrine in SOUL.md and the Hermes hook entries came out byte for byte the same before and after, on PHP 7.3 and 8.2, with four different `tools.disabled` lists.
- Changes in behaviour, none of which affect the shipped tree:
  - A symlinked unit directory used to work and is now a broken unit.
  - A directory that cannot be read used to drop its units without a word, which for hooks meant a check stopped running. It is now one broken unit that says the directory cannot be read, and in `hooks/` it blocks every `pre_tool_call`.
  - A category `DOCTRINE.md` with no unit below it no longer reaches SOUL.md. A test already required one.
  - A duplicate or reserved name was caught only by a test. Now it is also an error at runtime.
  - A `hooks/<category>/lib/` directory is skipped. Before, it would have been a broken unit.
  - `tools/<category>/skill.md` is still not a unit: depth-1 directories are categories in both trees. This keeps the ADR-0015 layout.
- Hermes's skill cache sees a new skill at once only when it lands directly in a top-level category; one added to `email/imap/` shows up when the 30-second cache runs out (`tools/skills_tool.py`, `_skills_scan_signature`). A category named like a directory Hermes skips (`node_modules`, `venv`, ...) would hide its skills from Hermes, so do not use such names.
- `lib/Units.php` is now about 190 lines, because the reserved list moved in.
- `tests/ArchitectureTest.php` still lists PHP files with two-level globs. It is a lint over the shipped files and needs a change only when the first nested category is added.
- Tool hooks, local hooks and local `DOCTRINE.md` notes, including a unit's own `DOCTRINE.md`, are separate changes (planned as [ADR-0054](0054-tool-hooks-and-local-hooks.md)). This ADR only finds what is shipped.
- Where the build departed from the plan: the plan first numbered this ADR-0054, which is now planned for tool hooks. A review suggested dropping the runtime name errors once local tool units were left out; they were kept, because nested shipped units can collide too and generate is where the user sees a problem. The index renderers group by category in the order they are given instead of sorting, because `all()` already sorts. The plan said the walker skips symlinks; it reports a symlinked directory as a broken unit instead, so in `hooks/` one blocks every `pre_tool_call` rather than a check disappearing.

## References
- ADR-0015 (tool units), ADR-0029 (tool doctrine in SOUL.md), ADR-0030 (hook units)
- Code: `pikkumies/lib/Tree.php`, `lib/Units.php`, `lib/HookUnits.php`, `lib/Generate.php`
- Tests: `tests/TreeTest.php`, `tests/GenerateTest.php`, `tests/ContractTest.php`, `tests/DoctrineTest.php`, `tests/UnitsDoctrineTest.php`, `tests/GuardUsageContractTest.php`
- Hermes Agent v2026.9.24: `agent/skill_utils.py`, `agent/prompt_builder.py`, `tools/skills_tool.py`
