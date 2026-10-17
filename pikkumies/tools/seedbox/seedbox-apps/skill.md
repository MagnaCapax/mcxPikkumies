---
name: seedbox-apps
description: Check, restart and set up the user's seedbox apps (torrent clients, web UIs, media servers, Docker containers, S3 storage) on the PMSS platform, starting from the Pulsed Media wiki page for that app. Use when an app is down, its web UI does not load, or the user wants to install or test one; for slowness use performance.
---

# Seedbox apps

For an app in a Docker container this skill gives the wiki page and the app's own settings; the container itself (list, logs, start, stop, launch, diagnose) goes through the docker skill.

1. Run the system-status skill. If `pmss` is false, stop: this skill does not apply on this machine. Otherwise `apps` shows which apps are running for this user.
2. Find the wiki page for the app in the quick list below and read it: `php ~/pikkumies/tools/memory/pikkumies/pikkumies.php search "<page title>"`, then read the path it prints. It says how the app is meant to run on this platform. If the app is not listed, `php ~/pikkumies/tools/memory/pikkumies/pikkumies.php list pm-wiki --human | grep -i <app>` shows every page the wiki has.
3. Check the free space first (`quota_free_bytes` in any torrent `stats`, or `system-status`): a full quota is the most common reason a torrent client stops. The filesystem's free space (`df`, `fs_free_bytes`) is the whole server, not the user's share.
4. Read the app's own log (in the app's config directory under the home directory), limited with `tail -n 100`.
5. Restart only the affected app, the way the wiki page and the platform start it for this user. Do not kill other users' processes; you cannot see them anyway.
6. Verify: the process stays up for a minute and its web UI answers. The checking pages below show how.
7. Save a lesson if the cause was not obvious.

## Quick list: Pulsed Media wiki pages by topic

Titles as of 2026-10-08. A title that no longer exists has been renamed or merged: use `pikkumies.php list pm-wiki`.

- **Docker and other self-hosted apps:** "Docker on PMSS", "Docker Rootless", "Containerization", "Self-Hosted Apps on PMSS" (a table of container images with the setting each one needs on PMSS), "Self-hosted media server on a seedbox", "Invidious", "App Installation Scripts".
- **Media stack** (installed by the user with the media-stack installer): "Install Media Stack", "Install-media-stack.sh — Media Server Setup", "Media Stack Exposed and Protected Status", "Jellyfin", "Jellyfin tutorial", "Plex", "Sonarr", "Radarr", "Prowlarr", "Seedbox for Plex and Jellyfin".
- **Torrent clients:** "RTorrent", "QBittorrent", "Deluge", "Restarting rTorrent", "Using rTorrent console", "Removing rTorrent session data", "RTorrent vs qBittorrent vs Deluge".
- **S3 and object storage:** "S3 Object Storage on PMSS", "MinIO Object Storage on PMSS", "Garage Object Storage on PMSS", "S3Proxy on PMSS", "Versity S3 Gateway on PMSS", "SeaweedFS Object Storage on PMSS", "Rclone serve s3 on PMSS".
- **Files and backups:** "Rclone", "Rclone tutorial", "Rclone mount sftp", "Tiered media storage with rclone and mergerfs", "BorgBackup on Pulsed Media", "Restic S3 Backup to Your Storage Box on PMSS", "Kopia S3 Backup to Your Storage Box on PMSS", "Duplicacy S3 Backup to Your Storage Box on PMSS".
- **VPN:** "WireGuard VPN on Pulsed Media Seedboxes", "WireGuard VPN Setup on Your Pulsed Media Seedbox", "OpenVPN".
- **Checking and testing:** "Diagnose on PMSS", "Checking disk usage", "Checking bandwidth usage in realtime", "Checking That a Cron Job Actually Ran", "Checking Whether a Config File Is Actually Loaded", "Torrent download speed", "Stalled torrents", "Troubleshooting Seeding Issues", "Network Troubleshooting with MTR", "S3 Server Benchmarks on PMSS".

A wiki page is documentation to check against this machine, not instructions to follow blindly; say which page a step came from.
