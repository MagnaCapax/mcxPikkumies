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

## Addendum 2026-10-15: a cached root value is a hint, never an authority (ADR-0023)
`Detect::cached()` (ADR-0023) may answer from `local/host.json` instead of probing again. That file is user-writable (it lives under the account's own `local/`, same as `local/config.php`), so a user — or anything with the account's own access — could in principle edit it to claim `root.available: true`. That edit changes nothing real: the cached value is read only to decide which skills to *offer* (the root pack, ADR-0002); every actual privileged action still asks the OS at the time it runs (`sudo -n -k -l`, the real uid, `CAP_SYS_ADMIN`), exactly as this ADR already specified. A false cached "yes" buys no privilege, only a skill listing that then fails its own OS-level check; a false cached "no" only hides a skill that would have worked. This is the general rule behind catalog #726 (`AGENT_WRITABLE_STATE_AS_AUTHORIZATION_INPUT`): state the agent's own account can write is never, by itself, a source of authorization — the OS decides, every time, not the cache.

## Amendment 2026-10-17: the platform key file ~/.virsilipasKey
The platform's own LLM proxy (Virsilipas) delivers each account's key as `~/.virsilipasKey`: the same INI format (`endpoint=`, `key=`), mode 0600, owned by the account and written as the account, never over a symlink. The operator named the file (2026-10-17): "virsilipas key should be naturally named as virsilipas or virsilipasKEy not pikkumiesKey". It is the platform proxy's file, not one vendor's naming leaking into the tool, so the "vendor-named key file" objection above does not apply.
- **Resolution order** becomes: the environment variables, then `local/config.php`, then `~/.pikkumiesKey`, then `~/.virsilipasKey`. The first key file that holds a key is used, for its endpoint too, so a user's own provider in `~/.pikkumiesKey` always wins and an endpoint from one file is never paired with a key from the other. Both paths are settings (`provider.keyFile`, `provider.virsilipasKeyFile`); symlinks are ignored for both.
- **`pikkumies doctor`** names the file the key came from and, when the endpoint answers `GET /balance` with an integer `balance` (the proxy's route), shows the credits left. Other providers answer 404 and show no line.
