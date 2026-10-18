# ffmpeg

Inspects and converts the user's own media files with the account's own ffmpeg/ffprobe (ADR-0045). One file at a time, on request: a long library of transcodes belongs to the media server (Jellyfin/Plex) instead, which already watches a library and transcodes on demand; this tool does not replace it and does not run on a schedule.

## Why no GPU transcoding across machines

Hardware transcoding needs a GPU render device on the machine actually doing the work. There is no remote-GPU or `rffmpeg`-style tooling here: when the user wants GPU transcoding, the answer is to run the media server on the machine that has the GPU, not to ship the work to it. `hw` only reports whether this account's own machine has a usable render device, so the skill can tell the user that much without guessing.

## Safety

- Every ffmpeg/ffprobe call is an argv through `pikkumies/lib/ToolProc.php` (`escapeshellarg`'d, never a shell string), with `-nostdin -hide_banner -protocol_whitelist file,pipe,crypto,data` so a crafted filename cannot be read as another protocol.
- Paths are always `realpath`'d to an absolute path first (`pikkumies/lib/HomePath.php`): a name starting with `-` is never read as an option, a symlink cannot point outside the account's home, and the input must be a regular file (no device nodes, no fifos).
- `convert` checks free quota (`pikkumies/lib/Quota.php`) against the input file's size before it starts, never overwrites an existing output without `--force`, and refuses the same path as both input and output.
- Long conversions run at nice 19 and idle I/O (`ToolProc::gentle`) with a cooperative time cap (`--max-hours`); the host itself may stop a very long-running process after about a day regardless.
- `jobs` only looks at this account's own PIDs (owner uid and process name, `/proc`), the same check `torrent-clients/qbittorrent`'s own-process code uses.

## Tests

`tests/MediaToolsFfmpegTest.php` against fake `ffmpeg`/`ffprobe` scripts on a temporary PATH (`tests/fixtures/mediatools/`): probe verdicts, the convert argv (protocol whitelist, the gentle wrapper), quota refusal, overwrite-without-force and in==out refusal, path confinement (outside home, symlink escape, a device file), and the hw gap text with fake `/proc`-free group data (seams, not a real device).
