# ADR-0103: tools by name inside an agent, the opening search in the first step, the wrap-up after the answer

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Proposed (number is a placeholder; renumbered when it lands). Supersedes the [ADR-0020](0020-deletes-need-the-users-approval.md) amendment of 2026-10-20 (a tool name typed inside an agent printed the tool's run line). Amends [ADR-0054](0054-tool-hooks-and-local-hooks.md) (what hook units read) and [ADR-0047](0047-halt-guard.md) (where the one wrap-up turn runs; its bound is unchanged).

## Context
A test campaign of support jobs (2026-10-20) found about a quarter of each run's steps wasted: every run opened with a memory search of its own, some spent two or three steps guessing a tool's path before reading the tool index, and two spent their last steps saving a memory note before their report. The operator, the same day: "none of the steps shoudl be skipped, BUT the ending memory save could be parallel or releasing the forntend already ... the guessing tool paths is an issue! ... no more guessing which tool path, which tools etc. ... the memory check is async". On the ADR-0020 amendment, which made `pikkumies <tool>` print `php <path> <verb>` instead of starting a second agent: "seems tliek a regresison". It cost a step every time.

Why the agent guessed: its start context names every tool (the skills index) but never says how to run one. The run line lives in each skill's body (read only after `skill_view`) and in tools/INDEX.md (23 KB, read on demand).

## Decision
1. **Tools by name.** Inside an agent (`PIKKUMIES_DEPTH` 1 or more), `pikkumies <tool> <args>` runs `php <the tool's script> <args>` with its stdio and exit code (`Launch::toolScript`, bin/pikkumies `tool`). The agent needs the name, which its start context has; no path to guess. `pikkumies -- <words>` still asks an agent, plain words are still a prompt, and the user's own shell (depth 0) is unchanged: there `pikkumies storage is full` stays a question. Every agent-facing run line uses the name form: the skill body's `Run:` line, the goal review's tool hint and one SOUL sentence. tools/INDEX.md keeps the exact paths: it is read by people too, and an exact path is not a guess.
2. **The guards see what runs.** The guards know a tool by its php form (SendGuard approves only `php <canonical path> send <id>`). Before any hook unit reads a `pre_tool_call`, the orchestrator rewrites every `[path/]pikkumies <tool>` at the start of a word in the command (terminal), code (execute_code) or data (process_manage) to `php <canonical script>`, with the launcher's own resolver (`HookPayload::normalize`). A quoted or backslashed form is matched on the text without quotes and backslashes, the view SendGuard checks too. A rewrite where nothing runs (an echo) only makes a guard stricter. A regex failure, or a payload that cannot be encoded again, blocks the call: the command never reaches the guards in the form they cannot see.
3. **The opening search in the first step.** The search stays ([ADR-0043](0043-lessons-before-documentation.md): lessons first). The first-turn instruction says to run it in the same step as the first reading (`pikkumies system-status show`, which the search gate already allows): Hermes runs one response's tool calls together, so the search costs no model request of its own.
4. **The wrap-up after the answer.** With `PIKKUMIES_WRAPUP_DETACH=1`, set by an unattended caller that reads the answer and goes (a support job), the one wrap-up turn (save a lesson, one idea: HaltJudge::WRAPUP) starts in its own session under `timeout -k 10 300`, stdin /dev/null, its output to a 0600 log in local/logs/halt-guard, and the run returns at once. The same resume runs; the bound of 3 continues + 1 wrap-up is unchanged. Without the variable, or without setsid or timeout, it runs inline as before. The halt-guard log records `wrapup-detached`.

## Alternatives rejected
- Inline tools/INDEX.md in SOUL (23 KB on every request), a PATH entry per tool (shadows real commands such as docker or rclone), native Hermes tools or an MCP server (move tools out of the terminal guards' sight), flattening the tool tree (breaks installs), a per-tool alias directory or an environment variable for the path (still a path).
- Rewriting what Hermes runs (`modify`): the launcher runs the php form itself; only what the guards read needs the rewrite.
- A pre-call block for a wrong tool path: with the name form in SOUL and in every run line, a failed path costs one step either way, and a new pre_tool_call unit enlarges every tool call's hook budget.
- Opening the search gate from the first-turn hook's own search: it matches titles by half the message's words, so a long support text rarely finds a lesson, and reading a hit costs the same step.
- A background reaper for a support job's key: the job's cleanup only reads the spend; nothing needs reaping.
- Releasing a support job on its first report block: a fix made after it would be missing from what staff read.

## Consequences
- An agent runs a tool from its name alone; the fc351b2 step and the path guesses go.
- A local hook unit sees the php form of a `pikkumies <tool>` call, not the words the agent typed.
- A launcher named through a variable or a script stays out of the guards' sight, as for the php form (AGENTS.md: the guards are safety prompts, not a security boundary).
- A detached wrap-up's spend is not in a caller's reading taken at the answer.
- Tests: tests/ToolByNameTest.php (routing, passthrough, the rewrite table, fail-closed, SendGuard and orchestrator equivalence), tests/HaltRunDetachTest.php.
