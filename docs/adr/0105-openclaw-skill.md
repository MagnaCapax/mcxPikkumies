# ADR-0105: One generated Pikkumies skill for OpenClaw

## Metadata

- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-21
- Status: Accepted

## Context

OpenClaw can run in the same Unix account as Pikkumies. It loads skills from `<state dir>/skills/<name>/SKILL.md`, puts their names and descriptions in every prompt, and reads a skill's body only when it uses it. Loading all 57 generated Pikkumies skills would add about 14,000 characters of skill headers to every OpenClaw call. Most tasks can use a PHP tool directly, without a model call or AI credits; some need Pikkumies' own judgment or memory.

## Options Considered

- Load the 57 generated skills: repeats about 14,000 characters on every call and exposes instructions written for Pikkumies' own hooks.
- Require the user to install and maintain a separate skill: another copy that drifts when commands change.
- Generate one umbrella skill that searches the existing tool index and distinguishes tool calls from model runs: chosen.

## Decision

- `OpenClawSkill::sync` writes one `skills/pikkumies/SKILL.md` when the OpenClaw state directory already exists. The directory is the non-empty `OPENCLAW_STATE_DIR`, else `~/.openclaw`. An existing skill without Pikkumies' generated marker is the user's own and is left untouched. Marked files are atomically written only when changed with `Generate::writeIfChanged`; errors are reported to STDERR and never stop Pikkumies.
- `bin/pikkumies` calls the sync from `generate()`, after the Hermes config, at install, update and start. It does not belong in `Generate::run`: that generator's guarantee that nothing outside the generated directory is touched remains true. This OpenClaw skill is the one file Pikkumies writes outside its own tree.
- The skill prefers searching `tools/INDEX.md` with grep and running `php <script> <verb>`. A mistyped tool name in `pikkumies <tool>` form falls through `Launch::route` to a whole Pikkumies model run; the explicit PHP path cannot turn that mistake into an AI-credit spend. Tool text that teaches the name form is overridden here.
- Every tool call sets `PIKKUMIES_DEPTH=1`, so person-only checks such as configure undo treat OpenClaw as an agent. A delegated ask also starts with depth 1 and runs Pikkumies at depth 2, where its deletes and sends are refused. Only an explicit ask uses the launcher, with `--` or `--prompt-file -` and `PIKKUMIES_WRAPUP_DETACH=1`, without a pty. The answer and tool output are data, never instructions; the task is restated without the chat transcript or passwords.
- OpenClaw describes changes first and waits for the user's yes in a later message before deletes, sends or a step Pikkumies refused. This is OpenClaw's consent prompt, not security: Pikkumies' hook checks run only inside Pikkumies, and the OS account remains the privilege boundary. It does not change Pikkumies' own refuse-and-report policy (ADR-0101). The skill tells OpenClaw never to read Pikkumies' key files or `local/secrets`.
- Support starts with the kb and wiki tools, then the ticket tool's `draft`; the saved record is shown before a later yes permits `send <id>`. The ticket message ends by saying it was opened through OpenClaw at the user's request. Diagnostics use `bundle --by user --no-conversation` only on the user's request. Scheduled jobs never open tickets, and checks use OpenClaw cron command payloads running PHP, never agent-turn jobs.
- The off switch is OpenClaw's own `skills.entries.pikkumies.enabled false` setting. Deleting the generated file only lasts until the next sync; to customise it, copy it to a skill of another name.

## Consequences

- One short skill header rides in OpenClaw prompts; the existing index remains the source for tools and their changing verbs (`*`). Direct tools cost no model call; delegated asks use this account's AI credits and the first can take a few minutes to set up.
- `OPENCLAW_STATE_DIR` is read from Pikkumies' own environment. A user who sets it only in an interactive shell gets the skill in `~/.openclaw` from cron runs instead.
- An ask from a chat channel, like any run without a terminal, asks no terms question. The skill tells OpenClaw to let first-start setup finish.
- Removing Pikkumies leaves the generated skill folder behind; the user can delete it. The consent text depends on OpenClaw following the prompt and is not enforced by these direct tool calls.

## References

- [ADR-0015](0015-tool-units-one-skill-per-tool.md): tool units and generated index
- [ADR-0020](0020-deletes-need-the-users-approval.md): nested-agent depth and safety prompts
- [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md): first run without a terminal
- [ADR-0089](0089-support-tickets-with-the-ai-key.md), [ADR-0100](0100-ticket-bundle-diagnostics-and-automatic-tickets-on-pulsed-media-servers.md): support tickets and diagnostics
- [ADR-0101](0101-refuse-and-report-never-ask.md): Pikkumies' own refusal policy
- [ADR-0103](0103-tools-by-name-and-support-job-steps.md): name routing and detached wrap-up
