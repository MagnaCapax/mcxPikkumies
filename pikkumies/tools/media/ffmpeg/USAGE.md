# ffmpeg

Inspects and converts the user's own media files with ffmpeg/ffprobe, found on PATH (`pikkumies/lib/ToolProc.php`). Shared rules: `tools/media/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| probe | `<file> [--json]` | no | `container`, `duration_seconds`, `bit_rate_bps`, `streams` (one record per stream: `codec`, `width`/`height`/`fps` for video, `channels`/`sample_rate` for audio, `language`), and `verdict`: "plays directly in a browser" (H.264, or AV1/VP9 in webm, video + AAC/Opus/MP3 audio, in mp4 or webm) or what would need converting. `--json` is a no-op: JSON Lines is already the default output; `--human` is the other form. |
| hw | | no | `device` (`/dev/dri/renderD128`), `exists`, `readable`, `writable`, `device_group`, `account_groups` (this account's own groups), `gap` (null when usable, else the exact reason), `vainfo` (its driver summary line, when installed). |
| convert | `<in> <out> --preset P [--force] [--max-hours N]` | yes | Converts with one of the fixed presets (below). Refuses when free quota is smaller than the input file; refuses to overwrite an existing output without `--force`; refuses the same file as both input and output. Runs at nice 19, idle I/O, with a time cap (`--max-hours`, default 6, at most 20). Returns `in`, `out`, `preset`, `output_bytes`, `seconds`. |
| jobs | | no | This account's own running `ffmpeg`/`ffprobe` processes (by uid and process name, like `torrent-clients/qbittorrent`'s own-process check): `pid`, `command`, `elapsed_seconds`. |

## Presets (`convert --preset`)

| preset | what it does |
|---|---|
| remux-mp4 | `-c copy`: repackages without re-encoding (fast; only works between compatible containers/codecs) |
| audio-mp3 | drops video, encodes audio to MP3 |
| audio-opus | drops video, encodes audio to Opus |
| speech-16k | drops video, mono, 16 kHz — what `media/transcribe` sends for transcription |

```sh
F=~/pikkumies/tools/media/ffmpeg/ffmpeg.php
php $F probe ~/downloads/movie.mkv --human
php $F hw --human
php $F convert ~/downloads/movie.mkv ~/downloads/movie.mp4 --preset remux-mp4
php $F jobs --human
```

- Paths are resolved with `realpath` and must be inside the account's home (`pikkumies/lib/HomePath.php`), the same rule as `storage` and `backup`.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
