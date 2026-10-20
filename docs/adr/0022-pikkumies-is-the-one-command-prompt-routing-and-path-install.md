# ADR-0022: `pikkumies` is the one command: prompt routing and PATH install

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-15
- Status: Accepted

## Context
The request of 2026-10-15: make sure the launcher is just `pikkumies`, any number of them can run at once in one account, and install puts it on `PATH` automatically, so people can type `pikkumies` or `pikkumies check my stats and can we increase performance` and it starts that prompt.

Before this ADR, `bin/pikkumies` only had its own subcommands (`install | update | detect | doctor | generate | run [hermes args]`); there was no way to start a session with an opening line without typing `pikkumies run chat -q "..."`, and `install` never touched the shell's own `PATH` — only `~/pikkumies` (a symlink to the runtime) and the daily update cron line ([ADR-0013](0013-daily-auto-update-from-the-user-crontab.md)). The user had to know Hermes' own flags and either call `~/pikkumies/bin/pikkumies` by its full path or add it to `PATH` by hand.

Two investigations fed the decision:
- **Hermes' own `-q`.** Built from the pinned release's source (`hermes_cli/_parser.py`, `_build_chat_parser`): "`-q`/`--query` ... On a real TTY the prompt seeds an interactive session (submitted literally as the first turn); combined with `--oneshot` or `-Q`, or on a non-TTY, it answers and exits." So `hermes chat -q "<text>"`, run through `passthru()` (which never redirects the pikkumies process's own stdio), already does exactly what was asked — interactive when someone is at a keyboard, one-shot when piped — with no TTY-sniffing of our own.
- **What state a second instance would share.** `HERMES_HOME` is one shared directory per account on purpose ([ADR-0003](0003-hermes-integration.md)); Hermes' `state.db` is SQLite in WAL mode with its own cross-process advisory locking (`hermes_state_dbfile.py`) and a cross-process active-session registry (`hermes_cli/active_sessions.py`, `MAX_CONCURRENT_SESSIONS`) built to let several sessions write at once — a different session per invocation (no `--resume`/`-c`) never collides there; that is Hermes' job, not ours. What pikkumies itself regenerates on every `run` — the skills tree and `tools/INDEX.md` (`Generate::run`) — already serializes under a `flock()` and writes every file through a temp name + `rename()`; `HermesConfig::write` (`config.yaml`, `.env`, `SOUL.md`) writes the same way and is deterministic from the same inputs, so two instances racing it converge on the same content. `profile.json` was the one plain `file_put_contents()` in the whole path (non-atomic, though nothing reads it back); it is now written the same temp-then-rename way as everything else, for the same reason.

## Options Considered
- **A `--prompt`/`-q` flag on `pikkumies` itself.** Explicit, but defeats the ask ("just type `pikkumies <text>`"), and duplicates a flag Hermes already has.
- **Always treat `argv[1..]` as a prompt, subcommands only via a flag.** Clean split, but breaks `pikkumies install`/`doctor`/… — the existing commands have to "keep working" unflagged.
- **Known subcommand names win; free text otherwise; `--` forces a prompt.** Rejected as written in review: `pikkumies update my plex` would start a self-update and `pikkumies install radarr please` would re-run the installer, from an ordinary request.
- **A Pikkumies subcommand wins only when every word after it is a flag; `run` keeps its documented passthrough; free text otherwise; `--` forces a prompt.** Chosen: every existing form (`pikkumies doctor`, `pikkumies detect --refresh`, `pikkumies run sessions list`) works exactly as before, and an ordinary request that happens to start with one of those words stays a request.

For PATH:
- **Only ever a symlink in `~/.local/bin`.** Simplest, but on a bare account where neither `~/bin` nor `~/.local/bin` is already wired into `PATH` (Debian's `/etc/skel/.profile` adds both, but only `if [ -d ... ]`, i.e. only once the directory already exists — which it does once we create it, but only from the *next* login), the symlink alone does nothing until a relogin, with no explanation.
- **Always edit `~/.bashrc`/`~/.profile`, never just rely on the directory.** Works everywhere, but needlessly edits dotfiles on the common case where `~/bin` or `~/.local/bin` is already on `PATH` (so a plain symlink is enough and is the less invasive change).
- **Prefer the symlink when the directory is already on PATH, else a marked PATH block.** Chosen, matching the request's own wording.

## Decision
- **Routing (`pikkumies/lib/Launch.php`, `Launch::route($argv)`).** `[cmd, passArgs]`:
  - no `argv[1]`, or `argv[1]` is `help`/`--help`/`-h` → `help` (usage) or plain `run` with no args;
  - `argv[1]` is `run` → the Hermes passthrough, all words kept (`pikkumies run sessions list` still means `hermes sessions list`; `pikkumies run update` is still refused, [ADR-0009](0009-hermes-from-a-pinned-release-tarball.md));
  - `argv[1]` is one of `install | update | post-update | detect | doctor | generate` and every later word starts with `-` → that subcommand with those flags; if any later word is not a flag, the whole command line is a prompt;
  - `argv[1]` is `--` → the rest (if non-blank once joined) becomes the prompt, even if it starts with a subcommand name;
  - anything else → `argv[1..]` joined with single spaces is the prompt.
  - A prompt becomes `run` with `passArgs = ['chat', '--query=' . $text]` (one token: a prompt that starts with "-v" or "-m" is never read as one of Hermes chat's own flags); `bin/pikkumies` already turns `passArgs` into `escapeshellarg()`-wrapped words for `passthru()`, so the free text is one shell argument, never evaluated, never re-split — safe with spaces, quotes, `$(...)`, or a literal `--`.
- **PATH (`pikkumies/lib/PathInstall.php`, called from `install` only).** `PathInstall::binDir()` picks the first of `~/.local/bin`, `~/bin` already present in the inherited `PATH` (the order Debian's skeleton ends up preferring `.local/bin`, since its `.profile` prepends it last); with neither on `PATH`, `~/.local/bin` is the default. `PathInstall::ensureLink()` symlinks `pikkumies` there to `~/pikkumies/bin/pikkumies` (the existing stable link, [ADR-0001](0001-layout-install-and-update.md)) — idempotent, retargets a stale link, never overwrites a real file. When the chosen directory was *not* already on `PATH`, `PathInstall::ensureFile()` adds one `# >>> pikkumies >>>` / `# <<< pikkumies <<<` block to `~/.bashrc` and `~/.profile` (both, so a shell that sources only one still gets it) that exports `PATH` for that directory — same byte-preserving, marker-replace contract as `Update::cronMerge` (ADR-0013), so a re-run or a reinstall never duplicates it and every other line survives untouched. There is no `pikkumies uninstall`; removing the block by hand is the same manual step ADR-0013 already documents for the cron line.
- **Concurrency.** No new per-instance state was introduced, because none was needed: `HERMES_HOME` stays the one shared directory (ADR-0003) and Hermes' own session database and active-session registry already allow several writers; `Generate::run`'s `flock()` plus temp-file-then-`rename()` on every file it and `HermesConfig::write` touch means two `pikkumies run`s regenerating config at once never read a half-written file; `profile.json` now writes the same way. The one *new* piece of genuinely shared, foreign-content-mixed state this ADR adds — the `~/.bashrc`/`~/.profile` PATH block — gets its own `flock()` in `PathInstall::ensureFile()` around the read-merge-write-rename, because unlike the generated files it is not deterministic-content-converges-anyway: it shares the file with whatever else the user put there.

## Consequences
- `pikkumies` (no args) starts a plain interactive session; `pikkumies <anything not one of the seven subcommand names>` starts one with that as the opening line; `pikkumies -- <text>` forces a prompt; a subcommand followed by ordinary words is a prompt, so `pikkumies update my plex` asks about Plex; the subcommands themselves are unchanged.
- A fresh `pikkumies install` leaves the command on `PATH` without the person editing anything by hand; on an account where `~/.local/bin` or `~/bin` was already wired in (the common Debian case once either exists), only a symlink is added — no dotfile is touched.
- Non-TTY callers (scripts, CI) using the free-text form automatically get Hermes' one-shot answer-and-exit instead of a seeded interactive session — that split is entirely Hermes' own TTY check, not something pikkumies decides or needs to test against a particular Hermes build.
- Tests: `tests/LaunchTest.php` (routing), `tests/PathInstallTest.php` (PATH logic, unit-level), `tests/ConcurrencyTest.php` (two real OS processes racing `Generate::run` and `PathInstall::ensureFile` against the same files).

## References
- ADR-0001 (layout, install and update: the `~/pikkumies` symlink), ADR-0003 (`HERMES_HOME`), ADR-0009 (Hermes is pinned; `pikkumies run update` is refused), ADR-0013 (the crontab line this PATH block's merge logic mirrors), [ADR-0015](0015-tool-units-one-skill-per-tool.md) (tool units / `Generate::run`'s lock)
- Hermes Agent `v2026.9.24` source (the pinned release, verified by its sha256 in `local/config.php`'s default): `hermes_cli/_parser.py` (`-q`/`--query` TTY behaviour), `hermes_state_dbfile.py` and `hermes_cli/active_sessions.py` (cross-process session safety)

## Amendment 2026-10-18
[ADR-0020](0020-deletes-need-the-users-approval.md) (amendment 2026-10-18): started from inside an agent (`PIKKUMIES_DEPTH` 1 or more), `pikkumies run` refuses the Hermes flags and commands that turn approvals, hooks or rules off or pick another config; for the user it stays the full passthrough.

[ADR-0059](0059-settings-tiers-free-asked-and-the-persons-own.md) (2026-10-18): `pikkumies configure undo`, exactly those words, is a subcommand (the person's own: it shows the last settings change and asks before setting it back, without a session); `pikkumies configure` followed by any other words stays a prompt.

## Amendment 2026-10-19: Eternal resident invocation
`pikkumies run --eternal pmN` is the resident process of an Eternal tmux window. It takes the same launcher gates and session path as a normal `pikkumies --resume <id>` start. See [ADR-0075](0075-eternals-primary-ask-and-ui.md).
