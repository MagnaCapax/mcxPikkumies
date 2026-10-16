# ADR-0032: User settings, the pikkumies-configure skill, first start and install flags

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-16
- Status: Accepted

## Context
The operator's requests of 2026-10-16:
- "we need essentially tooling + skills for pikkumies itself management, a pikkumiesConfigure skill to make config changes and review configs. We should have things like is performance logging allowed, session transcript retention period (default infinite), sending performance telemetry allowed (default on), and TOS/PRivacy policy accepted, name of their pikkumies agent. Upon first launch we should ask to name their pikkumies agent."
- "the install should ahve --args for everything so on PMSS no install questiionaires for names etc. all streamlined install, but someone one installing on any other server would get tehe questions etc."
- "we should probably have the default pikkumies launch ... just launch a interactive shell, but also have pm1-9 tmux groups?"

Facts this rests on, checked 2026-10-16 in the pinned Hermes v2026.9.24 (`hermes_cli/config_defaults.py`):
- `sessions.auto_prune` (default `True`, :2236) prunes ended sessions inactive for `sessions.retention_days` (default 90, :2245) at start; the comment there notes `state.db` "grows without bound (multi-GB installs reported within weeks)" without it.
- `telemetry.shared_metrics.enabled` and `.send` both default `False` (:2297-2306).

No telemetry sender or performance logger exists in Pikkumies.

## Options Considered
- **Let the configure skill write any config key.** One tool for everything, but then the agent can turn off its own hooks, search-first, the root switch or the provider, and accept the terms for the person (vjoukahainen C1 on the plan). Rejected.
- **Keep the settings in a separate file (local/settings.php).** Leaves hand comments in `local/config.php` alone, but splits the config in two places. The plan says local/config.php, and this ADR keeps it there.
- **Ask the questions on every install, flags optional.** Hangs or breaks a PMSS install, which runs without a terminal. Rejected: prompts only on a terminal, and flags answer each one.
- **Implement telemetry now.** There is nothing to send it to and no review of what it would contain. Rejected: the settings exist, the sender does not.

## Decision
- **Settings (`Config::defaults()`):** `agent.name` `''` (not named yet; the agent is "Pikkumies"), `privacy.perfLogging` `true`, `privacy.telemetry` `true` (the operator's default), `sessions.retentionDays` `0` (keep forever, the operator's default), `terms.accepted` `false`.
- **`Config::set($key, $value)`** writes only the keys of `Config::SETTABLE` (`agent.name`, `privacy.perfLogging`, `privacy.telemetry`, `sessions.retentionDays`), each type-checked (a name is 1-32 letters, digits, spaces, dots, dashes or underscores, starting with a letter or digit; days 0-36500). It rewrites `local/config.php` from its values under a lock, through a temporary file and a rename, mode 0600; comments in that file are not kept. `Config::parse()` turns on/off, yes/no, true/false, 1/0 and whole numbers into those types.
- **Terms** are set only by `Config::acceptTerms()`, called by the first interactive session's prompt and by `install --accept-terms`. `Config::set` refuses `terms.accepted`.
- **`tools/system/pikkumies-configure/`** (a tool unit, ADR-0015; lowercase name as Hermes requires): `show`, `get <key>`, `set <key> <value>`, `review`. `set` passes only `Config::SETTABLE` and refuses everything else with the way the person changes it: the terms by the first-start prompt or `--accept-terms`; hooks, searchFirst, root, provider, tools, skills, cron, autoUpdate, hermes and anything unknown by hand in `local/config.php`. `review` gives each setting's value, a one-line meaning, whether `set` may change it, and warnings (sessions kept forever, with the current size of `local/hermes/state.db`; no name yet; terms not accepted). This is a safety boundary for the agent, not security: the agent's user can edit `local/config.php` anyway (AGENTS.md).
- **Hermes config:** `HermesConfig::sessionsYaml()` is appended to `config.yaml`: `retentionDays` 0 → `sessions: auto_prune: false`; N > 0 → `auto_prune: true`, `retention_days: N`. `telemetry.shared_metrics.enabled` and `.send` are pinned `false`, so a later Hermes default cannot turn Hermes' own telemetry on (as ADR-0024 pins its features).
- **The agent's name in SOUL.md:** when `agent.name` is set and is not "Pikkumies", one line follows the soul text: "Your name is <name>; the user chose it. Pikkumies is the software you run as." An unset or invalid name (a hand edit with a newline, markdown or more than 32 characters) adds nothing, so SOUL.md stays exactly as before. `doctrine/soul.md` is not changed.
- **First start, only on a terminal** (`stream_isatty`/`posix_isatty` on STDIN, `Launch::isTty()`): `install` without `--name` and the first interactive `run` (plain, or `chat`) ask for the name; Enter keeps "Pikkumies", which is saved so it is not asked again. An interactive `run` with the terms not accepted shows the settings (telemetry, performance logging, retention), the links https://pulsedmedia.com/terms.php and https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy, and asks until yes or no; no, or the end of input, does not start the session. `cron-tick`, `docs-fill`, `run` without a terminal and management passthroughs such as `run sessions list` never ask and are never blocked.
- **Install flags:** `pikkumies install --name=<n> --accept-terms --telemetry=on|off --perf-logging=on|off --retention-days=<N>`. Values go through `Config::parse` and `Config::set`, the terms through `Config::acceptTerms`. A setting a flag gives is never asked. An unknown or invalid flag stops the install before anything changes (exit 2). The name takes the `--name=` form only: a separate word after a flag makes the whole line a prompt (ADR-0022).
- **Telemetry and performance logging are settings only.** Nothing is sent and nothing is logged until a sender or logger exists; that will need its own ADR (what is sent, where, how it honours this setting). AGENTS.md's privacy rule says so.
- **Launcher (the third request):** plain `pikkumies` already starts an interactive session (ADR-0022). `pikkumies tmux [N]` (N 1-9, default 1) opens window `pmN` of the tmux session `pikkumies`, creating the session or the window (running `pikkumies`) when missing, then selects it and attaches (`switch-client` inside tmux). Windows are named, not numbered, so tmux's `base-index` and the user's other windows never matter. Without tmux it prints a message and exits 1. `pikkumies tmux` followed by other words stays a prompt. The installer adds no aliases; the README suggests `alias pm1='pikkumies tmux 1'`. (This decision rides here because it came in the same request; it changes only routing, ADR-0022.)

## Consequences
- A PMSS install with flags asks nothing and needs no terminal; an install elsewhere on a terminal asks the name, and the first session asks the terms.
- The agent can explain and change the four user settings on request, and cannot accept the terms or change its own safety settings through its tool.
- With the default retention (forever), `state.db` grows; `review` warns and shows its size, and one setting turns Hermes' pruning on.
- The default telemetry "on" promises nothing yet; when a sender is built, it must read `privacy.telemetry` first.
- A name in a non-Latin script uses up to 4 bytes per character; the SOUL.md budget test uses a 32-character ASCII name (ADR-0029).

## References
- ADR-0007 (local config), ADR-0010 (soul), ADR-0015 (tool units), ADR-0022 (launcher routing), ADR-0024 (pinned Hermes features), ADR-0029 (SOUL.md budgets)
- Tests: `tests/ConfigTest.php`, `tests/LaunchTest.php`, `tests/DoctrineTest.php`
- Hermes Agent v2026.9.24: `hermes_cli/config_defaults.py` ("sessions" :2231-2255, "telemetry" :2297-2306)
