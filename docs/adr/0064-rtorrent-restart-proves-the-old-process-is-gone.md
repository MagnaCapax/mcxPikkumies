# ADR-0064: rTorrent `restart` reports a restart only when the old process is gone

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted

## Context
On PMSS, `rtorrent restart` touches `~/www/.rtorrentRestart`, runs the platform's own `~/.rtorrentRestart.php`, then polls rTorrent until it answers, and has reported `answered: true` on the first answer. The answer was taken as proof that rTorrent restarted.

It is not proof. The PMSS script finds the user's rTorrent by the exact name `rtorrent` (`pgrep -u <uid> -x rtorrent` and `comm === 'rtorrent'`, PMSS `etc/skel/.rtorrentRestart.php:57, 72`). A running rTorrent of some builds names its main thread `rtorrent main` (PMSS issue #717; PMSS `scripts/lib/agentDiagnostics.php:65` notes the same for its own counter). On those hosts the script finds nothing to stop, logs that, and may start a second rTorrent beside the first. The old process keeps answering, so the tool said "restarted" when nothing had changed. The user then asks again, and the agent digs through the tool's code to find out why. The panel's restart button runs the same script, so it has the same limit.

The PMSS watchdog compensates for one of the two outcomes only: it restarts rTorrent when more than one runs (`scripts/cron/checkRtorrent.php:100-106`, every 2 minutes, `etc/seedbox/config/root.cron:7`), but a single old process that still answers is healthy to it and stays.

## Options Considered
- **Keep "answers" as success.** Wrong on the hosts #717 describes.
- **Stop the old rTorrent from Pikkumies** (SIGTERM, then SIGKILL). Fixes the symptom on the user's account, but the torrent-clients doctrine forbids restarting by hand, killing rTorrent risks its session files, and it hides the platform bug from the people who can fix it.
- **Prove the restart and say plainly when it did not happen (chosen).** Note the user's own rTorrent processes and their start times before the restart, compare after.

## Decision
- Before touching the marker, `RtorrentRestart` notes the account's own rTorrent processes: exact process names from `Detect::KNOWN_APPS['rtorrent']` (one list for the whole tool), owner uid, same mount namespace, through the new `lib/OwnProcs.php`, each with its start time (field 22 of `/proc/<pid>/stat`, so a reused pid is a new process).
- After rTorrent answers:
  - more than one rTorrent runs: error "not restarted cleanly: N rTorrent processes ... share one session", hint: do not stop either by hand; the watchdog restarts rTorrent when more than one runs; check `stats` in a few minutes; ticket if two still run;
  - a process from before (same pid and start time) still runs: error "not restarted: ... the platform restart did not take effect (a known platform issue, PMSS #717 ...)", hint: do not stop rTorrent by hand; tell the user; open a support ticket with this message;
  - otherwise success, with `processes_before` and `processes_after` (counts, never pids).
- No process ran before: an answer is a start, as before. Pikkumies never signals rTorrent.
- `lib/OwnProcs.php` becomes the one own-process finder: `DelugeRestart::pids` and `QbitProc::pids` delegate to it, keeping their behaviour (qBittorrent still finds nothing when its own namespace is unreadable, and still checks `exe`).

## Consequences
- On #717 hosts the verb now fails instead of claiming success, which is the truth; the fix belongs in PMSS (#717), and the tool's check stays useful after that because hosts update at their own pace.
- The check reads the account's own `/proc` entries only (no new privilege). Where `/proc` cannot be read, nothing is found before, and the verb behaves as it did.
- Not done here: `Detect::runningApps` still scans `/proc` its own way (a set lookup over all names), and `qbittorrent restart` still takes an answer as success; the qBittorrent restart stops the processes itself, so the #717 failure mode does not apply to it.

## References
- PMSS issue #717 (`.rtorrentRestart.php` exact name match), PMSS `etc/skel/.rtorrentRestart.php`, `scripts/cron/checkRtorrent.php`
- `pikkumies/tools/torrent-clients/DOCTRINE.md` (never restart by hand), [ADR-0015](0015-tool-units-one-skill-per-tool.md)
- Tests: `tests/RtorrentRestartProofTest.php`
