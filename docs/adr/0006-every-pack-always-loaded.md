# ADR-0006: Every pack is always loaded; detection only describes the machine

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Superseded by ADR-0015 (tool units; every unit is always generated unless its category is in tools.disabled). It had superseded the "Enabling" decision and the root-pack consequence of ADR-0002.

## Context
ADR-0002 enabled a pack only when its `detect` rule matched the machine (or it was listed in config). The point was relevance: keep skills that do not apply out of the agent's way. Measured against Hermes Agent 0.19.0, that is not needed:
- Hermes puts only a compact index of skills in the system prompt (name and description, about 100 bytes per skill; 404 bytes for four skills, `hermes prompt-size`). A skill's full text loads only when the model opens it (`agent/prompt_builder.py`, `build_skills_system_prompt`).
- Hermes's own comment there argues against hiding skills: models do not go looking for skills the index stops showing them.
- Pikkumies tools are PHP command-line scripts that the skills call, so they add no tool schema at all (the 17 built-in tool schemas are about 39 KB).

So shipping every pack costs a few hundred bytes, and the agent can use a skill whenever a situation calls for it, which is what "gets when needed" should mean. The operator's direction on 2026-10-04: "we should just dump all into the system always since it's just text files".

## Options Considered
- **Keep detect rules, enable all by default.** Keeps code that decides nothing.
- **Gate skills with a `requires:` frontmatter that builds `skills.disabled`.** That is relevance filtering again, with the discoverability cost above.
- **Remove packs and move their skills into core.** Loses grouping and the per-pack opt-out.
- **Load every pack; say once what this machine allows.** Chosen.

## Decision
- Every pack under `packs/` is loaded: its `skills/` go into `skills.external_dirs` and its `hooks/` into the hook config.
- `packs.disabled` in `local/config.php` leaves a pack out. `root.disabled` also leaves out the `root` pack. `packs.enabled` and `packs.auto` no longer exist and are ignored if set.
- `pack.json` has `name` and `description` only; the `detect` key is gone.
- Detection (ADR-0004) stays. Its result opens SOUL.md as a short "This machine" block: root (available and how, not available, or turned off in config) and whether the seedbox platform is present. Nothing else from the profile goes there, because SOUL.md is sent to the model on every turn.
- Because every pack loads everywhere, a pack skill checks that it applies before acting, and a pack hook decides from the tool call it receives. Pack `doctrine.md` fragments are dropped: none existed, and always-on doctrine belongs in core.

## Consequences
- Adding a pack is still adding a directory and a manifest, now without a detect rule.
- The root pack's skills are listed on machines without root. They grant nothing: privilege comes from the OS (AGENTS.md), and the server-admin skill stops at its first step when root is not available.
- The index grows by about 100 bytes per skill. If it ever matters, `hermes prompt-size` measures it.

## References
- ADR-0002 (packs), ADR-0004 (root detection), ADR-0005 (Hermes facts verified against source)
- Hermes Agent 0.19.0: `agent/prompt_builder.py`, `agent/skill_utils.py`
