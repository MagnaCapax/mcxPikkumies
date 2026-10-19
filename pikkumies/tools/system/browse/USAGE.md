# browse

The isolated browser behind Hermes' browser tools: agent-browser and the server's Chromium in a private network namespace of the account, a guard in front of every request ([ADR-0077](../../../../docs/adr/0077-isolated-browser-in-a-private-network-namespace.md)).

| verb | arguments | changes | what it does |
|---|---|---|---|
| up | `[--human]` | yes | Start the browser now: the readiness checks, the memory gate, the namespace, slirp4netns, agent-browser with Chromium, the isolation check, the guard. One record as `status` gives, `verified` when it runs. A failed check is a refusal with the reason; nothing is left running. |
| down | `[--human]` | yes | Stop the browser and everything started for it. `stopped` (it was running), `running` (always false after). |
| status | `[--human]` | no | `may_run` and `off_because` (the first failing check), `running`, `agent_browser` (the version the Hermes lock pins), `agent_browser_fetched`, `isolated`, `guard` (`up`/`down`), `idle_seconds`, `stops_after_idle_seconds`. |

```sh
php ~/pikkumies/tools/system/browse/browse.php status          # may it run here, does it run
pikkumies browse up                                             # the same verbs from the pikkumies command
pikkumies browse down
```

- It starts by itself on the first browser call and stops after 15 minutes without one (`stops_after_idle_seconds`), so `up` and `down` are rarely needed.
- `cli` and `watch` are internal entries, not verbs: `cli -- <args>` is what the `agent-browser` command on Hermes' PATH runs, and `watch` is the watcher a start leaves behind.
- Everything it keeps is in `~/pikkumies/local/browse/` (0700): the agent-browser binary under `bin/<version>/`, the Chrome profile, the socket, `browse.log` (starts and stops) and the guard's log of blocked requests.
- The panel login is the agent's own (`pikkumies access status`) where the panel accepts it: made by itself and handed to the guard in `local/browse/panel-auth` (0600), deleted once the guard has read it ([ADR-0079](../../../../docs/adr/0079-the-agents-own-panel-login.md)). Elsewhere it comes from `~/pikkumies/local/secrets/browse-panel-auth` (one line, the Authorization header value, mode 0600) when the user stored one; without either the panel answers 401 and everything else works.
