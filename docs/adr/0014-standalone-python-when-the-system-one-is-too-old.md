# ADR-0014: Standalone Python when the system one is too old

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-08
- Status: Accepted

## Context
Hermes needs Python 3.11 to 3.13 (ADR-0009). The first home of Pikkumies, the PMSS seedbox platform, runs mostly Debian 10 and 11, which ship Python 3.7 and 3.9; there `pikkumies install` set up Pikkumies but could not build the agent runtime. Pikkumies runs as the user, so it cannot install system packages.

## Options Considered
- **Require a newer OS.** Leaves most current PMSS accounts without the agent.
- **Build Python from source per account.** Needs compilers and headers, takes long, and fails in new ways per host.
- **A pinned prebuilt standalone CPython**, downloaded into the user's own `include/` and checked by sha256 exactly like the Hermes tarball.

## Decision
- `HermesInstall::python()` uses the system `python3` when it is 3.11 to 3.13; otherwise, on x86_64, the pinned standalone build `python-3.13.16` (python-build-standalone release 20261003, `install_only`, built for glibc 2.17 and later); otherwise Hermes is not installed and doctor says why.
- The background build downloads it once into `include/python-3.13.16`, verifies the sha256 before unpacking, unpacks into a temporary directory and moves it into place, then creates the Hermes venv with it. Later Hermes builds reuse it.
- The pin (URL and sha256) is a constant next to the other Hermes pins; a bump is a code change with a test, like the Hermes tag.

## Consequences
- Debian 10/11 accounts get the agent runtime. Checked: the build runs on Debian 10 (glibc 2.28) with its own OpenSSL and sqlite.
- About 75 MB download and 257 MB on disk per account that needs it, in the user's own quota.
- Other architectures still need a system Python 3.11 to 3.13.

## References
- ADR-0009 (Hermes from a pinned release tarball); python-build-standalone release 20261003 `SHA256SUMS`
