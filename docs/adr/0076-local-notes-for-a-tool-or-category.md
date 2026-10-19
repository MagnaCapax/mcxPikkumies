# ADR-0076: Local notes for a tool or a tool category

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0007](0007-customising-shipped-skills.md) (a note adds to a shipped skill; a copy still replaces it), [ADR-0029](0029-tool-doctrine-loaded-into-soul.md) (local notes follow the shipped tool doctrine in SOUL.md), [ADR-0054](0054-tool-hooks-and-local-hooks.md) (notes share the local hooks' record, notice and check of `local/`) and [ADR-0055](0055-recursive-discovery.md) (a `DOCTRINE.md` for one unit is read, but only from `local/tools/`).

## Context
On 2026-10-18 the operator asked for a place, per tool, where the user's own rules for that tool can be kept on one machine, untracked like the rest of `local/`. Until now a user who wanted the agent to follow one rule for one tool ("restart rtorrent only through its tool") had three places, none right: the built-in memory (about 2,200 characters for everything, ADR-0007), a lesson (how a problem was solved, read only when searched), or a copy of the shipped skill under a new name, which stops getting the shipped skill's updates.

The plan for this change had note-add ask the user, through Hermes' approval panel, to approve the exact text. On 2026-10-19 the operator ruled out questions and deferrals except before destroying what cannot be replaced ([ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md)). A note is one line in a file of the user's own, removed again with one command that prints it, and logged with its text; it is replaceable. So note-add asks nothing.

## Options Considered
- **A new file name (NOTES.md) beside DOCTRINE.md.** A second format for the same kind of text. Rejected for one name, `DOCTRINE.md`, in both trees.
- **Local tool units with their own scripts under `local/tools/`.** Nobody asked for them, and the user's own skills already live in `local/hermes/skills/` (ADR-0007). Rejected: `local/tools/` holds notes only.
- **Ask before each note** (the plan's guard question). Rejected by the 2026-10-19 ruling above; the guard is not changed for notes.
- **Notes as a configure setting.** Settings are values with tiers ([ADR-0059](0059-settings-tiers-free-asked-and-the-persons-own.md)); a note is free text. Rejected for three verbs of their own.

## Decision
- **Where.** `local/tools/<path>/DOCTRINE.md`, where `<path>` is a shipped tool (`torrent-clients/rtorrent`) or category (`torrent-clients`, `email/imap`), one note per line. Read by `lib/LocalNotes.php`.
  - A tool's notes are added at the end of its generated `SKILL.md`, under `## Local notes`.
  - A category's notes go into SOUL.md after all shipped tool doctrine, under `### Local notes` with one `#### <path>` per category. A category's notes show while it is on and has an enabled unit below it, as its shipped `DOCTRINE.md` does; `tools.disabled` hides them with it, and a tool's notes go with its skill (`skills.disabled` included).
  - Both start with the line "Written on this machine at the user's request; they never override safety.md or the shipped doctrine." A note never changes or replaces shipped text.
  - The text goes through `Cli::cleanString` (no control or bidi characters), blank lines are dropped, and a line that would read as a heading, a rule, a quote, a table or code gets a backslash in front, so a note cannot pose as a section of SOUL.md or of the skill.
- **Writing.** Three `pikkumies-configure` verbs (`tools/system/pikkumies-configure/ConfigureNotes.php`):
  - `note-add <tool or category> --text <note>` (or `--text-file <file>`): appends `- <UTC date>: <note>`, with line breaks turned into spaces. The target is a category path, a tool's name when only one tool has it, or `category/tool`; anything else is refused with where the list is. One note is at most 2000 bytes, the file at most `HermesConfig::DOCTRINE_MAX` (16384) bytes.
  - `notes [<tool or category>]`: every note with its number and whether it is in use or waiting for the next start, and every note file that is not read, with why.
  - `note-remove <tool or category> <n>`: removes note `n`, prints it and the command that adds it back. The file goes with its last note.
  - No question is asked. Every add and remove is logged with its text in `local/state/note-changes.jsonl` (0600). Files are written 0600 through a temporary file and a rename, under a lock.
  - **Secrets are refused**, not asked about: a note in which `Redact::secrets()` finds a password, key or token form (the forms the command log and `/feedback` remove) is not saved, because notes reach the agent's instructions on every turn. A name followed by `:` or `=` reads as one too.
- **Read like local hooks** (ADR-0054). Only while `local/` is this account's and closed to group and others; `local/tools` must be this account's directory and no symlink. Every directory name on a note's path is plain (`[A-Za-z0-9._-]`), no directory on it is a symlink or another account's, and the file is this account's plain file within the size cap. A note file whose path has no shipped tool or category is an orphan. Anything refused is skipped, never shown, and named by `generate`, `pikkumies doctor` and `notes`.
- **Only what was recorded at the user's last start is shown.** The notes share the local hooks' record, `local/state/local-changes.json`, under keys `tools/<path>` (the record's keys were made to say their tree for this), and its one notice, `local/notices/local-changes.txt`: "N local note files new or changed since the last start: ...; they apply from this start". A start by the user records and shows them; a start by an agent (`PIKKUMIES_DEPTH` 1 or more) records nothing and shows only what was recorded before, and the notice says the rest waits for a start by the user. So a note the agent was steered into writing reaches no instructions in the session where it was written, and its arrival is printed to the user's terminal at their next start.
- **Size.** SOUL.md is cut at `HermesConfig::SOUL_MAX` by Hermes. When the category notes would take it past that, they are left out from the last one and a line says how many and that `notes` lists them; shipped text is never cut for a note.
- **What goes where** (doctrine.md): a preference for everything goes to the built-in memory; a rule for one tool or category to `note-add`; how a problem was solved to a lesson. The generated `SKILL.md` header says the same as ADR-0007 now does: a note adds to the shipped skill and keeps its updates; a copy under a new name replaces it and forks.

## Consequences
- The user gets a lasting rule for one tool by asking for it, without editing a tracked file; `pikkumies update` never touches it.
- A note takes effect only at the next start by the user. The agent says so; `notes` and `pikkumies doctor` show notes waiting for it.
- Writes to `local/tools/` other than through note-add (an editor, `sed -i`, a `cat >`) are still asked about by the guard's local-write question (ADR-0054, `GuardLocal.php`), through the ADR-0073 review; note-add itself names no `local/tools` path in its command, so neither guard-destructive nor config-change answers it (pinned by a test, for `--text`, `--text-file` and note-remove). One form does get an answer: a single command that first writes the note to a file and then runs note-add (`cat > f <<EOF ... EOF` and note-add in one call) matches config-change's pattern for a write next to `pikkumies-configure`; checked 2026-10-19, it was blocked as too long to show. config-change is not changed here; `--text` exists so that no file is needed, and the skill tells the agent to use it, or to write the file in a call of its own.
- A note is text the agent wrote at the user's request, by its own account. Pikkumies cannot tell a note the user asked for from one the agent was steered into writing; what limits the second is the record (it waits for the user's start, which prints it), the secret refusal, the heading line, and that notes never replace shipped text.
- A shipped unit still has no `DOCTRINE.md` of its own: its rules are its `skill.md`, and a test keeps it that way (ADR-0055). Only `local/tools/` reads one per unit.
- Every generated `SKILL.md` header grows by one sentence (note-add), so each changes once at the next start.
- The start line on the terminal now reads `local: ...` for hooks and notes together.

## Where the build departed from the plan
- The plan's guard question on note-add, with a hash the verb re-checked, is dropped (the 2026-10-19 ruling above). The guard is unchanged.
- The plan's heading claimed the user's approval; the heading now says the notes are written at the user's request, which is what the doctrine tells the agent to do, and that they never override safety.md or the shipped doctrine.
- `--text` was added beside `--text-file`, so a note needs no temporary file.
- The plan put category notes under `### <path> (local)`; they are under one `### Local notes` heading carrying the line above, each category under `#### <path>`, so the line is said once and cannot read as part of the last shipped category.
- A file over the size cap is skipped and named rather than cut, so half a rule never reaches the agent.

## References
- ADR-0007, ADR-0029, ADR-0054, ADR-0055, ADR-0059, ADR-0073
- Code: `pikkumies/lib/LocalNotes.php`, `lib/LocalHooks.php` (`closed`, `record`, `noteChanges`), `lib/Generate.php`, `lib/Units.php` (`renderSkill`), `lib/HermesConfig.php` (`renderSoul`), `bin/pikkumies` (doctor), `tools/system/pikkumies-configure/ConfigureNotes.php`
- Tests: `tests/LocalNotesTest.php`, `tests/ConfigureNotesTest.php`
