# ADR-0120: A machine proven single-tenant needs no per-account fence in the browser guard

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-11-23
- Status: Accepted. Amends [ADR-0077](0077-isolated-browser-in-a-private-network-namespace.md): the guard's `--host` fence stays on every PMSS server and on every machine that is not proven single-tenant; on a proven single-tenant machine it is replaced by a guard started with `--single-tenant`.

## Context
[ADR-0077](0077-isolated-browser-in-a-private-network-namespace.md) fences the browser to the account's own paths on the server's own name, because on a PMSS server every account's panel shares that origin. [ADR-0004](0004-provider-key-and-root-detection.md) lets Pikkumies run with root on any Linux server. On an ordinary one-account server that has just had a web app installed at the server's own `/`, the fence blocks the page the person actually types, and a host that reports an unqualified name (`hostname -f` without a dot) never starts the browser at all, because the fence is keyed on that name.

What the fence protects there, read from `WebGuardDecision.php`: the panel login is added only on `/user-<self>/`, a PMSS route, and `PanelAccess` and the stored header exist only for a PMSS panel. Without co-tenants and without a login there is nothing for the fence to keep apart. The host's public address was already outside it (ADR-0077, known gaps).

## Options Considered
- **Leave it and document "browse the server by its address".** An address reaches the default virtual host and fails a TLS name, so it does not reach an app that is served by name.
- **A setting in `local/config.php` that turns the fence off.** The account can edit it, and the fence's job on a shared server is to hold when the account's own files say otherwise ([AGENTS.md](../../AGENTS.md): what must be enforced is never a setting the user can edit). Rejected.
- **Fence only when `/etc/seedbox` exists.** The simplest rule, but a generic shared server with several accounts (user directories on one name) would lose the fence ADR-0077 gave it. Rejected for that reason.
- **Prove the machine single-tenant from the OS each time, and keep the fence on every doubt.** Chosen.

## Decision
- **`BrowseCheck::fenceReason()`** answers `''` (no fence needed) only when all of these hold, read live from the OS and never from the cached host profile, which the account can edit ([ADR-0004](0004-provider-key-and-root-detection.md) addendum):
  - there is no `/etc/seedbox` directory;
  - `/etc/passwd` and `/etc/nsswitch.conf` can be read, and the `passwd:` line of the latter names only `files` and `systemd` (accounts that a directory service supplies cannot be counted from the file);
  - `Detect::sharedTenancy` finds at most one account with a home (uid 1000 or more).
  Any other answer is the plain reason the fence stays, and the fence is exactly what [ADR-0077](0077-isolated-browser-in-a-private-network-namespace.md) built.
- **The supervisor**, when the answer is `''`: starts the guard with `--single-tenant` instead of `--host`, passes no login (neither the agent's own, [ADR-0079](0079-the-agents-own-panel-login.md), nor a stored header), and does not need a qualified host name. It records the mode in `browse.log`.
- **The guard** takes `--single-tenant` as an explicit alternative to `--host`, and refuses to start when it is given together with `--host` or `--auth-file`. So the guard itself never sends a login without a fence, whatever the supervisor decided. Everything else it does is unchanged: every request goes out with any `Authorization` header removed and every 401 challenge is cancelled; page-script rules, the isolated network namespace and the loopback self-check are not touched.
- **Doctor and `browse status`** say the mode: the ready line carries `single-tenant host: no per-account fence` only in that mode (the shared-server line is unchanged), and `status` has a `fence` field.

## Consequences
- The browser can open an app at the server's own name on a one-account server, and starts on a host with an unqualified name.
- A multi-tenant layout the OS cannot show (accounts only in a container's own list, a custom name service not named in `nsswitch.conf`) is read as single-tenant. What is lost there is only the block on other paths of the server's own name; no panel login exists in that mode, and the server's address was never fenced.
- Not decided here: installing Chromium and slirp4netns by the agent itself. That is a separate change to what the agent may do unasked ([ADR-0021](0021-web-access-off-unless-configured.md), [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md)), and the browser stack is not verified on a single-tenant host end to end (Chromium normally refuses to run as uid 0 without its sandbox switched off, which the skill forbids; whether the namespace's uid map avoids that when Pikkumies itself runs as uid 0 has not been tried).
- Not verified: Hermes' browser tools through the wrapper on a single-tenant machine, and a start as uid 0 on a real machine. The unit tests cover the decision, the supervisor wiring, the guard's refusals and the decision table; no browser ran.

## References
- ADR-0004, ADR-0021, ADR-0073, ADR-0077, ADR-0079
- `pikkumies/tools/system/browse/BrowseCheck.php`, `BrowseSupervisor.php`, `guard.php`; tests `tests/BrowseCheckTest.php`, `tests/BrowseSupervisorTest.php`, `tests/WebGuardDecisionTest.php`, `tests/WebGuardProcessTest.php`
