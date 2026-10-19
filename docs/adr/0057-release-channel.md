# ADR-0057: Updates and new installs follow the newest release by default

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Supersedes [ADR-0001](0001-layout-install-and-update.md)'s "Release tags and channels come later"; amends [ADR-0013](0013-daily-auto-update-from-the-user-crontab.md) (what the daily update moves to) and [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) (one more settable key, `update.channel`, as [ADR-0052](0052-launcher-model-flag-and-configure.md) requires an ADR for).

## Context
The request of 2026-10-18: "we should do the release // git version branching on to pikkumies too, people defaulting to latest release ... and the pikkuimies update and installer defaulting to the release, unelss you specify git version".

Until now every install followed `main`: the daily `pikkumies update` fast-forwarded to `origin/main` (ADR-0001, ADR-0013), and `main` took 10 to 31 commits a day from 2026-10-14 to 2026-10-18 (`git log`). A change that broke something reached every install by the next morning.

Facts this rests on, checked with git 2.47 in throwaway repositories (tests/UpdateChannelTest.php):
- `git merge --ff-only <commit>` with a commit already in HEAD's history succeeds and changes nothing, so "ahead of the release" has to be detected with `git merge-base --is-ancestor`, not inferred from the merge.
- In a `--depth 1` clone, a tag older than the clone's commit is past the shallow boundary: after fetching it, `merge-base --is-ancestor` answers "no" in both directions, so it looks diverged. `git fetch --unshallow origin` makes the answer correct; the whole history of this repository is about 2 MB.
- A fetch of `refs/tags/<T>:refs/tags/<T>` without `+` refuses to replace a local tag of that name that points elsewhere ("would clobber existing tag").
- `git fetch origin <name>` resolves the name against every ref on origin, so `pull/1/head` reaches `refs/pull/1/head`, which on a public host anyone who opens a pull request can create; `git fetch origin refs/heads/<name>` takes only a branch.
- `git reflog -n 2 --format=%gs HEAD` in a fresh clone prints one line, `clone: from <url>`; every later update or checkout adds a line.
- The installers (`install.sh`, and the platform's skeleton installer) clone `main` with `--depth 1` and run `pikkumies install`; on an existing checkout they run `pikkumies update` first and `install` after it.

## Options Considered
- **A `stable` branch the release process fast-forwards.** Two moving pointers (the branch and the release tag) and a second write surface for the release process; the backwards safety is the same as with tags. Rejected.
- **Install and update pinned to a tag (detached HEAD).** A later `update` on `git/main` would have to leave the detached state; a checkout on branch `main` keeps both channels plain fast-forwards. Rejected.
- **Change the installers to clone the release tag.** Touches the platform installer and still leaves update to decide; the PHP install verb can do it in one place. Rejected.
- **Date tags as releases, chosen at update time from `git ls-remote --tags`, fast-forward only; a git branch only when asked.** Chosen.

## Decision
- **Setting `update.channel`:** `release` (default) or `git/<branch>`. A branch starts with a letter or digit and has only letters, digits and `. _ / -`, never `..`, `//`, `/.`, a trailing `/`, `.` or `.lock` (`Config::validChannel`), so it can never be read as a git option or a revision expression. It is set with `pikkumies install --channel=...` or the `pikkumies-configure` tool (`set update.channel git/main`), which the agent runs only when the user asks; it is not a safety setting. A hand-edited invalid value makes `update` refuse and `doctor` say so.
- **A release** is a tag named `YYYY-MM-DD` (a real date) on origin, or `YYYY-MM-DD.N` (N 1 to 99, no leading zero) for a further release the same day. The newest is the latest date, then the highest N (none counts as 0), in `git ls-remote --tags origin`. Peeled `^{}` lines, every other tag (`.0`, `.100`, `.x`, `.1.2`, …) and any tag dated after tomorrow (UTC; one day of clock skew allowed) are ignored, so a mistyped future date cannot hold every install back (`UpdateChannel::latestRelease`). The GitHub release page and its "latest" mark are not read.
- **Release tags are immutable:** once published, a release tag is never moved or deleted; a repository tag ruleset blocks updating or deleting any tag matching `20*`, which covers both `YYYY-MM-DD` and `YYYY-MM-DD.N`, and an install refuses a tag that moved anyway (below).
- **`pikkumies update` on `release`:** after the same check for edited tracked files, it lists the tags. With no release published it does exactly what it did before (fetch `main`, fast-forward only), so installs are unchanged until the first release. Otherwise it fetches the release tag (a plain fetch, never `--depth`, as ADR-0001 says) and compares HEAD with it: behind, `merge --ff-only` to it; equal, nothing to do; ahead, it stays where it is and says "ahead of the latest release T; staying here until a newer release" (success); diverged, it refuses and changes nothing. A shallow clone that cannot tell is unshallowed once and asked again. A release tag that moved on origin is not adopted over the local one; the update fails and says so.
- **`pikkumies update` on `git/<branch>`:** what it did before, with that branch: fetch `refs/heads/<branch>`, fast-forward only. Only a real branch is followed; a tag, a pull-request head or any other ref of that name fails the fetch.
- **First install only:** when `pikkumies install` runs with no `pikkumies/local/` yet, the channel `release`, no edited tracked file, HEAD's reflog holding exactly one entry and that entry the clone (`clone:`), and a release exists, it fetches that tag and moves the checkout with `checkout -B main <tag>`, then runs the install again from the release's code. This is the one move that is not a fast-forward; on a fresh clone nothing of the user's exists yet. An existing install is never moved: with local/ the installers' `update`-then-`install` changes nothing here, and a checkout that was ever updated or moved keeps its place even when local/ was deleted and the installer runs again, so the move can never go backwards.
- **Output:** `pikkumies update` prints `channel: <what it followed>` before its result; `pikkumies doctor` and `pikkumies install` print the channel and the release or commit the checkout is at, read from the checkout without fetching (`UpdateChannel::describe`).

## Consequences
- Installs move once per release instead of once per day of commits; a broken commit on `main` reaches only `git/main` installs.
- Installs that followed `main` before this are ahead of the first release. They stay where they are, with the message above, until a release newer than their commit exists, then follow releases. The first release must contain this change: an install that moved to an older release would run the old update code, which follows `main` again.
- A bad release reaches every `release` install at its next daily update. Installs read the tags, not the release page's "latest" mark, so marking an older release as latest does not stop them, and the bad tag cannot be removed (immutable). The remedy is a newer release with the fix: the next date's tag, or `YYYY-MM-DD.N` the same day. Installs that already moved stay where they are (never backwards) until it is published.
- Unshallowing downloads the whole history once (about 2.2 MiB today) on a clone whose commit is newer than the release. The first daily update after the first release does this once on each install that was cloned with `--depth 1` before it, spread over the day by each account's update time.
- `install --channel=git/<branch>` on a fresh clone does not move the checkout; the next update fetches that branch and fast-forwards, which fails (and says so) when the branch is not ahead of the cloned `main`.
- Tests: `tests/UpdateChannelTest.php` (fast-forward to a newer release, annotated and lightweight tags, `.N` ordering and junk suffixes, future tags ignored, hold when ahead past a shallow boundary, unshallow failure, diverged refused, moved tag refused, no release follows main, `git/<branch>` and refused non-branch refs, configure and install-flag round trip, channel validation) and `tests/UpdateChannelInstallTest.php` (first-install move and when it must not happen, including an updated checkout without local/, and `bin/pikkumies install` moving and running again with the same flags).

## References
- ADR-0001 (layout, install and update), ADR-0013 (daily auto-update), ADR-0032 (settings and the configure tool), ADR-0052 (`pikkumies configure`; more settable keys need an ADR)
