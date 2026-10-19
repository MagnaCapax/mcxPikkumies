---
name: pikkumies-configure
description: Show, review and change Pikkumies' own settings (the agent's name, also "from now on call yourself X", performance logging, telemetry, session history retention, which version updates follow, the model, the hooks, tool categories, skills).
---

# Configure Pikkumies

Use it when the user asks about Pikkumies' own settings: what the agent is called, which model it uses, whether it may log or send performance data, how long session history is kept, which tools, skills and hooks are on.

- `review` first when the user wants to know what is set: it explains every setting, says in `changes` which change you may make, which one asks the user and which one is theirs alone, and warns about the ones that need a look (for example session history kept forever).
- `set <key> <value>` only for a change the user asked for. Say what you set and its old value; it takes effect when the next session starts.
- Changes that tighten something go through. Changes that loosen something, cost credits or delete history (another model, turning off a tool category, hiding the low-credits notice, turning the scheduled-job ticker on or off, turning search rewording off, a shorter session history, `update.channel git/<branch>`) make Hermes ask the user first: run the plain `set` command on its own and let the user answer. Never try to get around the question, and never edit `~/pikkumies/local/config.php` instead: edits of that file are asked about too, and nothing in them is checked.
- Turning a safety layer off (the search-first gate, the halt guard, the fileops check, the hooks' model calls), root's tools back on, auto-update off and runtime downloads on and the provider, key files, `host.*` and the terms are the user's own. The tool refuses them and says how the user does it; tell the user that, do not run it again or edit the file for them.
- "From now on call yourself Kalle" (or "always", "your name is") asks for a lasting name: `set agent.name Kalle`, then say it applies from the next session. Answering as Kalle only in this chat is not what was asked.
- A model (`provider.model`, `hooks.model`): pick an id the `credits` tool's `models` lists; the tool checks it against the endpoint's list and shows its rate. `pikkumies -m <model>` uses another model for one session without changing the setting.
- To hide a shipped skill (for example after copying it under a new name): `skills disable <name>`; `skills enable <name>` shows it again.
- To go back, `set` the old value: `set` prints it. `pikkumies configure undo` is the user's own shell command (it asks them y/N); never run it yourself.
- Never accept the terms of service or privacy policy for the user: do not run `set terms.accepted` at all, not even to see the refusal. The person accepts them when the first interactive session asks, or installs with `pikkumies install --accept-terms`.
- `update.channel`: `release` (default) updates to the newest release only; `git/main` (or another `git/<branch>`) follows every change on that branch. Set it only when the user asks for releases or for the latest code; it applies at the next `pikkumies update`.
- `privacy.perfLogging` (on by default) keeps performance logs on this machine only: today the time and result counts of each memory search (per tier, and per documentation source by name), its words only when it found no lesson, and the time of each lesson save, under `~/pikkumies/local/state/memory-metrics/`. Off stops new lines; files already written stay until deleted. Telemetry is a setting only: nothing is sent, because no sender exists yet. Say so if asked.
