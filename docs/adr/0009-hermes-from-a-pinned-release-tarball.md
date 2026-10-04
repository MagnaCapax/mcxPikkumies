# ADR-0009: Install Hermes from a pinned, checksum-verified release tarball

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted. Supersedes the install method and the 0.19.0 pin of ADR-0008.

## Context
ADR-0008 pinned Hermes 0.19.0 from PyPI. That channel is a dead end:
- PyPI stopped at 0.19.0 (2026-07-20). Hermes's own code marks pip as an unsupported install method, and 14 releases have shipped since, some with security fixes (issue #1).
- From v2026.9.24 on, a normal `pip install` of the source fails on purpose: `setup.py` refuses to build wheels or sdists. Only editable installs (`pip install -e`, `uv sync`) work.
- The official `scripts/install.sh` puts launchers in `~/.local/bin` and appends PATH lines to `~/.bashrc`, `~/.zshrc` and `~/.bash_profile`, with no option to turn that off (`install.sh:2255-2278`), and it tries `sudo` for system packages. A helper that lives in a customer's account must not edit their shell files.
- A git checkout pinned to a tag does not stay pinned: `hermes update` (and the built-in `/update` command) fetches `main` and moves the checkout onto it.

## Options Considered
- **Stay on PyPI 0.19.0.** Frozen, unsupported, missing fixes.
- **Run the official installer.** Edits shell files, tries sudo.
- **Shallow git clone of the tag, remove `origin`.** About 290 MB per user, and relies on breaking Hermes's updater by removing a remote.
- **`uv sync --locked`.** Locks dependencies exactly, but needs the `uv` binary, a second download to trust and update.
- **Release tarball of the tag, checked by sha256, editable install into a venv.** Chosen.

## Decision
- `hermes.version` is a release tag and `hermes.sha256` the checksum of that tag's source tarball (`https://github.com/NousResearch/hermes-agent/archive/refs/tags/<tag>.tar.gz`). Default: `v2026.9.24` (Hermes 0.21.5).
- The build runs in the background under a lock (`include/.hermes-build.lock`) and logs to `local/install.log`. Into `include/hermes-<tag>/` it: downloads the tarball, verifies the checksum before unpacking, unpacks without the top-level `tests/`, `website/` and `apps/` directories (nested directories with those names are kept: the terminal UI has its own `apps/`), creates a venv, runs `pip install -e`, and marks the build complete. Only then does it switch `include/hermes` to the new venv with an atomic rename. A failed build never replaces a working one.
- With no `.git` in the source tree, `hermes update` refuses ("Not a git repository"), so the pin holds without tricks. `pikkumies run update` is refused with a pointer to `pikkumies update`, the generated config sets `updates.check: false`, and the update skill tells the agent never to run Hermes's updater or installer.
- `pikkumies update` builds a newly pinned release and removes old builds that are neither current nor pinned. An install from before this ADR (a plain venv in `include/hermes`) is moved aside to `include/hermes-legacy` at the switch and removed by the next update.
- Install and doctor check Python first: Hermes needs 3.11 to 3.13, so an older system (Debian 11 has 3.9) gets a clear message instead of a failing build.
- The core skill `update` is renamed `pikkumies-update`: Hermes has a built-in `/update` command. The contract test now checks skill names against every name Hermes v0.21.5 uses: registered tools, toolsets and built-in commands.

## Consequences
- About 83 MB of source and 190 MB of venv per user, against about 290 MB for a clone and 430 MB for the official installer.
- Dependencies are resolved by pip within Hermes's declared versions, not from its lock file. Most direct dependencies are pinned exactly in `pyproject.toml`; transitive ones are not.
- GitHub generates tag tarballs on the fly. If one ever changes, the checksum fails and the build stops with nothing replaced; the fix is a new checksum in a reviewed commit.
- A pin bump is one commit that changes tag and checksum, re-runs the live check below, and regenerates the reserved-name list in `tests/ContractTest.php`.

## Live check for a pin bump
In a throwaway HOME with `HERMES_HOME` pointing into it (never the real one): `pikkumies install` or `update` until `local/install.log` says ready; `pikkumies doctor`; `pikkumies run skills list` (all skills, disabled ones marked); `pikkumies run hooks test pre_tool_call --for-tool terminal` with a destructive command (blocked); one request against a local OpenAI-compatible stub that checks the key arrives.

## References
- ADR-0005, ADR-0007, ADR-0008; issue #1
- Hermes v2026.9.24 source: `pyproject.toml`, `setup.py`, `hermes_cli/update_cmd.py`, `scripts/install.sh`, `hermes_cli/config_defaults.py`
