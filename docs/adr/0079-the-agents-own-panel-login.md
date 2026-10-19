# ADR-0079: The agent's own panel login: a second line in the account's password file

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0077](0077-isolated-browser-in-a-private-network-namespace.md): the guard's `--auth-file` now comes first from the agent's own login, written for each start to `local/browse/panel-auth` and deleted once the guard has read it; the header the user stored in `local/secrets/browse-panel-auth` is the fallback.

## Context
Pikkumies has to open the account's panel to check what only the page shows: whether an app loads through its proxy path, whether the panel shows it protected, a login loop. The panel's lighttpd checks Basic logins against `~/.lighttpd/.htpasswd`, where the account's password is stored hashed, so the agent cannot read it, and it must not ask for it or reset it: the account's primary password is never a service credential, and a reset locks the user out.

Facts this rests on, checked 2026-10-19:
- PMSS renders each account's lighttpd config to `~/.lighttpd.conf` (root's file, mode 0644, readable by the account). Its `auth.require` entry for `/user-<account>/` says `"require" => "user=<account>"`, so a second user in the password file is refused today.
- lighttpd's `require` accepts `user=a|user=b`. Its htpasswd backend hands every hash that is not `{SHA}` or `$apr1$` to the C library's `crypt()`; PMSS already writes `$2y$` bcrypt there and measured that lighttpd accepts it. A PHP `password_hash` bcrypt value checked out through the C library's `crypt()` on a Debian 13 host.
- PMSS's writers of that file replace only the account's own line and keep every other line. The file is the account's, mode 0640.
- A PMSS account name matches `^[a-z][a-z0-9]{0,7}$`, so a name with a dot can never be an account.

## Options Considered
- **Reset the user's password, or ask for it.** Locks the user out, or puts the password through the agent; rejected. Asking stays the last step for services that offer nothing else.
- **A session cookie of the panel's opt-in cookie login.** Off by default and an internal format; rejected as the default.
- **A root helper on the server that issues short-lived tokens.** A new root surface for something the account can already do; rejected.
- **A second line in the account's own password file, under a reserved name the panel config lists.** Chosen. No new principal: the same account owns both lines and could write either today.

## Decision
- **The name** is `pikkumies.agent`. The PMSS side (a separate change) adds it to the `/user-<account>/` require rule only; WebDAV keeps `user=<account>`, because the agent has the files already.
- **`lib/PanelAccess.php`**:
  - `available()` reads `~/.lighttpd.conf` (never writes it) and is true only when the `/user-<account>/` rule lists `user=pikkumies.agent`. Until the PMSS change lands, nothing is written and every answer is one plain line saying why.
  - `ensure()` keeps one line `pikkumies.agent:<bcrypt>` in the password file for a secret of 32 random bytes (64 hex digits), bcrypt cost 10: the secret is 256 random bits, so a higher cost buys nothing against guessing, and lighttpd checks it on every request. The secret is stored with `SecretFile` in `local/secrets/panel-agent` (0600, [ADR-0019](0019-hosts-and-credentials.md)). A missing secret, more than one line, a line that does not match the secret, or `pikkumies access rotate` (a 401 with the stored login) makes a new one.
  - `remove()` takes the line and the secret out, whether or not the panel still lists the agent.
- **The password file** (`lib/PanelHtpasswd.php`):
  - Never created: PMSS owns it. A symlink, a hard link, a file that is not regular, another owner's file or one over 1 MiB is refused, never repaired.
  - Every change holds `flock` on `~/.lighttpd/.htpasswd.lock`, the lock the PMSS change adds to its writers (taken in the account's context), waiting at most 10 s and never breaking it; the checks run again once it is held.
  - A new line is appended (the file keeps its inode, owner and mode; a missing final newline is added first). A rotate or a remove rewrites the file through a new file next to it, read back, with the old mode and group, then renamed.
  - After every write the file is read back. When it is exactly what was written but a line that is not the agent's differs from before, that came from this code, and the old bytes are put back. When it is not what was written, another program changed it, and it is left alone: putting the old bytes back there could undo the user's own password change.
- **The browser.** Before the guard starts, the supervisor runs `ensure()` and writes `Basic <base64>` to a new file `local/browse/panel-auth` (O_EXCL, 0600, in the 0700 directory), passes it as `--auth-file`, and deletes it once the guard is ready (the guard reads it once at its start), at the next start and at every stop. When the panel does not accept the agent, or `ensure()` fails, the stored header of ADR-0077 is used if there is one, else none; the failure is one line in `browse.log` and the browser still starts.
- **`pikkumies access status|rotate|remove`**, and the same status line in `pikkumies doctor`: off and why, available with no login yet, ready with the last change, or needs renewing. Never the secret or the hash.
- **The record.** Each create, rotate, restore, remove and failure is one JSON line in `local/state/panel-access.jsonl`: time, action, reason. The secret and the hash never leave PHP: no argv, no log, no output.

## Consequences
- Once PMSS lists the name, the agent logs in to its own account's panel with no question and no user password. Until then everything stays as it is and the status line says why.
- The agent's login reaches the account's panel, its console and file manager included, as the account's own does. The agent already has a shell as the account, so this adds no capability; a leaked secret is revoked by `pikkumies access rotate` or `remove`.
- ruTorrent keys its settings on the login name, so through the panel the agent sees its own ruTorrent profile; facts come from the torrent tools.
- There is no `pikkumies uninstall` ([ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)): removing Pikkumies by hand starts with `pikkumies access remove`. A line left behind authenticates only with a secret that was deleted with `local/`.
- A PMSS writer that takes no lock (any PMSS before the change) can still race. An append loses at worst the agent's own line, which the next start adds again. A rotate or remove that read the file just before such a writer replaced it can put the user's previous password line back, and the read-back cannot see that; rewrites happen only on a mismatch, on request or on removal, and the window closes once the server runs the locking writers.
- Not verified against a running lighttpd: the PMSS side is not released yet. Unit tests cover the file handling, the lock (also held by `flock(1)`), the refusals and the browser wiring.

## References
- ADR-0019, ADR-0022, ADR-0077; PMSS `etc/seedbox/config/template.lighttpd`, `scripts/lib/lighttpd/htpasswd.php`, `scripts/lib/lighttpd/userConfigApply.php`, `scripts/lib/user/identity.php`
- lighttpd 1.4 `src/mod_auth.c` (the `require` list), `src/mod_authn_file.c` (`mod_authn_file_htpasswd_basic`, `mod_authn_file_crypt_cmp`)
- `pikkumies/lib/PanelAccess.php`, `pikkumies/lib/PanelHtpasswd.php`; tests `tests/PanelAccessTest.php`, `tests/BrowseSupervisorTest.php`
