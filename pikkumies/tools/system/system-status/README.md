# system-status

What the machine looks like to the agent: the operating system, which seedbox host it is (Pulsed Media's PMSS is checked first), whether root is available, which of the user's own apps are running, and how much CPU, memory and home disk there is. The agent runs it before diagnosing anything. Its `account` verb answers "how much disk, memory and traffic do I have left" in one call from the platform's own live figures (PMSS `userShowLimits`). Its `health` verb answers "is my seedbox down?" in one call ([ADR-0066](../../../../docs/adr/0066-system-status-health-one-answer-to-is-it-down.md)): the machine is up (the agent runs on it); on PMSS the account's web panel answers, or the platform's own 502 page names the reason; each torrent client is reachable or says why, and when its process started (a client that started long after boot restarted on its own); the quota state (the storage unit's) and the memory level PMSS reports. Unlike the panel's Diagnose, any answer from the account's own web server counts as up, a redirect or a login prompt included. The panel probe is one plain HTTP GET of the account's own `/user-<name>/` path on this machine, as PMSS's own diagnostics do (`scripts/lib/agentDiagnostics.php:133`); the record never names the account, the server or that address.

The tools rule that ships in SOUL (`tools/system/DOCTRINE.md`, quoted with its reason in the performance README):

- You run on this server, so it is up while you answer; for "down", "not loading" or a 502, run `system-status health` once and answer from it; a whole-server outage is out of your reach.

It reads `/proc`, `/etc/os-release` and the user's own processes only. It never looks at other users' processes.
