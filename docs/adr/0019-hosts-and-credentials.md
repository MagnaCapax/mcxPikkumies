# ADR-0019: Find the user's apps on any host; never weaken their logins

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-14
- Status: Accepted

## Context
The request of 2026-10-14: the tools must be "provider agnostic, work with us in Pulsed Media (Albeit default and first check is that), Feral, What.CD etc." What.CD was a music tracker that closed in 2016; the seedbox host with the closest name is Whatbox, so this ADR reads it as Whatbox. That is an interpretation.

The tools talk to the user's own torrent clients, *arr apps and media servers, which listen on 127.0.0.1 ports or unix sockets and need a password, an API key or a token.

Facts this rests on, checked 2026-10-14:
- On PMSS every account can reach every 127.0.0.1 port. There is no loopback isolation per user (sysadmin lesson of 2026-10-14 on qBittorrent's localhost bypass), so "localhost" is not private.
- PMSS keeps `/etc/seedbox/config` traversable by all users (PMSS `scripts/lib/systemStatus.php`), so it is a reliable first check. Other hosts are told apart by hostname (feralhosting.com, whatbox.ca, usbx.me and others).
- `/proc/net/tcp` and `/proc/net/tcp6` list each listening socket with its owner uid.
- PMSS stores only a PBKDF2 hash of the qBittorrent WebUI password, and qBittorrent bans an address after repeated failed logins. The panel proxy also connects from 127.0.0.1, so a ban would lock the user out of their own WebUI.

## Options Considered
- **One table of documented paths per host.** Host documentation drifts, and undocumented hosts get nothing.
- **Ask the user for every endpoint.** Works everywhere, but makes the user do the agent's work.
- **Check PMSS first, then the hostname, then live probes (the user's own config files, sockets, ports), with documented paths only as a fallback, and `--url`/`--socket` overrides.**

## Decision
- `Detect` reports `host`: pmss when `/etc/seedbox/config` exists, otherwise the host family from the hostname, otherwise generic. Tools read the endpoint from the user's own app config first and use the host table only when that fails.
- `lib/Http.php`, the one HTTP client, connects to a port on this machine only when `/proc/net/tcp(6)` shows the listening socket belongs to the user's own uid; otherwise it refuses ("port belongs to another account"). "This machine" is any address the host resolves to in 127.0.0.0/8, 0.0.0.0/8, `::1`, `::`, their IPv4-mapped forms, or one of the machine's own interface addresses, whatever the spelling (`127.1`, `localhost.`, a decimal address, a name). curl is pinned to the address that was checked, only http and https are allowed, and a URL with no host, or a name that does not resolve, is refused. The LLM provider endpoint also accepts listeners owned by root or a service account (uid below 1000), since a system-wide local proxy is legitimate; another customer's listener is still refused. For unix sockets the tool checks the socket file's owner.
- Tools never weaken an app's login: no localhost bypass, no disabling of authentication, no new admin keys without the user's own setup step.
- Credentials are read from the user's own files and never printed. A record says where a credential lives, never its value. Passkeys in tracker URLs, tokens in URL parameters and `user:password@` in addresses are redacted from all output, and fields whose names look like secrets (password, passkey, token, api key, cookie, authorization) are dropped.
- A password that cannot be read from disk (qBittorrent on PMSS) is typed by the user at a terminal prompt, never in chat or on the command line. It is stored under `local/secrets/` (directory 0700, file 0600). Each call makes one login attempt; when it fails, the stored password is marked rejected and no tool tries it again until the user enters it anew, because the app bans an address after a few failures and the panel's own proxy shares that address.

## Consequences
- The same tool works on PMSS, the hosts we know, and plain servers, and it says why when it cannot.
- A co-tenant who grabs a port while an app is down gets no credential. A gap of milliseconds remains between the check and the connection.
- The user enters the qBittorrent password once per machine.

## References
- ADR-0004 (provider key and root detection), ADR-0015, ADR-0020
