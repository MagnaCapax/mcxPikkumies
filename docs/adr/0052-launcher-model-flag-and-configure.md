# ADR-0052: A model for one session from the launcher, and `pikkumies configure`

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-18
- Status: Accepted. Amends [ADR-0022](0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md) (a leading `-m`/`--model`/`--reasoning` pair is a flag, not prompt text), [ADR-0038](0038-plain-search-command-config-overlay-live-source-check.md) (launcher flags are allowed for per-run Hermes chat flags only) and [ADR-0032](0032-settings-configure-skill-first-run-and-install-flags.md) (routing of `configure`).

## Context
The requests of 2026-10-18: "pikkumies needs to take in --arg for the model too, and probably other arguments too." and "further pikkumies configure should be prompts tools etc and the agent should configure itself as far as possible, as an agent".

What was found:
- `pikkumies run chat -m <model> --query=...` already picks a model for one session, and it is a session: `Launch::isSession` is true for `chat`, so the crontab restore, the missing-key refusal, the first-start questions, the credits line and the halt guard all apply. It is long to type.
- Hermes chat takes `-m`/`--model`, `--reasoning <level>` ("for this run only") and `-s`/`--skills` (`hermes_cli/_parser.py`, `_build_chat_parser`). An explicit `-m` wins over the configured model (`hermes_cli/cli_init_mixin.py`: `self.model = model or _config_model`).
- The credits line at start showed the token floor of the configured model, also when another one was used.
- The `pikkumies-configure` unit already computes the settings review (`review`); the first start already shows the settings (`Launch::settingsNote`).

## Options Considered
- **Force the model through the `PIKKUMIES_CONFIG` overlay for the run.** The overlay is merged by `Config::load`, and `run` writes the generated Hermes config from it, so the model would stay for every later start, scheduled job and parallel session until the next start without it (ADR-0038 addendum). Rejected: not one run.
- **Pass any Hermes chat flag (`--provider`, `-c`, `-t`, `--yolo`, …).** `--provider` can route traffic away from the configured provider, whose choice belongs to the deployer (AGENTS.md); `-c` takes an optional value, so `pikkumies -c fix my plex` would read "fix" as a session name; `--yolo`, `--safe-mode`, `--ignore-rules`, `--ignore-user-config` and `--accept-hooks` switch off approvals, hooks or rules. Rejected.
- **Only document `pikkumies run chat -m`.** Does less than was asked ("needs to take in --arg"). Rejected.
- **Check the model against the provider's model list at start.** The server already refuses a model it does not serve, a request costs seconds at every start, and [ADR-0050](0050-credits-usage-models-and-low-balance-notice.md) rejected fixed model lists. Rejected.
- **Start an agent review automatically at the first start, or `configure` without a terminal as a one-shot session.** Spends credits nobody asked for; without a terminal the halt guard may resume it several times ([ADR-0047](0047-halt-guard.md)). The review is fixed text the unit already prints. Rejected.
- **Leading `-m`/`--model`/`--reasoning` pairs turned into the arguments `run chat` already takes; `configure` a session with the configure skill on a terminal, the printed review otherwise.** Chosen.

## Decision
- **Launcher flags (`lib/RunFlags.php`, used by `Launch::route`).** Only at the start of the command line: `-m X`, `--model X`, `--model=X`, `--reasoning L`, `--reasoning=L`, any number of them. Each needs a value; an empty one, or one starting with `-`, stops with one line and exit 2, and nothing starts. A repeated flag keeps the last value. After them: nothing gives `run` with `['chat', '-m', X, '--reasoning', L]` (each pair only when given), an interactive session; words give the same plus `--query=<words>`; a `--` right after the flags makes the rest the prompt. A subcommand name after the flags is prompt text, never a subcommand. Any other leading word is prompt text as before (`pikkumies -v hello` is the prompt "-v hello"), and `pikkumies -- -m X` is still the prompt "-m X".
- **Never passed by the launcher:** `--provider`, `-c`/`--continue`, `-t`, and every flag that turns off approvals, hooks or rules (`--yolo`, `--safe-mode`, `--ignore-rules`, `--ignore-user-config`, `--accept-hooks`). `pikkumies run chat …` stays the full passthrough. Nothing is written to any config: the model is for this session only.
- **Credits line.** `bin/pikkumies` gives `CreditsLine` the `-m` model of a `chat` run (`RunFlags::model`), so the token floor shown is for the model used.
- **`pikkumies configure`** is a subcommand under ADR-0022's rule: alone (or with flags only) it is `configure`, `pikkumies configure my plex` is a prompt. On a terminal (stdin and stdout) it starts `chat -s pikkumies-configure --query="Review my setup and help me configure Pikkumies"` through the same path as `run`, with all its checks. Without a terminal it prints `pikkumies-configure review --human` and never starts Hermes.
- **First start.** After the terms are accepted, the review's warnings (if any) and one line: "Run `pikkumies configure` or ask me to review your setup." No session is started for it.
- **ADR-0038's rejected option** ("flags would collide with prompts, and hooks and tools run without them") is amended only for these per-run chat flags: they change one Hermes session and are not settings, so hooks and tools reading settings are not affected. Forcing settings stays the overlay's job.

## Consequences
- `pikkumies -m <model> why is my disk full` works; a prompt that starts with `-m` needs `--` first, a change to ADR-0022.
- A one-word prompt "configure" now opens the configure session; `pikkumies -- configure` keeps it a prompt.
- What the agent may change through `configure` is unchanged (ADR-0032); more settable keys need their own ADR.
- Without a terminal, `pikkumies -m X` with no words goes the same way as `pikkumies run chat -m X` does today.
- Tests: `tests/LaunchRoutingTest.php` (flags, errors, `--`, last wins, subcommand words, `-v`), `tests/LaunchTest.php` (configure routing, no-terminal review, first-start line, banner model).

## References
- ADR-0022 (launcher routing), ADR-0032 (settings and the configure skill), ADR-0038 (overlay), [ADR-0040](0040-credits-line.md) (credits line), ADR-0047 (halt guard), ADR-0050 (model list, credits per model)
- Hermes Agent v2026.9.24: `hermes_cli/_parser.py` (`_build_chat_parser`: `-m`, `--reasoning`, `-s`; `-q` TTY behaviour), `hermes_cli/cli_init_mixin.py` (`self.model = model or _config_model`)

## Amendment 2026-10-18
[ADR-0020](0020-deletes-need-the-users-approval.md) (amendment 2026-10-18): `pikkumies run chat …` stays the full passthrough for the user; started from inside an agent (`PIKKUMIES_DEPTH` 1 or more) it refuses the flags listed under "Never passed by the launcher", and `-z`, a top-level `--oneshot`, `--profile`/`-p`, `profile use` and `config set approvals.…`.

## Amendment 2026-10-18 ([ADR-0059](0059-settings-tiers-free-asked-and-the-persons-own.md))
`pikkumies configure undo`, exactly those words, is the person's own shell command: it shows the last configure change and, on a terminal, sets it back after they answer y, without a session; `pikkumies configure <other words>` stays a prompt. "What the agent may change through `configure` is unchanged" no longer holds: ADR-0059 gives every setting a tier.

## Amendment 2026-10-19: a prompt from a file or stdin, and `--resume`
The request of 2026-10-19: "pikkumies needs also file input mode for prompt ... otherwise might not fit the full prompt ... and bring other commonly needed --args too".

- **Why.** Linux limits one command-line argument string to 128 KiB (MAX_ARG_STRLEN; ARG_MAX does not help). The prompt becomes one `--query=<text>` word, and `bin/pikkumies` starts Hermes with `passthru()` or, without a terminal, `proc_open()` given one command string, which runs as `/bin/sh -c "<whole command>"`: the whole Hermes command line is one argument. Measured: a 140 000-byte prompt fails to start, 120 000 bytes starts. The failure was nearly silent: `passthru` printed a PHP warning holding the whole command and exited 255; the one-shot path (`HaltRun`) exited 1 with nothing on PHP 8.3+, where `proc_open` uses posix_spawn, and 127 with nothing on older PHP, where the forked child cannot exec `sh`.
- **`--prompt-file <file>`, also spelled `--query-file`.** One more launcher flag under the rules above (only at the start, a value required, the last one wins, every flag has an "=" form). It becomes Hermes chat's own `--query-file=<path>`, so Hermes reads the prompt itself (`hermes_cli/main.py` `_read_query_file`, Hermes v0.22.2) and nothing is duplicated here. `-` is stdin, the one value starting with "-" that is accepted. The path is made absolute (relative to where `pikkumies` started; an absolute path is kept as given, since a `/dev/fd/N` path from `<(...)` has no realpath), because Hermes changes directory for `--in` and for a resumed session's recorded directory before it reads the file. A file that does not exist, or words after the flag, stop with one line and exit 2 (Hermes' `-q` and `--query-file` are mutually exclusive). This is not ADR-0022's rejected `--prompt`/`-q` flag: free text keeps working unchanged, and this maps to Hermes' own reader for what free text cannot carry.
- **`--resume <session id>`.** Hermes chat's `--resume`, for this run; `pikkumies run chat --resume` stays the full passthrough. `-c`/`--continue` stays excluded (its value is optional, see above); `--resume latest` is Hermes' own way to continue the newest session.
- **Order** of what the launcher passes: `-m`, `--reasoning`, `--resume`, `--query-file`.
- **When Hermes cannot be started at all,** both paths print one line to stderr: "Could not start Hermes. If the prompt is long (over about 128 KB), it does not fit on a command line: put it in a file ..." (`HaltRun::START_FAILED`). It names the likely way out, not a proven cause: a process or memory limit fails the same way. The passthru path exits 1 (was 255) and no longer prints PHP's warning; the one-shot path keeps its exit code.
- **Still never passed:** `--provider`, `-c`, `-t` and the flags that switch off safety layers. Prompts that start with the literal words `--prompt-file`, `--query-file` or `--resume` change meaning, as `-m` did; `pikkumies -- …` keeps them text.
- **Not done:** reading stdin by itself when no words are given (breaks `</dev/null` callers, and implicit), spilling a long prompt to a temp file (secrets on disk, and a single word over 128 KiB already fails in the caller's shell), `proc_open` with an argv array (PHP 7.4+, still 128 KiB per word), more passthrough flags (`--format`, `--max-turns`, `--run-budget` work through `pikkumies run chat`).
- Tests: `tests/LaunchPromptFileTest.php` (forms, absolute path, order, last wins, errors with exit 2, prompts that stay prompts), `tests/HaltRunQueryFileTest.php` (the file and stdin reach Hermes, a resume never repeats the file, an empty stdin stops with exit 2, the could-not-start line for a missing Hermes and for a 140 000-byte prompt word, the passthru branch), `tests/ContractTest.php` (Hermes chat still defines `--query-file`, where Hermes is installed).
