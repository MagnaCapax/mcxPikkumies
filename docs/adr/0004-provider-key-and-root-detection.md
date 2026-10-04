# ADR-0004: Provider key file and root detection

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-04
- Status: Accepted

## Context
Pikkumies talks to any OpenAI-compatible endpoint with the user's own key. A hosting platform may drop a key file for its users. Pikkumies must also tell whether it can act as root, based on what the OS actually allows.

## Options Considered
- **Key in environment variables only.** Platforms would have to edit shell profiles.
- **A vendor-named key file.** That leaks one provider's naming into a generic tool.
- **A neutral key file** that the platform or user writes, with environment overrides.
- **Root from configuration.** Untrustworthy and meaningless; the OS decides.

## Decision
- **Provider resolution order:** the `PIKKUMIES_ENDPOINT` / `PIKKUMIES_KEY` environment variables, then `local/config.php`, then the key file `~/.pikkumiesKey` (INI with `endpoint=` and `key=`). Symlinked key files are ignored.
- **Platform-written key files** should be `root:<user>` mode 0640, written without following symlinks.
- **Key handling.** The key is never printed (it is masked). It reaches Hermes only through `$HERMES_HOME/.env` (mode 0600, umask 077).
- **Root is available** when either holds:
  - the effective uid is 0, with an identity uid map (not a user namespace) and `CAP_SYS_ADMIN`; or
  - the user is in `sudo`, `wheel` or `admin` and `sudo -n -k -l` succeeds (passwordless, with cached tickets ignored). Non-members are never probed, which avoids sudo's "not in sudoers" mail.
- **`root.disabled`** in `local/config.php` turns the root layer off for convenience. It is not a security control.
- **Running apps** are read from `/proc` for the user's own processes only.

## Consequences
- Works with any provider, and a platform can preconfigure users by dropping one file.
- Detection never escalates privileges or asks for a password.

## References
- ADR-0002 (root pack), ADR-0003 (key in .env)
