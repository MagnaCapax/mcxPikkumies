---
name: update
description: Update Pikkumies itself to the latest version without losing local changes, and explain what to do when the update refuses.
---

# Update Pikkumies

Run `~/pikkumies/bin/pikkumies update`.

- **"updated A -> B"**: done. The agent config was regenerated, so new skills and packs take effect in the next session.
- **"already up to date"**: nothing to do.
- **"tracked files were changed locally"**: the update stopped and changed nothing. It lists the edited files. Do not stash, reset or delete them: they are the user's work. Tell the user which files were changed and suggest moving the change to a local skill or `local/` config, then run update again.
- **"cannot fast-forward"**: the checkout's history differs from upstream. Report it to the user. Do not try to repair the git history yourself.
- **"fetch failed"**: usually no network or no access to the repository. Report the error text.

Never edit files under `~/pikkumies` that git tracks. To change how a shipped skill behaves, create a local skill with the same name instead; local skills take precedence over shipped ones.
