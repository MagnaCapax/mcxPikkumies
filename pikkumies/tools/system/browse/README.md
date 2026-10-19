# browse

The browser the agent drives, and the guard that keeps it to this account. This page covers the guard (`guard.php`); the supervisor that starts the browser, the wrapper the agent calls and the skill are described by their own sections.

## Why there is a guard

On PMSS every account's web panel shares one origin: the server block routes `/user-<name>/`, `/public-<name>/`, `/~<name>/`, `/webdav-<name>/` and `/deluge-<name>/` for every account on the server (PMSS `etc/seedbox/config/template.nginx-user`). A browser logged in to one account's panel therefore sits on the same origin as every neighbour's public pages, and the browser's own ways to log in cannot be scoped to one path: a credential set for the session goes to every site, a header set on a page goes to every path of the origin, and a password the browser answered a challenge with can be offered again to another path of the origin (measured with agent-browser 0.26 and Chromium 1208, 2026-10-19, against a test server that used one realm for every account). The guard is the one place the panel login is added, and the one place the neighbours' paths are refused.

## What it does with each request

It holds one connection to the browser's DevTools endpoint and attaches to every target before it runs: pages, popups, iframes and workers (`Target.setAutoAttach` with `waitForDebuggerOnStart`, on the browser and again on each target). A target is let go only after its request interception (`Fetch.enable`, every URL, auth challenges included) is on; if interception cannot be turned on, the target is closed. A dedicated worker has no interception of its own; its requests pause in the page that started it, and that page's rules apply.

| request | answer |
|---|---|
| `/user-<self>/` or `/public-<self>/` on the panel host, asked for by the account's own page (the top-level document and the requesting frame's document are both on those prefixes) | sent with the login added |
| the same paths opened by the CLI (a top-level GET navigation whose URL the wrapper wrote to the intent file just before) | sent with the login added |
| a redirect | keeps the login only when the hop it came from carried it, so a foreign site redirecting to the panel gets none |
| the same paths asked for by any other page or frame: a foreign site's image, fetch, iframe or form POST | sent without the login; the panel answers 401 and the guard cancels it |
| anything else on the panel host: other accounts' paths of every kind, the root, shared files | refused before it is sent, and logged |
| a path on the panel host with a dot segment, a backslash, a second encoding or a control character once decoded (nginx decodes and resolves those before it picks an account's location) | refused, and logged |
| `/~<self>/`, `/webdav-<self>/`, `/deluge-<self>/` | sent without the login |
| every other site | sent with any `Authorization` header removed |
| any 401 challenge, anywhere | cancelled, so the browser never answers one from its own credential cache |

The rule is `WebGuardDecision.php` (no state, no I/O); `WebGuardEvents.php` tracks targets, frames and redirects and turns DevTools messages into answers; `CdpSocket.php` is the WebSocket; `WebGuardIntent.php` reads the intent file.

## Running it

```text
php guard.php --cdp ws://127.0.0.1:<port>/devtools/browser/<id> --log <file> --self <account> --host <panel host>...
              [--intent <file>] [--auth-file <file>] [--ready <file>]
```

- `--cdp` must be a `ws://` address on loopback, and the listening port must belong to this account (`Host::ownsListener`, [ADR-0019](../../../../docs/adr/0019-hosts-and-credentials.md)); the guard never connects to another account's browser.
- `--host` names the panel host the browser uses (repeatable, required): the fence applies to those names only, so a guessed default that missed the real one would leave the neighbours' paths open. The same server reached by another name or by its address is another origin: it cannot script the panel and gets no login, but it is not fenced.
- `--intent` (`local/browse/intent`): one line, the URL the CLI is about to open; consumed by the first matching navigation within 30 seconds. Only a regular file this account owns and nobody else can write counts. Without it, the CLI's own navigation never gets the login.
- `--auth-file`: one line, the `Authorization` value (`Basic <base64>`), a regular file this account owns, 0600 (a looser mode is set back to 0600). Read once at start; never on the command line, never logged, never printed. Without it nothing gets a login and the fence works the same. The supervisor passes the agent's own panel login here, in a file it deletes once the guard is ready ([ADR-0079](../../../../docs/adr/0079-the-agents-own-panel-login.md)).
- `--ready` (`local/browse/guard.ready`): written 0600 once the browser has answered the attach and every target that already existed has its interception on. Start the first navigation only after it exists.
- `--log`: JSON Lines, one record per refused request (time, reason, the first path segment such as `/user-<name>/`, method and request type), and one per target closed because its interception could not be turned on. Never a full URL, a query string or the login. Created 0600.

Exit codes: 0 after SIGTERM or SIGINT, 1 when it cannot start (bad option, bad login file, port not this account's, the browser refused the attach), 2 when the DevTools connection drops or closes. A browser whose guard has exited runs unchecked, so the supervisor stops the browser whenever the guard exits.

## Limits

- Interception covers what happens after the guard attached. A tab opened with a URL in one step navigates before the guard sees it, so the wrapper opens a blank tab and then navigates it.
- A popup starts with no known page, so a popup to the own panel gets no login, even from the account's own page.
- A service worker or shared worker that is not attached under a page has no page to vouch for it; its requests to the own panel get no login.
- A top-level navigation that is not the CLI's counts as the current page's: from the account's own page it carries the login.
- Behaviour was measured with headless Chromium 1208 (HeadlessChrome 145); the recorded session is `tests/fixtures/browse/chromium-1208-guard-session.jsonl`.
