# ADR-0066: `system-status health`: one answer to "is my seedbox down?"

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted. Amends [ADR-0058](0058-account-status-verb-and-live-figures-before-search.md) (a plain `health` call is allowed while the search-first gate is closed, like `show` and `account`).

## Context
The operator's request of 2026-10-19, verbatim: "near deterministic tools towards those goals integrated into pikkumies as possible, so it can do those tasks with minimal token consumption --- or links to wiki, KB, documen tations etc. clearly we cannot do anything about seedbox down ... except for sometimes people think rtorrent crash is seedbox down ...".

When a user says "my seedbox is down", Pikkumies is running, so the server is up. What the user sees is usually one of: the web panel shows PMSS's 502 page with a reason, a torrent client crashed or was stopped, or the quota is full. Today the agent finds that out over many rounds: a search, `system-status`, logs, each client's `stats`, wiki pages. Facts checked before building:
- PMSS's 502 page for the account names one of seven reasons under a fixed "Specific status:" line, as its headline (`scripts/lib/lighttpd/watchdogErrorPageFile.php:14-40`); nginx serves it for `/user-<name>/` (`etc/seedbox/config/template.nginx-user:15`).
- PMSS's own diagnostics probe the same address with one local GET (`scripts/lib/agentDiagnostics.php:133`). The lighttpd watchdog counts as a failure only a 502 that lighttpd itself did not answer (`scripts/lib/lighttpd/watchdogNginxLog.php:31-38`), so a probe adds one only when lighttpd is really down.
- PMSS checks rTorrent every 2 minutes and starts it once it has been missing 180 s (`etc/seedbox/config/root.cron:7`, `scripts/cron/checkRtorrent.php:22`).
- The memory level PMSS reports (LOW, MEDIUM, HIGH, THROTTLED) is in `userShowLimits` (`webCgroupMemoryStatus.php`), already read by `account`.
- A crashed rTorrent leaves `~/.rtorrent.socket` behind, so the socket file alone does not prove rTorrent runs.

## Options Considered
- **A new tool unit or a zero-model `pikkumies health` subcommand.** A unit duplicates `system-status`; a subcommand needs a change to [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md) and its value is unmeasured. Deferred.
- **Copy the panel Diagnose thresholds** (95 % disk, 3 s answer). A third copy of numbers PMSS already turns into states. Rejected: use PMSS's states.
- **Count restarts in the lighttpd error log, read host PSI.** The 502 page already carries PMSS's own restarting/PHP diagnosis, and the performance tool owns PSI. Rejected.
- **A verb in `system-status` that composes existing readers (chosen).**

## Decision
- `system-status health` returns one record: `summary` (the first finding, or that all is well), `findings` (fixed sentences, most blocking first), `machine` (`up_s` and `not_shown`: "whether the user's own network reaches this server"), `panel`, `clients`, `quota`, `memory`.
- **Panel (PMSS only):** one GET of `http://localhost/user-<own name>/` through `lib/Http.php` (10 s, 64 KiB, the web server's system-owned listener allowed). Any answer of the account's own web server except 502/504 is `answers` (a login prompt or a redirect too; the panel's Diagnose counts only 200 and 401). A 502 whose "Specific status:" headline is one of PMSS's seven is `platform_reason` with PMSS's reason key and a fixed next step; anything else is `no_answer`, with Diagnose in the client area or a ticket as the next step. The record keeps the key, never the address or the name.
- **Clients:** for rTorrent, qBittorrent and Deluge, the same cheap locate each client unit starts with (no login, no API call), and when the account's own process started (`lib/OwnProcs.php`, stat field 22 against `/proc/uptime`). A client that started more than 10 minutes after boot is `started_after_boot`; started within the last hour it becomes the finding "started N minutes ago while the machine kept running: the client restarted, not the server". On PMSS, an rTorrent socket with no rTorrent process is `not_running`, with the watchdog timing as the next step. Clients that are not installed, or not enabled (except rTorrent, the default client), are left out.
- **Quota:** the storage unit's own state and sentence (`StorageStats`); at the hard limit or with the server's disk full it is a finding.
- **Memory (PMSS):** the level PMSS reports; HIGH or THROTTLED is a finding that points to the performance tool.
- **Documentation:** a finding's `doc` is resolved at run time from the downloaded documentation by page title (`DocSources::pageUrl`; "Seedbox Storage Quota", "Diagnose on PMSS"): null when the copy is missing, never a guessed or stored address.
- **Search-first gate:** `search-first-gate.php` allows `show|account|health` as one plain call, for the reason ADR-0058 gave for `account`: the live record is the source a search would point at. Logs and chained, piped or substituted calls still wait.
- **SOUL:** one line in `tools/system/DOCTRINE.md` sends "down", "not loading" and 502 questions to `health` and says a whole-server outage is out of reach; the system-status skill description gains those words in English and Finnish.

## Consequences
- A "down" question can be answered in one call. Whether the agent does so is measured by the question suite (`seedbox-down`, `fi-seedbox-down`, `rutorrent-502`: `first_call` health, `max_calls`).
- Reaches users who ask Pikkumies; web-only users see PMSS's 502 page and Diagnose already.
- Not done: the expired grace period (the storage unit does not parse it yet), deleted-but-open files (shipped 2026-10-19 once PMSS `userShowLimits` reported "held by deleted files": a finding when it is 1 GiB or more and the quota is over or 90% used), and the left-behind session files of a crashed rTorrent.
- The locate classification reads the first words of each client unit's own error ("not installed", "not enabled", "not running"); a reworded error falls to `unreachable` with the unit's own text, never to a wrong state.

## References
- [ADR-0058](0058-account-status-verb-and-live-figures-before-search.md), [ADR-0064](0064-rtorrent-restart-proves-the-old-process-is-gone.md) (`lib/OwnProcs.php`), [ADR-0023](0023-host-detection-cache.md) (what the model may see), [ADR-0039](0039-question-suite.md)
- Tests: `tests/SystemStatusHealthTest.php`, `tests/HookSearchTest.php`
