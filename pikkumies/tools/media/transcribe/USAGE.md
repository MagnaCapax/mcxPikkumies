# transcribe

Speech to text for the user's own audio or video (ADR-0045). Shared rules: `tools/media/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| run | `<file> [--language xx] [--json] [--local]` | yes (a cloud call, or a local build's compute) | Converts `<file>` to 16 kHz mono WAV (about 1.9 MB per minute) in a temporary directory inside the account's quota, removed afterwards. Tries the cloud endpoint (unless `--local`, or no login is stored); a file over 24 MiB is split into ~150 s pieces first and the texts joined. Any cloud failure falls back to the local engine, saying why on stderr. Prints the text on stdout; `--json` prints `{provider: cloud\|local, seconds, language, chars, text}` instead — the one verb here that is not plain JSON Lines. |
| login | | yes | The user types, at a terminal: the endpoint (an OpenAI-compatible base URL; https required unless it is this account's own local listener), the model name (required, no default), and the API key (hidden). Stored as one secret, `pikkumies/local/secrets/transcription` (mode 0600): `stored`, `endpoint`, `model`. |
| status | | no | `cloud_login` (bool), `cloud_endpoint_host`, `cloud_model` (never the key), `local_installed` (bool), `local_model` (`base`, `small` or null), `local_model_bytes`, `ffmpeg` (bool). |
| install-local | `[--model base\|small]` | yes | Builds whisper.cpp from its pinned release source and downloads the pinned model (default `base`). Every download is verified by sha256 before use; a mismatch deletes it and stops. Idempotent: a finished step is skipped on a later call. Preflights free quota, free memory and a C/C++ toolchain. Returns `model`, `installed`, `steps_run`. Never run on its own. |

```sh
T=~/pikkumies/tools/media/transcribe/transcribe.php
php $T login                                 # the user runs this once, in a terminal
php $T install-local --model base             # only when the user asks; can take a long time
php $T status --human
php $T run ~/recordings/call.mp3 --language en
```

- The cloud endpoint, model and key are read from `local/secrets/transcription` on every `run` and never printed; `status` shows only the endpoint's host and the model.
- Every other verb's output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
