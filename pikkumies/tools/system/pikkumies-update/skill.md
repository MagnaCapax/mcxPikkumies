---
name: pikkumies-update
description: Update Pikkumies itself to the latest version without losing local changes, explain what to do when the update refuses, and how Hermes is updated separately.
---

# Update Pikkumies

Run `~/pikkumies/bin/pikkumies update`.

- **"updated A -> B"**: done. The agent config was regenerated, so new tools and skills take effect in the next session. `pikkumies update` never updates Hermes; it only builds Hermes when none is installed yet ("hermes: building …", log in `~/pikkumies/local/install.log`).
- **"already up to date"**: nothing to do.
- It also runs daily from the user's crontab (the line tagged `# pikkumies-update`); the last run's output is in `~/pikkumies/local/update.log`.
- **"tracked files were changed locally"**: the update stopped and changed nothing. It lists the edited files. Do not stash, reset or delete them: they are the user's work. Tell the user which files were changed and suggest moving the change to a local skill or `local/` config, then run update again.
- **"cannot fast-forward"**: the checkout's history differs from upstream. Report it to the user. Do not try to repair the git history yourself.
- **"fetch failed"**: usually no network or no access to the repository. Report the error text.

Never edit files under `~/pikkumies` that git tracks. To change how a shipped skill behaves, make a local copy under a new name (for example `seedbox-apps-local`) in `~/pikkumies/local/hermes/skills/`, set `name:` in the copy's SKILL.md to that new name, then turn the shipped one off by adding its name to `skills.disabled` in `~/pikkumies/local/config.php` (the Hermes config is regenerated from it). Do not reuse the shipped name: Hermes refuses to load a skill name that exists twice (ADR-0007).

## Updating Hermes

Hermes is a separate, unmodified checkout in `~/pikkumies/hermes` and updates on its own, only when the user asks (ADR-0033). Tell the user to type `/update` in a session, or to run `~/pikkumies/bin/pikkumies run update` from a shell when no other session is open. Never run `hermes update` or `pikkumies run update` from your own terminal tool: it rewrites the code this session is running. Never run the Hermes installer script: it edits the user's shell files and tries sudo.

- **"Not updating: other Hermes sessions or a scheduled job are running"**: the user closes the other sessions and runs it again.
- **"Hermes was updated, but Pikkumies will not start it"** or **"not starting: pikkumies/hermes changed and fails the Pikkumies checks"**: a setting Pikkumies relies on (for example the delete guard) no longer works with this Hermes. Tell the user the exact message and suggest `pikkumies doctor`; do not try to repair the Hermes checkout.
