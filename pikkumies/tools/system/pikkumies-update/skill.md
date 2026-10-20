---
name: pikkumies-update
description: Update Pikkumies itself to the latest version without losing local changes, explain what to do when the update refuses, and how Hermes is updated separately.
---

# Update Pikkumies

Run `~/pikkumies/bin/pikkumies update`.

The first line, `channel: …`, says what the update followed: `release <tag>` (the default), `release (none published yet: following main)`, or `git/<branch>` ([ADR-0057](../../../../docs/adr/0057-release-channel.md)).

- **"updated A -> B"** (with `(release <tag>)` on the release channel): done. The agent config was regenerated, so new tools and skills take effect in the next session. `pikkumies update` never updates Hermes; it only builds Hermes when none is installed yet ("hermes: building …", log in `~/pikkumies/local/install.log`).
- **"already up to date"** or **"already at the latest release T"**: nothing to do.
- **"ahead of the latest release T; staying here until a newer release"**: normal, not a fault. This checkout already has everything in release T (it followed `main` before releases existed, or was installed from `main`); it moves again when a newer release comes out. Do not "fix" it by switching to `git/main` or by resetting: only change `update.channel` when the user asks for the latest code.
- **"fetch of release T failed"**: usually no network; if git's text says it "would clobber existing tag", the release tag was moved on the repository after this install fetched it, and the update refuses it on purpose. Report the text to the user; never delete or force the local tag.
- **"could not compare with release T (fetching the full history failed)"**: this install was cloned with only its newest commit, and fetching the rest failed (network). Nothing changed; the next daily update tries again. Report it if it keeps happening.
- **"update.channel is not valid"**: `update.channel` in `~/pikkumies/local/config.php` was edited by hand to something other than `release` or `git/<branch>`. Nothing changed. Set it back with the `pikkumies-configure` skill (`set update.channel release`) and tell the user.
- It also runs daily from the user's crontab (the line tagged `# pikkumies-update`); the last run's output is in `~/pikkumies/local/update.log`.
- **"tracked files were changed locally"**: the update stopped and changed nothing. It lists the edited files. They are the user's work: never stash, reset or delete them. Tell the user which files were changed. A changed shipped skill or setting has a place updates leave alone: copy the file to `~/pikkumies/local/backups/` first, carry the change into a local skill (below) or `local/config.php`, check it, then put the tracked file back (`git -C ~/pikkumies checkout -- <file>`) and run update again. Other files: report them.
- **"cannot fast-forward"**: the checkout's history differs from upstream. Report it to the user. Do not try to repair the git history yourself.
- **"fetch failed"**: usually no network or no access to the repository; on a `git/<branch>` channel also a branch that does not exist ("couldn't find remote ref"). Report the error text.

Never edit files under `~/pikkumies` that git tracks. To change how a shipped skill behaves, make a local copy under a new name (for example `seedbox-apps-local`) in `~/pikkumies/local/hermes/skills/`, set `name:` in the copy's SKILL.md to that new name, then turn the shipped one off with the `pikkumies-configure` skill (`skills disable <shipped-name>`; the Hermes config is regenerated from it). Do not reuse the shipped name: Hermes refuses to load a skill name that exists twice ([ADR-0007](../../../../docs/adr/0007-customising-shipped-skills.md)).

## Updating Hermes

Hermes is a separate, unmodified checkout in `~/pikkumies/hermes` and updates on its own, only when the user asks ([ADR-0033](../../../../docs/adr/0033-vanilla-hermes-git-checkout-updated-separately.md)). It cannot be done from inside a session: say in your answer that Hermes updates with `/update` typed in a session, or with `~/pikkumies/bin/pikkumies run update` from a shell when no other session is open. Never run `hermes update` or `pikkumies run update` from your own terminal tool: it rewrites the code this session is running. Never run the Hermes installer script: it edits the user's shell files and tries sudo.

- **"Not updating: other Hermes sessions, a dashboard or a scheduled job are running"**: stop a running dashboard yourself with `pikkumies run dashboard --stop`, and say in your answer which other sessions or jobs are still running, since the update runs only when they are closed.
- **"Hermes was updated, but Pikkumies will not start it"** or **"not starting: pikkumies/hermes changed and fails the Pikkumies checks"**: a setting Pikkumies relies on (for example the delete guard) no longer works with this Hermes. Run `pikkumies doctor` and give the user the exact message and what it shows; do not try to repair the Hermes checkout.
