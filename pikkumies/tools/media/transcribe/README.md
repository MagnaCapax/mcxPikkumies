# transcribe

Speech to text for one of the user's own audio or video files: an OpenAI-compatible cloud endpoint when the user has logged in, with a local whisper.cpp engine as the fallback and as the only option when `--local` is given or no login is stored (ADR-0045).

## The login (ADR-0019)

`login` is the only way in: the user runs it at their own terminal, which prompts for the endpoint (an OpenAI-compatible base URL; https required unless it is this account's own local listener — `pikkumies/lib/Http.php` already refuses a local port another account owns), the model name (required; there is no default to fall back to silently) and the API key (never echoed). All three are stored together as one secret, `pikkumies/local/secrets/transcription` (`pikkumies/lib/SecretFile.php`: directory 0700, file 0600, written to a temporary file and renamed). Binding the endpoint and the key in one secret means a later change to one does not quietly send the other's key to a different place: changing the endpoint needs running `login` again. There is no `provider`/`endpoint`/`model`/`key` setting in `local/config.php`; the agent can change ordinary config, but the write path for `local/secrets/` is the login prompt alone.

## `run`

1. Resolves and confines the input path (`pikkumies/lib/HomePath.php`), the same rule as `storage` and `backup`.
2. Converts it to 16 kHz mono WAV (about 1.9 MB per minute: uncompressed, kept because OpenAI-compatible servers accept it) with the `speech-16k` preset (`tools/media/lib/Ffmpeg.php`), inside a fresh 0700 directory under `pikkumies/local/tmp/` — never `/tmp`, which sits outside the account's quota — removed afterwards whether the run succeeded or failed.
3. Tries the cloud endpoint, unless `--local` was given or no login is stored. A converted file over 24 MiB is first cut into ~150 second pieces (`ffmpeg -f segment -segment_time 150`) and each piece's text joined, because most OpenAI-compatible endpoints cap a single upload well under that.
4. Falls back to the local engine on any cloud failure (a network error, any non-2xx answer): the reason goes to stderr, with the auth-specific wording "the stored key was refused; run login again" for a 401/403.
5. With neither a cloud login nor the local engine installed, the error names both ways to fix that (`login`, `install-local`) and exits 1.

The output is special: the plain transcribed text on stdout (not a JSON record), because that is the one thing almost every caller wants; `--json` gives the full record instead. Every other verb here still prints JSON Lines, like the rest of the tools.

## `install-local`

Builds whisper.cpp v1.9.5 from its pinned GitHub release source tarball and downloads one pinned ggml model (`base` by default, `small` with `--model small`) from the whisper.cpp model repository on Hugging Face. Every pin (source tarball, the Kitware cmake release used only when no system `cmake` 3.x or newer is on PATH, and both models) is a URL and a sha256 in `TranscribePins.php`; every download is hashed before it is used, and a mismatch deletes it and stops — nothing unverified is ever extracted or run. The build itself runs through `cmake` with `-j` capped at `min(4, max(1, nproc/2))`, never more than half the account's own share. It preflights free quota (source, build and model, with margin), free memory (base needs about 1 GiB, small about 2 GiB) and a C/C++ toolchain, and is idempotent: a step whose result already exists is skipped on a later call. It never runs on its own; it is the one verb in this tool that builds anything.

### Pins (sourced and recorded in `docs/adr/0045-media-tools-ffmpeg-and-transcription.md`)

| what | source |
|---|---|
| whisper.cpp v1.9.5 source | GitHub release tag's generated source tarball |
| cmake (Linux x86_64 only) | Kitware's own CMake GitHub release, cross-checked against its published `SHA-256.txt` |
| ggml-base.bin, ggml-small.bin | the whisper.cpp model repository on Hugging Face, cross-checked against its `X-Linked-ETag` |

## Tests

`tests/MediaToolsTranscribeTest.php` against fake `ffmpeg`, `whisper-cli`, `nproc` and `curl` scripts on a temporary PATH, and a fake OpenAI-compatible endpoint (`php -S`) that records the multipart request and can answer 200, 401 or 500 (`tests/fixtures/mediatools/`). `install-local`'s pins are injected as a local fixture URL in tests; real downloads never happen there.
