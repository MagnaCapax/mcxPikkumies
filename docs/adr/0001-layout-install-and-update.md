# ADR-0001: Repository layout, install location and update model

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted. The "local skill with the same name" override is superseded by ADR-0007; where Hermes is installed (`pikkumies/hermes`, not `include/hermes/`) by ADR-0033.

## Context
Pikkumies runs on the machine it manages, as the user. It must be quick to install and easy to update from git. Updates must never overwrite what the user or the agent changed locally. The repository also holds developer material (docs, tests, CI) that the runtime does not need.

## Options Considered
- **Repository root is the runtime.** Simplest paths, but developer files land in the runtime tree.
- **Split layout.** Runtime in `pikkumies/`, bundles in `packs/`, developer material at the root. The clone lives at `~/.mcxPikkumies` and `~/pikkumies` links to `pikkumies/`.
- **Sparse checkout of `pikkumies/` only.** Smaller, but sparse checkouts are fragile to update.
- **Update with stash or rebase.** A conflict would leave a half-merged tree for the agent to repair.

## Decision
- **Layout.**
  - `pikkumies/` is the runtime: `bin/ lib/ tools/<target>/ skills/ hooks/<event>/ doctrine/ doctrine/SOP/ memory/ include/ local/`.
  - `packs/` holds the bundles, plus a generated `INDEX.md` and a `README.md`.
  - `docs/adr/` and `tests/` sit at the root.
- **Install.** `git clone --depth 1 <repo> ~/.mcxPikkumies && ~/.mcxPikkumies/pikkumies/bin/pikkumies install`. The installer links `~/pikkumies` to the runtime directory. It needs no root and returns quickly, while the Hermes virtualenv builds in the background.
- **State.** All user and agent state is gitignored: `pikkumies/local/` (config, profile, Hermes home, logs), the user lesson files in `pikkumies/memory/`, and `pikkumies/include/hermes/`. Tracked files are never written at runtime.
- **Update.** `pikkumies update` stops and lists the files if any tracked file was edited. Otherwise it fetches `origin/main` (a plain fetch, never `--depth 1`: on a shallow clone that grafts the new tip as a parentless root and the fast-forward fails) and fast-forwards only, never stashing, merging, rebasing or resetting. Release tags and channels come later, when there is a release to pin.
- **Code.** PHP compatible with 7.3 (hosts may run older distributions), no Composer dependencies, camelCase.

## Consequences
- An update cannot destroy local work: it either fast-forwards cleanly or changes nothing and says why.
- Users override a shipped skill by creating a local skill with the same name (Hermes precedence, ADR-0003), not by editing tracked files.
- `~/pikkumies` is a symlink. To remove Pikkumies, run `rm -rf ~/.mcxPikkumies ~/pikkumies`.

## References
- ADR-0002 (packs), ADR-0003 (Hermes integration), ADR-0004 (provider key and detection)
