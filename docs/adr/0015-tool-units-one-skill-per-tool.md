# ADR-0015: Every capability is a tool unit with one skill, generated at launch

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted. Supersedes ADR-0002 and ADR-0006 (packs) and the skill-directory parts of ADR-0003 and ADR-0005. Its hook parts are superseded by ADR-0030.

## Context
The request of 2026-10-14 asks for tools for torrent clients, the *arr apps, media servers, storage and backup, with "all tools into pikkumies/tools/ and categorized well, then all have that USAGE.md and README.md", "single skill per tool, like /rtorrent", "placed on tools/{tool category}/{tool}/skill.md", "On launch the skills are dynamically copied and generated for in hermes use from those tools", a tools/INDEX.md "so agent can read it efficiently", and then: "everything should be like this! including memory tools/memory/pikkumies/ {save,search,append}".

Today a capability is spread over three places: a skill in `pikkumies/skills/` or `packs/<pack>/skills/`, a script in `pikkumies/tools/<area>/`, and a USAGE.md beside the script. Packs group skills by machine type.

Facts this rests on, checked 2026-10-14 against Hermes v2026.9.24:
- Hermes loads a skill only from a file named exactly `SKILL.md` under a skills directory, searched recursively (`agent/skill_utils.py`, `iter_skill_index_files`).
- A skill name must not equal a Hermes tool name, and two skills with one name make `skill_view` refuse both (ADR-0007; `tests/ContractTest.php` keeps the reserved list).
- Hermes puts about 100 bytes per skill (name and description) into the system prompt; the full skill loads only when opened (ADR-0006).

## Options Considered
- **Keep packs and add tools inside them.** Two groupings (pack and area) for one thing, and the skill still lives apart from its tool.
- **Commit `SKILL.md` in each tool directory and let Hermes find it.** No launch step, but the verb list in the skill is a hand copy of the code, and hiding a disabled category needs a Hermes `skills.disabled` list kept in step.
- **One directory per tool holding everything; the skill is generated at launch.** The operator's layout. The generated skill takes its verb list from the tool's USAGE.md, so it cannot drift, and a disabled category is simply not generated.
- **One dispatcher per category (`torrent.php --client=rtorrent`).** DRY across clients, but it makes one skill per category, against "single skill per tool".

## Decision
- **Layout.** Each tool lives in `pikkumies/tools/<category>/<tool>/` and holds:
  - `skill.md`, with frontmatter of `name` (equal to the directory) and `description` only; the body says when to use the tool and how;
  - `USAGE.md`, whose verbs table (`| verb | arguments | changes | what it does |`) is the one list of the tool's verbs, followed by examples;
  - `README.md` for people: what the tool controls and how it finds it on PMSS and on other providers;
  - `<tool>.php`, one entry script with the verbs as subcommands (`php <tool>.php <verb> [arguments] [--human]`). A unit without a script is a procedure skill.
- **Shared code.** `pikkumies/lib/` holds what all tools use: argument parsing, output (ADR-0016, ADR-0017), HTTP, and detection. `tools/<category>/lib/` exists only when two or more tools of a category share real code.
- **Same verbs across a category.** The tools of one category use the same verb names and record fields, listed in `tools/<category>/README.md`. A contract test checks every tool of the category against that list. Both arrive with the first category that has more than one tool (the torrent clients); a category of one tool needs neither.
- **Generated at launch.** `install`, `update` and `run` refresh `local/generated/skills/`, a directory only Pikkumies writes. For every unit whose category is enabled they write `<category>/<tool>/SKILL.md` = a header ("generated from tools/<category>/<tool>/skill.md; to change it, copy it under a new name into your own skills", ADR-0007) + `skill.md` + the verbs table from USAGE.md + the command line and the path of USAGE.md. Hermes groups its skills index by the category directory. Each file is written only when it changed, by writing a temporary file and renaming it, and files of removed or disabled units are deleted, all under a lock, so a running session never reads half a file. They also refresh `pikkumies/tools/INDEX.md` (gitignored): one line per tool with its verbs and a one-line description. `skills.external_dirs` points at `local/generated/skills/`; the user skills in `local/hermes/skills/` are never touched. A unit that fails to parse is reported and skipped; Hermes still starts.
- **Packs go.** `packs/` is removed and its skills become units: seedbox apps → `tools/seedbox/seedbox-apps/`, server administration → `tools/server-admin/server-admin/`; the core skills system status and update become `tools/system/system-status/` and `tools/system/pikkumies-update/`. The lessons and documentation skills stay in `pikkumies/skills/`, still listed in `skills.external_dirs`, until the memory unit `tools/memory/pikkumies/` replaces them (ADR-0018). `tools.disabled` lists category names, and `root.disabled` keeps its meaning: it hides the server-admin category and the agent is told not to use root. `packs.disabled` is not mapped: Pikkumies has not been released.
- **Names.** The skill name is the tool directory name. The contract test checks uniqueness, the Hermes reserved names, and the 1024-character description limit.

## Consequences
- One directory tells the whole story of a tool, and adding a tool is adding a directory.
- The agent finds tools from the skills index or by reading `tools/INDEX.md` once.
- Hermes never loads a stale skill: removed or disabled tools disappear at the next launch.
- `local/generated/` and `tools/INDEX.md` are generated and never tracked, so updates stay fast-forward (ADR-0001). This amends the AGENTS.md and ADR-0001 wording "state goes under local/": a generated file may sit in a tracked directory when it is gitignored, because the update check ignores untracked files (`Update.php`).
- The skills index grows by about 100 bytes per tool; it is measured with `hermes prompt-size` as tools are added.
- The SOP for making skills, tools, hooks and packs is rewritten as "make a tool unit".

## References
- ADR-0001, ADR-0002, ADR-0003, ADR-0005, ADR-0006, ADR-0007; ADR-0016, ADR-0017, ADR-0018
