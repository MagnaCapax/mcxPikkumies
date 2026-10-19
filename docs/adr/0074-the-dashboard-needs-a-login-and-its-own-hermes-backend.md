# ADR-0074: The dashboard starts only with a login, and Pikkumies' Hermes keeps its own backend record

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Adds to [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md) (one more variable in the Hermes environment) and to the [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) addendum of 2026-10-19 (the dashboard start).

## Context
The operator asked on 2026-10-19 for the Hermes web dashboard on PMSS, run as Pikkumies. PMSS is a shared server: many accounts on one host. Linux does not separate loopback by user, so unless a host firewall does, each of them can open a connection to 127.0.0.1 on any port.

Hermes v0.22.2, read in source:
- On a loopback bind with no gate, the dashboard puts its session key into the page itself (`hermes_cli/web_server_dashboard.py:164-165`). That key opens the sensitive APIs, the chat terminal included (`hermes_cli/web_server.py:510-516`).
- The login gate engages only for a bind host outside `localhost`, `127.0.0.1` and `::1` (`web_server.py:522`, `541-548`), or when `dashboard.public_url` names a host outside them (`web_server.py:550-562`; the URL comes from `HERMES_DASHBOARD_PUBLIC_URL` when that is well formed, else from the config, `hermes_cli/dashboard_auth/prefix.py:117-130`). A Desktop-owned loopback backend (`HERMES_DESKTOP=1` and a session token) ignores the public URL (`web_server.py:565-583`).
- So `pikkumies run dashboard` with Hermes' defaults lets any other account on the server fetch the page with one request, read the key and run commands as the owner.
- Hermes keeps one backend record per OS user, in `~/.local/state/hermes/gateway-locks` unless `HERMES_GATEWAY_LOCK_DIR` says otherwise (`gateway/status.py:319-335`). A second `hermes dashboard` attaches to the live backend that record names, prints its address and exits (`hermes_cli/main_dashboard.py:873-941`). If a plain Hermes ran on the same account, `pikkumies run dashboard` would hand the user that one, which has no Pikkumies settings or hooks.

## Options Considered
- **Leave it to the user's setup.** The unsafe setup is also the default, so the first try is the dangerous one (pit of success).
- **Always bind a non-loopback host.** Hermes then gates the dashboard, but it opens a port on every interface, which a PMSS account should not need.
- **Refuse the start wherever Hermes itself would leave the dashboard open.** This uses Hermes' own rule and changes nothing in Hermes. Chosen.

## Decision
- `pikkumies run dashboard` (any start of a server: not `--stop`, `--status`, `register` or help) refuses with one line when the bind host is one of Hermes' three loopback names and the public URL does not name another host. The line names the risk and the fix: set `HERMES_DASHBOARD_PUBLIC_URL` (or `dashboard.public_url`) to the address it is opened at, with Hermes' basic-auth login (`HERMES_DASHBOARD_BASIC_AUTH_USERNAME`, `_PASSWORD`, `_SECRET`), as the release QA stage does, or bind a non-loopback `--host`.
- The bind host is the one argparse ends up with: the last `--host`, `--hos` or `--ho`, with or without `=`. An empty value counts as the default. The public URL is read through Hermes (`hermes config get HERMES_DASHBOARD_PUBLIC_URL`, then `dashboard.public_url`, with Pikkumies' Hermes environment), so Hermes' `.env` files and the managed scope count. A well-formed environment value wins, as in Hermes. When this check cannot read a URL that Hermes would use (a user part in it, an unusual form), it refuses. With `HERMES_DESKTOP=1` it refuses too. The check runs before the web page build (ADR-0033 addendum) and before the Hermes lock (`lib/HermesDashboardGate.php`).
- `HermesConfig::env()` adds `HERMES_GATEWAY_LOCK_DIR=local/hermes/gateway-locks`, so every Hermes that Pikkumies starts keeps its backend record apart from a plain Hermes of the same account. The directory name is Hermes' own (`gateway/status.py` `_LOCKS_DIRNAME`). It is not the shared `locks/` directory, where Hermes keeps its desktop build locks (`hermes_cli/desktop_build_lock.py`). Hermes creates it, owner-only (`gateway/host_rendezvous.py` `ensure_host_state_dir`).

## Consequences
- A dashboard with Hermes' defaults no longer starts. The user gets the reason and the fix instead of an open terminal.
- Starting a dashboard bound to loopback costs up to two short Hermes processes for the public URL reads.
- A dashboard started before this change, or one some other way, is not checked: `hermes dashboard` started by hand is outside Pikkumies.
- A running Pikkumies backend from before this change still sits in the per-user record until it stops, so for that time a new `pikkumies run dashboard` does not see it and binds its own.
- Not covered: `pikkumies run serve` (the headless backend; no page carries a key there, and it was not examined further), and the agent changing `HERMES_GATEWAY_LOCK_DIR` in a nested command (it is not among the variables [ADR-0020](0020-deletes-need-the-users-approval.md) protects).
- Not verified on a live host: the refusal and a gated start through `pikkumies run dashboard`, and the separate record beside a plain Hermes.

## References
- Hermes v0.22.2: the files and lines above.
- `pikkumies/lib/HermesDashboardGate.php`, `pikkumies/lib/HermesConfig.php` (`env()`), `pikkumies/bin/pikkumies`; tests `tests/HermesDashboardGateTest.php`.
- Release QA dashboard stage (Pulsed Media's own test suite): public URL and Hermes basic auth in the environment, bind `127.0.0.1`.
