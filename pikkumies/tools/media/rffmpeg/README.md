# rffmpeg

Lets this account's Jellyfin or Emby hand transcodes off to ffmpeg plus a GPU on a *different* box
the user also controls, instead of needing a GPU on the media box itself. Wraps the third-party
[rffmpeg](https://github.com/joshuaboniface/rffmpeg) project (GPL-3.0, Joshua M. Boniface and
contributors), installed per account under `~/pikkumies/local/state/rffmpeg` -- a venv, never a
system path, never root (AGENTS.md: "installing the user's own third-party app is allowed; our code
stays PHP").

## Why this exists

A user with a storage-heavy or GPU-less seedbox, and a second box that has a working GPU, wants
hardware transcoding without moving their library: for example the media server and the media on one box, and an
Intel iGPU on the other.

## The same-path problem

rffmpeg's remote host must see the media files at the *exact same absolute path* as Jellyfin does
locally ("Mount your media directories in the same location(s) as on the media server" -- upstream
`docs/SETUP.md`, fetched 2026-10-20). On two separate accounts on two separate boxes that is never
true by default. This unit's answer:

1. The user runs an rclone sftp mount of this box's home on the GPU box (not built by this unit --
   `tools/storage/rclone` has no `mount` verb yet; the mount is the user's own setup, following the
   wiki page "Rclone mount sftp").
2. `add-host` deploys a tiny POSIX `/bin/sh` wrapper on the GPU box, named `ffmpeg` and `ffprobe`
   respectively, under `<gpu-home>/.pikkumies-rffmpeg/`. Each wrapper rewrites every argument (and
   `TMPDIR`) that starts with this box's home path to start with the mount path instead, then execs
   the real ffmpeg/ffprobe binary that `add-host` detected on that host.
3. rffmpeg's own config on this box points `commands.ffmpeg`/`commands.ffprobe` at those wrapper
   paths, never at the real binary directly -- so the rewrite always runs first.

### The systemd --user mount gotcha

A mount started as a `systemd --user` service is **not visible to a plain SSH-launched command**,
which is exactly how rffmpeg runs the remote ffmpeg. `plan --gpu <nick> --mount <path>` and `add-host` both run the test this unit's design is
built around:

```sh
ssh <gpu-host> ls <mount>
```

If that comes back empty or erroring while the mount is supposedly active, the mount is running in a
session the SSH login cannot see. Start it a different way (not as a `systemd --user` unit under a
non-lingering session), then retry.

## Why ffmpeg/ffprobe live in a second directory with no "rffmpeg" in its name

The pinned script's own `__main__` reads `"rffmpeg" in cmd_name`, where `cmd_name` is `sys.argv[0]`
exactly as invoked (a symlink's shebang passes the symlink's OWN path through, never its resolved
target) -- so the path Jellyfin is configured to call must never contain that substring, or the
script dispatches to its own `init`/`add`/`status` control CLI instead of transcoding. `install`
creates `~/pikkumies/local/state/gpu-transcode/ffmpeg` and `.../ffprobe` ("rffmpeg"-free) as the
paths to paste into Jellyfin's dashboard "Custom FFmpeg path" setting; each is a tiny POSIX `/bin/sh`
script that exports `RFFMPEG_CONFIG` (Jellyfin itself is never told about it) and then execs a
same-directory symlink (`.target-ffmpeg`/`.target-ffprobe`, also "rffmpeg"-free) pointing at the real,
pinned control script -- which legitimately lives under `~/pikkumies/local/state/rffmpeg/` so its own
`init`/`add`/`status` keep dispatching correctly.

## The SSH host-key pin

Upstream's own `generate_ssh_command` always sends `-o StrictHostKeyChecking=no -o
UserKnownHostsFile=/dev/null` with no `-F /dev/null`, before `remote.args` -- and ssh keeps the FIRST
value given for a repeated option, so that cannot be overridden from `remote.args` alone. `install`
points rffmpeg's `commands.ssh` at a generated PHP wrapper (`ssh-wrapper.php`, in the same state
directory) that becomes the actual `ssh(1)` rffmpeg execs: it reads the one recorded host's nick from
this unit's own `state.json`, calls `Services::refuseListed($host)` on every single connection (not
just at `add-host` time), then PREPENDS `-F /dev/null -o UserKnownHostsFile=<that service's pinned
known_hosts> -o GlobalKnownHostsFile=/dev/null -o StrictHostKeyChecking=yes -o IdentitiesOnly=yes -o
BatchMode=yes` before forwarding everything upstream passed it. Because those come first, they win.
No recorded host, no Services list, or a denied/unpinned host all refuse to connect.

## What this unit never does

- Never touches a system path: the venv, the pinned script, the wrappers/symlinks, the ssh security
  wrapper and the config all live under this account's own `~/pikkumies/local/state/`.
- Never uses the Services forced-command serve transport to run ffmpeg: that whitelist has no ffmpeg
  verb (SPEC.md "Remote serve"), so a GPU host must be added with `transport: shell`, and every call
  that actually uses that shell needs the caller's explicit `--allow-shell`.
- Never deletes anything outside its own recorded paths. `remove` undoes exactly what `install` and
  `add-host` created (see USAGE.md), using the wrapper paths it RECORDED at `add-host` time rather
  than re-deriving them from the service's current `home`; a remote cleanup it could not attempt (no
  `--allow-shell`, or the service record is gone) is reported, never silently assumed done. A
  top-level symlink among its own directories is unlinked, never followed into and emptied.
- `install --force` never deletes while GPU hosts are still recorded, never deletes without
  `--with-data` (the delete-guard's own recognized flag), and never deletes the log.
- Never installs an unpinned Python dependency: `click` and `PyYAML` are pip-installed with
  `--require-hashes --only-binary=:all:` against sha256 hashes fetched and verified from PyPI's own
  JSON API this session, never a guessed value.

## Sources

- [github.com/joshuaboniface/rffmpeg](https://github.com/joshuaboniface/rffmpeg) README.md and
  `docs/SETUP.md`, fetched 2026-10-20: installation, the SSH-key and same-path requirements, the
  `click`/`yaml` dependencies, the `RFFMPEG_CONFIG` environment variable, and the `-w`/`--weight` host
  option.
- [rffmpeg.yml.sample](https://github.com/joshuaboniface/rffmpeg/blob/master/rffmpeg.yml.sample) and
  the pinned script's own `load_config()` (read directly, not summarized): the `logging`,
  `directories`, `remote` and `commands` config groups this unit writes.
- The pinned commit, `f54ac843c7c8a157562e7ce37aaebbc5cb41354e` (2025-11-03, GPG-verified merge
  commit per `api.github.com/repos/joshuaboniface/rffmpeg/commits/<sha>`), was chosen over the only
  git tag (`v1`, 2022-07-19) because that tag's script is named `rffmpeg.py`, not `rffmpeg`, and
  predates the `add`/`remove`/`status`/`clear` commands and the `init --no-root` flag this unit
  depends on -- confirmed with `git ls-remote` and a direct `curl`/`git clone` of the tag, not from a
  web-page summary alone.
- `tools/media/ffmpeg/FfmpegHw.php` (the local GPU-render-node check this unit's remote probe mirrors)
  and `tools/media-servers/jellyfin/JellyfinLocate.php` / `tools/media-servers/emby/emby.php` (the
  local Jellyfin/Emby presence markers `RffmpegLocal.php` reuses).
