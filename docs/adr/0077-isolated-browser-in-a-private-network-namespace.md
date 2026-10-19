# ADR-0077: The browser runs in a private network namespace of the account, behind a guard

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0021](0021-web-access-off-unless-configured.md) (out of the box there is now browser rendering, when the server has Chromium; lazy installs stay off) and [ADR-0024](0024-hermes-features-on-at-install.md) (the seed now sets one browser key, `browser.restrict_evaluate`). [ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md) is unchanged: Hermes' install still declines agent-browser.

## Context
The operator asked on 2026-10-19 for the full browser kit: Pikkumies logs in to the user's panel and apps, reads pages and takes screenshots, managed by itself with no setup questions. Hermes has the browser tools (a Node CLI, agent-browser, driving Chromium). On a shared seedbox server that browser is a security boundary.

Facts checked in agent-browser 0.26.0 (the version Hermes v0.22.2 pins in `pm/lock.json`) and Hermes v0.22.2:
- agent-browser drives Chrome over a CDP port on 127.0.0.1 (`--remote-debugging-port=0`) and always opens a stream WebSocket next to it. Neither has authentication, and there is no pipe mode. Any account on the server can reach the host's 127.0.0.1, so a co-tenant could take over a logged-in browser.
- The CLI reaches its daemon over a unix socket in `AGENT_BROWSER_SOCKET_DIR`. When no daemon answers, the CLI starts a new one.
- agent-browser has no way to send the panel's Basic login to one path only. Chromium's own login cache sends one account's credentials to a neighbour's `/user-<name>/` path on the same server, because every account's panel uses the same realm. Request routes cover only one tab, and `tab new <url>` navigates before anything can attach to the new tab.
- Hermes finds agent-browser in pm's store or on `PATH` (`tools/browser_tool_install.py` `_find_agent_browser`), and Chromium through `AGENT_BROWSER_EXECUTABLE_PATH` (`hermes_cli/browser_runtime.py`). It advertises the browser tools only when both are present (`check_browser_requirements`). It reads `browser.restrict_evaluate`, `browser.allow_private_urls` and `browser.use_real_profile` from the user's own config.yaml (`read_raw_config`), so the managed scope of [ADR-0062](0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md) cannot pin them. With `use_real_profile`, Hermes starts a Chrome of its own with a CDP port in the host's network namespace (`tools/browser_tool_real_profile.py`).
- Tested on a Debian 13 development host (util-linux 2.41, slirp4netns 1.2.1), without root:
  - `unshare --user --map-root-user --net --mount`, with lo up, a resolv.conf naming 10.0.2.3 bound over /etc/resolv.conf, and a nested `unshare --user --map-user=<uid>`, gives a namespace in which Chrome keeps its sandbox. CDP and stream ports listen only inside it.
  - `slirp4netns --configure --disable-host-loopback`, attached through the outer process, gives the namespace outbound HTTPS and DNS, and the host's public address (the panel), but not the host's 127.0.0.1.
  - slirp4netns has to join through a process of the outer user namespace, so the outer shell stays alive.
  - slirp4netns outlives the namespace unless it is stopped.
  - Chrome resets the oom_score_adj it inherits (the browser to 0, renderers to 200-300).
  - With one blank page the namespace's processes summed about 540 MB RSS. Shared pages are counted per process, so the real figure is lower.

## Options Considered
- **Hermes' browser as it is.** The CDP port on the shared 127.0.0.1 is an open door; rejected.
- **A BPF-LSM rule on the server forbidding connections to another account's loopback ports.** Protects every service, but it is new kernel policy on the PMSS side; not for this change.
- **Rootless containers (rootlesskit).** Needs subordinate uid ranges that only Docker-enabled accounts have.
- **A private network namespace per account, made by the account with unshare and slirp4netns, the CLI always run inside it, and a guard on the CDP connection.** Chosen.

## Decision
- **Unit `tools/system/browse`** (`browse` because `web` is a Hermes toolset). `pikkumies browse up|down|status`; its state is in `local/browse/` (directories 0700, checked for owner and mode before use, symlinks refused).
- **Start**, in order. Any failure stops everything started so far and is one plain line:
  1. Readiness: `/usr/bin/chromium`, the tools, user namespaces on, Hermes installed with an agent-browser pin, the Hermes source facts above, and the three config keys.
  2. A memory gate. 600 MiB must be free in the account's own cgroup limit, or in MemAvailable when the account has none.
  3. The holder, then slirp4netns.
  4. agent-browser `open about:blank` inside the namespace under `choom -n 1000`.
  5. The isolation self-check. The daemon must be in the holder's network namespace, and `ss -ltnpH` in the host namespace must show no listener of the browser stack. A failed `ss` fails the check.
  6. The guard, inside the namespace, on the CDP address. The browser is usable only after the guard writes its ready file, within 10 s.
  7. The watcher.
- **The binary** is the one the installed Hermes lock pins: the npm tarball's URL and sha256 (`packages.agent-browser`, target or `any`). It is fetched as the account, checked, and only this machine's `package/bin/agent-browser-<target>` is unpacked, 0700, with its own sha256 recorded. A mismatch refuses. A binary that changed on disk is refused, never replaced silently.
- **The agent-browser command on Hermes' PATH** is a generated two-line exec into `browse.php cli`. `pikkumies run` and the cron tick prepend its directory to `PATH`, and set `AGENT_BROWSER_EXECUTABLE_PATH`, only while the readiness checks pass. Otherwise Hermes offers no browser tools and nobody is asked anything. Each call:
  - is pinned to one session;
  - refuses options and commands that attach to, launch or configure another browser (`--cdp`, `--auto-connect`, `--provider`, `--profile`, `--args`, `--executable-path`, `connect`, `chat`, `install`, ...);
  - turns `tab new <url>` into `tab new` then `open <url>`, also inside a batch;
  - writes each open's URL as the guard's intent (0600) first;
  - answers `close` without closing, because the browser is shared by the account's sessions;
  - runs the real CLI only through `nsenter --preserve-credentials -U -n -m` into the namespace, with an environment of its own, so even a daemon the CLI starts by itself lands inside.
  The browser starts on the first call. A part found gone stops everything before the call is refused.
- **Stop.** The watcher checks every 5 s. When any part is gone (the guard exits when its CDP connection drops, so a dead browser or a dead guard both count), or after 15 minutes without a call, every process of the account in the namespace's network namespace gets TERM, then KILL, and so do slirp4netns and the watcher. Each round it also sets every namespace process back to oom_score_adj 1000, so the browser goes before the account's torrent client.
- **Hermes config.** The seed sets `browser.restrict_evaluate: true`. An older install gets it once through `hermes config set` (backed up and logged, as the skin is) when its file does not name the key. The browser stays off while the key is not true, or while `allow_private_urls` or `use_real_profile` is on, and doctor says which. The user's value is never overwritten.
- **Doctor** prints `browse: ready (chromium V, agent-browser V, isolated, guard up)`, the stopped form, or `browse: off (<the failing check>)`.
- The guard decides each request on the panel host:
  - the panel login is added only on the account's own `/user-<name>/` and `/public-<name>/` paths, only when both the requesting frame and the top-level page are the account's own, or for a top-level GET the CLI opened;
  - a redirect keeps the login only when the hop before it carried it;
  - every other path on the panel host is blocked before it is sent (other accounts' routes, and any path that is not plain once decoded); WebDAV never gets the login, because it opens the whole home;
  - every 401 challenge is cancelled, so cached credentials never answer another prefix;
  - `--host` is required, because a guessed default could leave the fence open;
  - the intent file is one-shot, consumed by the first matching top-level GET within 30 s.
  Known gaps: a popup to the own panel gets no login; the same server reached by another name or by its address is not fenced, but other accounts' paths there get no login and their own realm's 401 is cancelled. The WebSocket client is on the architecture test's network-egress allowlist. Details and exit codes are in the unit's README.

## Consequences
- Pikkumies can browse the user's services out of the box on servers with Chromium, slirp4netns and user namespaces. Elsewhere it stays quiet.
- Every start costs about a second plus Chrome, and the first start also fetches the CLI (about 10 MB). Concurrent sessions of one account share one browser and its snapshot refs.
- Page JavaScript inside the namespace can still reach the host's public and private addresses through slirp, as any process of the account can. Hermes' own private-URL guard covers only the agent's navigations.
- The guard's CDP address is on its command line; on a hidepid=2 server only the account sees it.
- A Hermes release that renames one of the facts turns the browser off until Pikkumies follows, without stopping the agent.
- Not verified on a PMSS host: Chromium from Debian's package (tested with a Playwright Chromium build), the cgroup memory gate on a real account limit, a two-account co-tenant test, and Hermes' browser tools end to end through the wrapper. The wrapper, the namespace, slirp4netns, the guard and the stops were run on the development host.

## References
- ADR-0021, ADR-0024, ADR-0033, ADR-0062, [ADR-0019](0019-hosts-and-credentials.md) (secrets in `local/secrets/`), [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) (no questions)
- agent-browser 0.26.0 (`--help`, README), Hermes v0.22.2: `pm/lock.json`, `pm/packages.py`, `tools/browser_tool_install.py`, `tools/browser_tool_session.py`, `tools/browser_tool_cloud.py`, `tools/browser_tool_eval_policy.py`, `tools/browser_tool_real_profile.py`, `hermes_cli/browser_runtime.py`, `hermes_cli/config.py` (`read_raw_config`)
- `pikkumies/tools/system/browse/`; tests `tests/BrowseArgvTest.php`, `tests/BrowseIsolationTest.php`, `tests/BrowseBinaryTest.php`, `tests/BrowseCheckTest.php`, `tests/BrowseSupervisorTest.php`
