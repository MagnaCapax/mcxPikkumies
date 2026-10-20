---
name: rffmpeg
description: Delegate this account's Jellyfin/Emby transcodes to ffmpeg plus a GPU on another box of the user's own, over SSH. Use when the user wants hardware transcoding but their media box has no GPU, or wants to check whether a planned remote-GPU setup across their own two boxes is ready.
---

# rffmpeg

This wraps the third-party [rffmpeg](https://github.com/joshuaboniface/rffmpeg) project, installed
for this account only (a venv under `~/pikkumies/local/state/rffmpeg`, never a system path, never
root). It only ever talks to a host the user already added to the Services list
(`tools/seedbox/services`), and only when that service was added with a real shell-capable SSH key
(`transport: shell`) -- the restricted serve forced command cannot run ffmpeg.

1. Always run `plan --gpu <nick>` first, with `--mount <remote-path>` once the user has an rclone
   sftp mount of this box's home on the GPU box. Read its `steps`: it never aborts on a gap, it tells
   you exactly what to fix next. The most common gap is the "same-path problem" (README.md): Jellyfin
   on this box and the GPU host must see this account's media files at the identical absolute path,
   which on two separate seedboxes needs a mount plus a path-rewriting wrapper -- `plan` and
   `add-host` set that wrapper up for you; you only need to get the mount itself running and visible
   to a plain SSH-launched command (not just a systemd --user service -- see README.md's gotcha test).
2. `install` once `plan` says the local side (Jellyfin or Emby) is present. Paste the printed
   `ffmpeg`/`ffprobe` paths into Jellyfin's dashboard. `--force --with-data` reinstalls from scratch,
   but refuses while a GPU host is still recorded (remove it first) and never touches the log.
3. `add-host <nick> --allow-shell --mount <path>` wires in one GPU service: it probes ffmpeg and the
   GPU there itself, deploys the rewriting wrapper, and tells rffmpeg about the new host. `--allow-
   shell` is not optional noise -- say it only once you understand a shell-transport key can run
   anything on that host, which the service's own `transport: shell` setting already told the user
   when they added it.
4. `status` any time to see what is installed and rffmpeg's own host/process table.
5. `remove --with-data --allow-shell` undoes exactly what `install`/`add-host` created: the local
   venv, script, entry points and config, and the two wrapper files on each GPU host (by their
   recorded paths, not re-derived from the service's current settings). Without `--allow-shell` it
   still removes the local side but leaves the remote wrapper files in place and says so -- it never
   silently claims a remote cleanup it did not attempt.

rffmpeg's own identity (the SSH user and key it uses to reach every GPU host) is global, not per
host: `add-host` refuses a second host whose service uses a different user or key than the first.
