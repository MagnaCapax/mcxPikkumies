# ADR-0007: Customise a shipped skill under a new name; lessons get their own skill name

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted. Supersedes the "local skill with the same name" override in ADR-0001 and ADR-0003.

## Context
ADR-0001 and ADR-0003 said a user overrides a shipped skill by creating a local skill with the same name, because local skills take precedence over `skills.external_dirs`. That came from the Hermes documentation. The installed Hermes 0.19.0 code does not work that way:
- The skills index and `skills_list` prefer the local copy, but `skill_view` collects every skill with the name and refuses with "Ambiguous skill name ... Refusing to guess" (`tools/skills_tool.py`). Hermes's own sync code says such a copy creates "a name collision the loader refuses to resolve" (`tools/skills_sync.py`).
- `skill_manage create` refuses a name that already exists anywhere.

So following our own advice produced a skill the model could see but not load. This is the fourth integration fact taken from the Hermes docs that the code contradicts (three are in ADR-0005).

Separately, the first live run showed the model saving a lesson with Hermes's built-in `memory` tool instead of our `memory` skill. The skill had the same name as the tool, and the tool's own description lists "lessons" among the things it stores. The built-in memory is about 2,200 characters, goes into every turn and cannot be searched (`hermes_cli/config.py`, `tools/memory_tool.py`), so it cannot replace lesson files.

## Options Considered
- **Keep the same-name advice.** Broken in the installed Hermes.
- **Copy shipped skills into `$HERMES_HOME/skills` and keep a hash manifest**, the way Hermes syncs its own bundled skills. Makes editing in place work, but adds a manifest and a sync step that the new-name path below does not need.
- **Customise under a new name and hide the shipped one.** Uses Hermes's own `skills.disabled`, which matches by name. Chosen now.
- **Delete our lessons skill and use the built-in memory.** Loses searchable, long-form lessons and the lessons packs ship.

## Decision
- To change a shipped skill, copy it to a local skill under a new name (in `$HERMES_HOME/skills/`, which is `local/hermes/skills/`), set the copy's frontmatter `name:` to the new name (Hermes matches that field, not the directory: `agent/prompt_builder.py`, `tools/skills_tool.py`), and list the shipped name in `skills.disabled` in `local/config.php`. Pikkumies writes that list into the generated Hermes config. Never reuse a shipped name.
- The core skill `memory` is renamed `lessons`. Its description starts with "Lessons:" and says to use it, not the built-in memory tool, for how a problem got solved. The doctrine's "what goes where" section says the same. The lesson files stay in `pikkumies/memory/` and the tool stays at `tools/memory/memory.php`, so existing lessons and commands keep working.
- A contract test checks every shipped skill the way Hermes reads it: the frontmatter parses, `name` equals its directory, the description fits in 1,024 characters (Hermes cuts longer ones silently), no name exists twice, and no skill is named like a Hermes 0.19.0 tool.

## Consequences
- The README and the shipped `update` skill now give advice that works.
- Whether the rename changes which memory the model uses is not proven until the same "save a lesson" request is run live again; until then the routing is changed but unverified.
- The tool-name list in the test is tied to Hermes 0.19.0 and is re-checked when the pin moves (ADR-0008).

## References
- ADR-0001, ADR-0003, ADR-0005
- Hermes Agent 0.19.0: `tools/skills_tool.py`, `tools/skills_sync.py`, `tools/skill_manager_tool.py`, `agent/skill_utils.py`, `tools/memory_tool.py`
