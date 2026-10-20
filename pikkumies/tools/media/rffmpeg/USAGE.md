# rffmpeg

Remote-GPU transcoding for this account's Jellyfin or Emby, over SSH to another box of the user's
own (a Services entry, `tools/seedbox/services`). Wraps the third-party
[rffmpeg](https://github.com/joshuaboniface/rffmpeg) project in a per-account venv; see README.md for
the full "same-path problem" and the systemd --user mount gotcha.

| verb | arguments | changes | what it does |
|---|---|---|---|
| plan | `--gpu <nick> [--mount <remote-path>]` | no | Checks Jellyfin/Emby locally, ffmpeg + the GPU render node on the `<nick>` service (needs its `transport: shell` and `--allow-shell`), and the same-path mount if `--mount` is given. Never refuses on a gap: `ready` is `false` and `steps` lists exactly what to fix, in order. |
| install | `[--force] [--with-data]` | yes | Creates `~/pikkumies/local/state/rffmpeg` (venv, hash-pinned `click`+`PyYAML`, the checksum-verified pinned rffmpeg script, the generated SSH security wrapper, the nested config) and `~/pikkumies/local/state/gpu-transcode` (the `ffmpeg`/`ffprobe` entry points Jellyfin's dashboard should point at -- their path never contains "rffmpeg", which the pinned script itself checks to choose transcode vs. control mode), then runs rffmpeg's own `init`. Refuses if already installed without `--force`; `--force` also needs `--with-data` and refuses while any GPU host is still recorded (remove it first), and never deletes the log. |
| add-host | `<nick> --allow-shell --mount <remote-path>` | yes | Probes `<nick>` for ffmpeg/ffprobe and GPU access, checks the mount is visible over a plain SSH-launched command, deploys the path-rewriting wrapper there, and runs rffmpeg's own `add`. Refuses a second host that does not share the first host's SSH user/key (rffmpeg's identity is global). |
| status | | no | What is installed (`installed`, `script_commit`, `hosts`) plus rffmpeg's own `status` output when a venv exists. |
| remove | `[--with-data] [--allow-shell]` | deletes | Without `--with-data`: a dry-run listing of what would be removed. With `--with-data` (the delete-guard convention for a `remove` verb, [ADR-0020](../../../../docs/adr/0020-deletes-need-the-users-approval.md)): removes the local venv/script/symlinks/config. Also needs `--allow-shell` to clean up each GPU host's wrapper files; without it those files are left in place and the output says so, per host. |

```sh
php ~/pikkumies/tools/media/rffmpeg/rffmpeg.php plan --gpu n100box
php ~/pikkumies/tools/media/rffmpeg/rffmpeg.php plan --gpu n100box --mount ~/mnt/le6-home
php ~/pikkumies/tools/media/rffmpeg/rffmpeg.php install
php ~/pikkumies/tools/media/rffmpeg/rffmpeg.php add-host n100box --allow-shell --mount ~/mnt/le6-home
php ~/pikkumies/tools/media/rffmpeg/rffmpeg.php status
php ~/pikkumies/tools/media/rffmpeg/rffmpeg.php remove --with-data --allow-shell
```

- `<nick>` must already exist in the Services list with `transport: shell`; the forced-command serve
  transport has no ffmpeg verb in its whitelist (SPEC.md "Remote serve"), so it cannot be used here.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the
  same records for people.
