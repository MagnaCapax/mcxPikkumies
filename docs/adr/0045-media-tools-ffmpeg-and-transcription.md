# ADR-0045: `media` category — ffmpeg and transcription, cloud first with a local whisper.cpp fallback

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted

## Context
The request of 2026-10-18, verbatim except for the bracketed paraphrases: "[design] that tooling to pikkumies too, with option to use [a hosted transcription API] (if keys stored) and then fallback to the local CPU model for voice transcription. not direct copy, make sure no leaks from our system, separateley built and done." Followed by: "hmm tools/media .... we do not have ffmpeg, or rffmpeg tooling !! ... [design] the toolöing and [plan and] build that too."

Why a separate tool (this ADR's reasoning, not the operator's words, and not re-checked against Hermes source): Hermes' own transcription, where it has one, serves the agent's own voice input, not a general file transcription the user can point at any of their media files.

Facts checked this session:
- ffmpeg ships as a standard installed package on the supported PMSS Debian releases (scripts/lib/update/dpkg/selections-debian11.txt:97, selections-debian12.txt:110, both "install"), so a tool that expects ffmpeg on PATH is a safe default on PMSS; elsewhere it says "not installed" with a hint, the same as every other unit (pikkumies/lib/ToolProc.php::need).
- There is no rffmpeg or remote-GPU tooling anywhere in this repository; hardware transcoding across machines is out of scope here (README.md, skill.md).
- A long-running foreground process on a shared host can be stopped by the provider after about a day; convert and install-local cap themselves well under that (--max-hours, default 6, at most 20; the build's own time cap, 2 h).
- Proxy-style and some self-hosted OpenAI-compatible endpoints refuse audio/multipart bodies or cap upload size well under typical provider limits; run therefore treats any non-2xx or network failure as "try the next thing" (local fallback), not a hard stop, and splits a large upload into about 150 second pieces first.

## Options Considered
- Extend Hermes itself with a transcription tool. Rejected: Hermes is kept vanilla ([ADR-0033](0033-vanilla-hermes-git-checkout-updated-separately.md)); its own gateway-shaped transcription is not this tool's job, and a Pikkumies unit is provider-agnostic by construction (AGENTS.md) in a way a Hermes-side change would not be.
- Copy an existing transcription wrapper from elsewhere wholesale. Rejected by the operator's own words ("not direct copy ... separateley built"): this tool is built from the ground up against this repo's own conventions ([ADR-0015](0015-tool-units-one-skill-per-tool.md), [ADR-0019](0019-hosts-and-credentials.md)), sharing only pikkumies/lib/ and pikkumies/lib/ToolProc.php's pattern, never another project's code.
- Cloud-only transcription. Simpler, but leaves the user stranded with no key stored or a dead endpoint; rejected per the operator's explicit fallback request.
- Local-only (whisper.cpp always). No cloud dependency, but slower and needs a build step on every account that wants it; rejected per the operator's explicit "option to use ... API ... and then fallback" ordering (cloud first).
- Cloud first, local whisper.cpp fallback, built and verified by this account only when asked (chosen).

## Decision
- Layout. pikkumies/tools/media/ (ADR-0015 tool-unit layout), two units (ffmpeg, transcribe) sharing no verbs, so the category has no verb table (tools/storage/README.md's own reasoning); tools/media/lib/Ffmpeg.php holds what both units need (finding ffmpeg/ffprobe, probing, the fixed conversion presets), the same "tools/<category>/lib/ once two units of a category share real code" rule the storage/backup units follow.
- Shared helpers promoted to pikkumies/lib/. ToolProc.php and HomePath.php moved out of tools/storage/lib/ once a third category (media) needed them, per tools/storage/README.md's own stated plan; ToolText.php and ToolList.php stayed in tools/storage/lib/ (storage and backup use both, media uses ToolText; ToolProc itself does not need them). Every require_once pointing at the old paths was updated (storage.php, rclone.php, tools/backup/lib/Backup.php, tools/system/performance/PerfProbe.php, tools/containers/docker/DockerCli.php, and the two edge-case tests); no behaviour changed, confirmed by the full suite before and after.
- ffmpeg unit. probe, hw, convert, jobs (USAGE.md). Paths are always realpath'd absolute first (HomePath), so a name starting with "-" is never read as an option; every subprocess is an argv through ToolProc (-nostdin -hide_banner -protocol_whitelist file,pipe,crypto,data); convert checks free quota against the input size (Quota::fields) and never overwrites without --force, never the same file as both input and output; hw reports the GPU render device's existence and this account's own group membership against it, naming the exact gap rather than guessing a group name.
- transcribe unit. login, run, status, install-local (USAGE.md). login prompts for the endpoint, model and key at a terminal (https required unless it is this account's own local listener -- a textual loopback check only; pikkumies/lib/Http.php still refuses a foreign-owned local port at call time); all three are stored as one SecretFile JSON so a redirected key cannot be used against a different endpoint. run converts the input to the speech-16k preset (16 kHz mono WAV, about 1.9 MB per minute) in a fresh directory under pikkumies/local/tmp/ (never /tmp), tries the cloud endpoint (unless --local or no login), splits anything over 24 MiB into about 150 second pieces first, and falls back to the local engine on any cloud failure (the auth-specific wording "the stored key was refused; run login again" for 401/403); the temp directory is removed, success or failure, and one left by a killed run is removed by the next run once it is 6 hours old. install-local builds whisper.cpp v1.9.5 from its pinned source, downloads one pinned ggml model, verifies every download's sha256 before use (a mismatch deletes it and stops), preflights free quota/memory/toolchain, and is idempotent; it is never run on its own.
- pikkumies/lib/Http.php gained fields (array name => string|CURLFile; curl builds the multipart body), exclusive with body, with every existing guarantee (no redirects, TLS verify, size cap, local-listener check) unchanged.
- pikkumies/lib/SecretFile.php gained a shared prompt() (the terminal-read-without-echo helper AutobrrSecret::prompt() had of its own); AutobrrSecret now calls it, transcribe login uses it directly. QbitSecret and MediaSecret (media-servers) keep their own copies for now -- out of this change's scope.
- Provider-agnostic. No LLM vendor or model id is named anywhere in tools/media/; "OpenAI-compatible" (an API shape, not a vendor) is the one sanctioned phrase, added to tests/ArchitectureTest.php's allowlist for the three files that say it (TranscribeSecret.php, TranscribeCloud.php, transcribe.php).

## Consequences
- Every category now has its subprocess runner and path-confinement helper in one place (pikkumies/lib/), so a fourth category needs no more file-moving.
- A user who wants transcription with no cloud key pays a one-time build (whisper.cpp plus a model, install-local), which can be large (the small model alone is about 465 MiB) and is never started without the user asking.
- A large file costs one more round trip (the segment split) before the first byte reaches the cloud endpoint; local transcription has no such split (whisper.cpp reads the whole file itself).
- GPU transcoding across machines is explicitly not offered; ffmpeg hw only ever reports the state of the machine it runs on.
- Hermes' own transcription path (gateway-only, per the operator's framing above) is untouched; this tool neither wraps nor depends on it.

## Pins (TranscribePins.php) -- obtained by downloading each file over HTTPS and hashing it, 2026-10-18
| asset | source | sha256 | cross-check |
|---|---|---|---|
| whisper.cpp v1.9.5 source | GitHub release tag v1.9.5's generated source tarball, ggml-org/whisper.cpp | ff1a9053feb509ff9d7729703355541ae9690073a6b1c40eb692c962e0dc1720 | single source only: GitHub's codeload tarballs for a given tag carry no separately published checksum |
| CMake 4.4.4, Linux x86_64 | Kitware's CMake GitHub release v4.4.4, the linux-x86_64 tarball asset | e5bb807f7728cb60cd8b27ebc97a2edb469b68655f21e844a600c3575b76f5bb | matched byte-for-byte against Kitware's own published cmake-4.4.4-SHA-256.txt |
| ggml-base.bin | the whisper.cpp model repository on Hugging Face (ggerganov/whisper.cpp), at repository commit 5359861c739e955e79d9a303bcbc70fb988958b1 | 60ed5bc3dd14eea856493d334349b405782ddcaf0028d4b5df4088345fba2efe | matched Hugging Face's X-Linked-ETag response header for the same file |
| ggml-small.bin | the whisper.cpp model repository on Hugging Face (ggerganov/whisper.cpp), at repository commit 5359861c739e955e79d9a303bcbc70fb988958b1 | 1be3a9b2063867b937e64e2ec7483364a79917e157fa98c5d94b5c1fffea987b | matched Hugging Face's X-Linked-ETag response header for the same file |

## Security notes
- Endpoint+key binding. The three login fields are written and read as one JSON object; there is no way to change the endpoint without re-typing the key, so a later edit of just the endpoint (were one possible) can never silently send an old key to a new place.
- Temp files stay inside the account's own quota. pikkumies/local/tmp/transcribe-<random>/, never /tmp; removed in a finally block on success or failure. A run killed by a signal (Ctrl-C, SIGTERM) skips that block and leaves its directory behind; the next run removes this tool's own directories there (name prefix transcribe-) once they are 6 hours old, so the converted audio stays at most that long, inside the account's own quota.
- Pinned downloads only. install-local fetches exactly the four assets above (or a test's own fixture pins); every download is hashed before use, and a mismatch deletes it and stops rather than extracting or running anything unverified.
- No argv-array proc_open on PHP 7.3. Every subprocess argv is built as a PHP array and escaped word by word by ToolProc::line() before being handed to proc_open's single string form, the same pattern every other category already uses.

## References
- ADR-0015 (tool units), ADR-0019 (hosts and credentials, the SecretFile/login pattern, Http's local-listener check), ADR-0033 (vanilla Hermes), [ADR-0044](0044-every-shell-command-is-logged.md) (command logging -- it logs the shell commands the agent itself runs (its terminal tool calls), so the agent's call of a media verb is logged, but not the ffmpeg/curl/cmake/whisper-cli subprocesses the verb starts; no new logging added here)
- tools/storage/README.md (the lib/ promotion plan this ADR fulfils), tools/arr/autobrr/README.md (the login/secret pattern transcribe login follows)
- whisper.cpp ggml-org/whisper.cpp at tag v1.9.5 (examples/cli/CMakeLists.txt names the target whisper-cli; root CMakeLists.txt requires cmake >= 3.5 and sets CMAKE_RUNTIME_OUTPUT_DIRECTORY to <build>/bin)
- PMSS scripts/lib/update/dpkg/selections-debian11.txt, selections-debian12.txt (ffmpeg ships by default)
