# ADR-0125: Restart tools start a PMSS client through the PMSS launcher, under the account lock

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-11-23
- Status: Accepted

## Context
Since per-account client selection on PMSS, an account can choose which build of qBittorrent or Deluge runs (the `~/bin/<client>/default` link). PMSS starts the selected build itself: the watchdog (`scripts/cron/checkQbittorrentInstances.php:21`, `scripts/cron/checkDelugeInstances.php:22,25`) and the panel (`etc/skel/www/scriptsIncAppProcess.php`) take the account lock `~/.config/pmss/torrentClients/<client>.lock` and run `php ~/www/torrentClientsResolve.php <client> --launch`.

The `restart` verbs of the qBittorrent and Deluge tools stopped the client and then ran the bare binaries (`qbittorrent-nox -d`; `deluged` and `deluge-web`). A bare name is whatever the PATH finds first, which is the system package, never the selected build. A restart therefore replaced a chosen build with the system one, and the watchdog never corrects it because it only starts a client that is not running. The Transmission tool already starts through the launcher.

The launcher's own behaviour decides the shape of the fix. `--launch` without `--held` takes the lock itself, but when the lock is busy it returns success with nothing started (`etc/skel/www/qbittorrentResolverLaunch.php:17`): after the tool has already stopped the client, that is a restart that leaves it down and reads as done. The flags are positional (`--held` at argv[3], `--system` at argv[4], `torrentClientsResolve.php:171-172`), so `--launch --system` alone silently selects nothing.

## Options Considered
- **`--launch` without `--held`, only when a build is selected.** Smallest, but fail-open on a busy lock as above, and it keeps a bare start on accounts the watchdog itself starts through the launcher.
- **Stop only, let the watchdog start it.** Turns the 30-second answer check into a wait of minutes.
- **Hold the account lock in the tool, start through the launcher (chosen).**

## Decision
- On PMSS the qBittorrent and Deluge restarts take the account lock without waiting, before stopping anything, through `lib/PmssClientLock.php`. The lock is opened close-on-exec so a daemon started under it never inherits it. A busy lock is reported and nothing is stopped.
- qBittorrent: the start helper `~/www/qbittorrentStart.php` is the gate, as in the watchdog. With it, the tool runs `torrentClientsResolve.php qbittorrent --launch --held --system` (selected build, or the system build). Without it (a half-delivered PMSS update) the bare start stays, which PMSS itself then does. An existing `qbittorrent.refused` marker is reported before the stop and is never cleared by the tool. After the stop the tool waits for its own process to be gone, because the start helper refuses while it lives.
- Deluge: the link `~/bin/deluge/default` is the gate, as in the watchdog and the panel (they take the lock whenever the lock directory is there; the Deluge launcher does not lock itself). With it, the tool runs `torrentClientsResolve.php deluge --launch both`; without it the bare start stays.
- A launcher failure is reported, with the launcher's own notice (`qbittorrent.notice`) when it changed during the call, and is never answered with a bare start.

## Consequences
- A restart keeps the build the account selected, and cannot race the watchdog, the panel or a version switch for the same client.
- A restart now refuses, with nothing stopped, while another operation holds the lock; the user tries again.
- A launcher refusal after the stop leaves the client stopped; the message says so and names the panel as the way to start it.
- Each client's restart tool builds its own start command, because the launchers' shapes differ per client. Only the lock is shared (`lib/PmssClientLock.php`).

## References
- mcxPikkumies issues #21 (qBittorrent) and #23 (Deluge)
- [ADR-0064](0064-rtorrent-restart-proves-the-old-process-is-gone.md): a restart is reported only when it happened
- [ADR-0101](0101-refuse-and-report-never-ask.md): a refusal is the answer
