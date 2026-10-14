---
name: pikkumies-update
description: Update Pikkumies itself to the latest version without losing local changes, and explain what to do when the update refuses.
---

# Update Pikkumies

Run `~/pikkumies/bin/pikkumies update`.

- **"updated A -> B"**: done. The agent config was regenerated, so new tools and skills take effect in the next session. If the new version pins a different Hermes release, update also prints "hermes: building …" and builds it in the background; the switch happens when the build finishes (see `~/pikkumies/local/install.log`).
- **"already up to date"**: nothing to do.
- It also runs daily from the user's crontab (the line tagged `# pikkumies-update`); the last run's output is in `~/pikkumies/local/update.log`.
- **"tracked files were changed locally"**: the update stopped and changed nothing. It lists the edited files. Do not stash, reset or delete them: they are the user's work. Tell the user which files were changed and suggest moving the change to a local skill or `local/` config, then run update again.
- **"cannot fast-forward"**: the checkout's history differs from upstream. Report it to the user. Do not try to repair the git history yourself.
- **"fetch failed"**: usually no network or no access to the repository. Report the error text.

Never edit files under `~/pikkumies` that git tracks. To change how a shipped skill behaves, make a local copy under a new name (for example `seedbox-apps-local`) in `~/pikkumies/local/hermes/skills/`, set `name:` in the copy's SKILL.md to that new name, then turn the shipped one off by adding its name to `skills.disabled` in `~/pikkumies/local/config.php` (the Hermes config is regenerated from it). Do not reuse the shipped name: Hermes refuses to load a skill name that exists twice (ADR-0007).

Never run `hermes update`, the `/update` command or the Hermes installer script. Pikkumies pins the Hermes release it was tested with and updates it itself; `hermes update` refuses anyway, and the installer would edit the user's shell files.
