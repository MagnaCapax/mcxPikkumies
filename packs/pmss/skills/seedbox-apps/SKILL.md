---
name: seedbox-apps
description: Check and restart the user's seedbox apps (torrent clients, web UIs, media servers) on the PMSS platform. Use when an app is down, slow, or its web UI does not load.
---

# Seedbox apps

1. Run the system-status skill. If `pmss` is false, stop: this skill does not apply on this machine. Otherwise `apps` shows which apps are running for this user.
2. Check free disk first: a full disk is the most common reason a torrent client stops.
3. Read the app's own log (in the app's config directory under the home directory), limited with `tail -n 100`.
4. Restart only the affected app, the way the platform starts it for this user. Do not kill other users' processes; you cannot see them anyway.
5. Verify: the process stays up for a minute and its web UI answers.
6. Save a lesson if the cause was not obvious.
