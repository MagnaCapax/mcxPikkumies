# Commands

| Command | What it does |
|---|---|
| `pikkumies install` | Detect the machine, generate the skills and the tool index, write config, install Hermes in the background, add the daily auto-update line to your crontab, put `pikkumies` on your `PATH` |
| `pikkumies update` | Fast-forward to the newest release, or to a branch when `update.channel` is `git/<branch>`, and print the channel (refuses to overwrite local edits, never moves backwards); never updates Hermes ([ADR-0057](adr/0057-release-channel.md)) |
| `pikkumies detect` | Print the machine profile (JSON) |
| `pikkumies doctor` | Show provider, masked key, the update channel and the release or commit installed, Hermes, tool and hook status, including your local hooks and why any is skipped |
| `pikkumies generate` | Regenerate the skills, `tools/INDEX.md` and the agent config (install, update and run do this too) |
| `pikkumies` | Start a plain interactive session (same as `pikkumies run` with no further arguments) |
| `pikkumies <free text>` | Start a session with that text as the first prompt — interactive on a real terminal, one answer and exit otherwise (Hermes' own `-q`, [ADR-0022](adr/0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)) |
| `pikkumies <tool> <verb> ...` (inside an agent) | Runs that Pikkumies tool, the same as `php <its script> <verb> ...`; in your own shell the words are a prompt ([ADR-0103](adr/0103-tools-by-name-and-support-job-steps.md)) |
| `pikkumies -- <free text>` | Same, forced even when the text starts with one of the names above (a name followed by ordinary words is already a prompt) |
| `pikkumies -m <model> [free text]` | A session with that model, for this session only; `--model <model>` and `--model=<model>` work too. `--reasoning <level>` sets the reasoning effort the same way. Only at the start of the line: the words after them are the prompt, even a name from above. `php ~/pikkumies/tools/system/credits/credits.php models` lists the models ([ADR-0052](adr/0052-launcher-model-flag-and-configure.md)) |
| `pikkumies --prompt-file FILE` | A session whose first prompt is the file's text; `--prompt-file -` reads it from stdin (`cat notes.txt | pikkumies --prompt-file -`), and `--query-file` is the same flag. Use it for a long prompt: one command-line argument holds at most 128 KB, and a longer prompt as words cannot start the agent. The file replaces the words, so give one or the other; it is read by Hermes as `chat --query-file`, which `--tui` ignores (ADR-0052) |
| `pikkumies --resume <session id> [free text]` | Continue that session, with the text as the next prompt; `pikkumies run sessions list` shows the ids. Combines with the flags above, for example `pikkumies --resume <id> --prompt-file -` |
| `pikkumies --no-progress [--stats] <question>` | Without a terminal (a pipe, a script, SSH) a question prints its answer on stdout and, on stderr, one line per step as it happens (`[12:01:03] ran df -h — checking disk usage`) with a short narration line from the light model (capped at 300 a day, `hooks.dailyCalls.narrate`; your own credits). `--no-progress` turns those lines off (they are off when an agent started Pikkumies); `--stats` prints wall time, model, model requests, tool calls, Hermes runs and tokens after the answer. Flags go first: after the question they are words of the question ([ADR-0109](adr/0109-one-shot-progress-and-stats.md)) |
| `pikkumies configure` | On a terminal: a session that reviews your settings and helps you change them. Without one: prints the settings review and starts nothing (ADR-0052) |
| `pikkumies configure undo` | Set the setting the last configure change replaced back to its old value, without a session, for example after a model that no longer starts. It shows `key old -> new (tier)`. A change the agent could make itself is made without a question; an asked change (one that loosens a setting, costs credits or deletes history) is made only after you answer y on a terminal, because the agent can write the change log too, and without a terminal it is refused. A model is checked against the endpoint's list as with any change; the provider, the terms and turning a safety layer off stay yours to change in `~/pikkumies/local/config.php` ([ADR-0059](adr/0059-settings-tiers-free-asked-and-the-persons-own.md)) |
| `pikkumies run [hermes args]` | Pass arguments straight through to Hermes (`pikkumies run sessions list`, `pikkumies run --tui`, …); started from inside an agent it refuses the flags and Hermes commands that turn approvals, hooks or rules off or pick another config ([ADR-0020](adr/0020-deletes-need-the-users-approval.md)) |
| `pikkumies run update` | Update Hermes with its own updater, separately from Pikkumies; refuses while another session or a dashboard runs (stop that with `pikkumies run dashboard --stop`) |
| `pikkumies run dashboard [hermes args]` | Hermes' web dashboard, run as Pikkumies. It starts only where Hermes asks for a login: set `HERMES_DASHBOARD_PUBLIC_URL` to the address you open it at, with `HERMES_DASHBOARD_BASIC_AUTH_USERNAME`, `_PASSWORD` and `_SECRET`; without them other accounts on a shared server could use it as you, so it refuses ([ADR-0074](adr/0074-the-dashboard-needs-a-login-and-its-own-hermes-backend.md)). The first start builds its web page with Hermes' own builder, which takes several minutes and needs no other Hermes session running; it says so before it starts. Hermes' question about sending usage metrics is already answered "No thanks" ([ADR-0072](adr/0072-hermes-shared-metrics-answer-recorded-as-no-thanks-in-the-users-file.md)) |
| `pikkumies browse up\|down\|status` | The isolated browser the agent uses for Hermes' browser tools: start it now, stop it now, or show whether it may run here and runs. It starts on the first browser call and stops after 15 idle minutes by itself ([ADR-0077](adr/0077-isolated-browser-in-a-private-network-namespace.md)) |
| `pikkumies websearch on\|off\|status` | Web search through DuckDuckGo (no key, no account). Off until you turn it on: before that nothing the agent searches for leaves the machine; once on, each search query goes to DuckDuckGo. The agent never turns it on; asked to search while it is off, it tells you this command ([ADR-0082](adr/0082-web-search-opt-in-keyless-ddgs.md)) |
| `pikkumies access status\|rotate\|remove` | The agent's own login to your panel, made by itself where the panel accepts it (a second line in `~/.lighttpd/.htpasswd`, never your password): show its state, make a new one, or take it out. Never prints the login ([ADR-0079](adr/0079-the-agents-own-panel-login.md)) |
| `pikkumies tmux [1-9]` | Open window `pmN` (default `pm1`) of the tmux session `pikkumies`, running `pikkumies`; the session and the window are created when missing. Without tmux it says so |

Any number of `pikkumies` instances can run at once in one account: they share `HERMES_HOME` on purpose (so sessions, memory and auth live in one place) and Hermes itself is built for several writers at a time; everything *pikkumies* regenerates on every run is written under a lock or through an atomic rename, so two instances starting together never race each other (ADR-0022).

To keep several sessions one keystroke away, add aliases to your `~/.bashrc` (the installer does not add them):

```sh
alias pm1='pikkumies tmux 1'
alias pm2='pikkumies tmux 2'   # ... up to pm9
```

`pm2` attaches to the `pikkumies` tmux session at window `pm2`, starting a new agent session there if that window is not open yet; detach with `Ctrl-b d` and the sessions keep running.

With a Pulsed Media AI key, `pikkumies` prints the credits line when a session starts, and the `pikkumies` tmux session shows it at the right of its status line, refreshed every minute: `💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≥1.1M tokens · today 35k`. The bar is what is left of the newest grant; the tokens are a floor from the model's rates, since the platform counts credits. Your other tmux sessions are not changed, and a provider that does not report usage shows nothing. The same line on demand: `php ~/pikkumies/tools/system/credits/credits.php line` ([ADR-0040](adr/0040-credits-line.md)).

To the left of the credits line, the same status line shows the memories and what the agent in the pane in front has done since `pikkumies` started there: `Memories: 42 +3 today · Ctx: 48.2k/1M 4% · Tools: 17 · Code: +120 -8`. Memories are the files `pikkumies doctor` counts, and those saved today (UTC); Ctx is the context the agent's last model request used out of the model's window; Tools counts tool calls, delegated agents' included; Code counts the lines its file edits added and removed (`patch` and `write_file`, not shell commands). A plain session prints the memories once when it starts. On a narrow terminal tmux cuts this part first ([ADR-0061](adr/0061-status-line-memories-context-tool-calls-code-lines.md)).

The tmux status line stays on the bottom row of the `pikkumies` session, below the prompt, even when your tmux.conf puts status lines at the top, and it uses the same graphite colours as Hermes' own status bar, which Pikkumies gives a calm skin (`/skin default` brings Hermes' gold look back; any skin you pick stays). Hermes draws its own bar above the input and has no setting to move it yet. With `NO_COLOR` set, `pikkumies tmux` leaves the tmux colours out ([ADR-0061](adr/0061-status-line-memories-context-tool-calls-code-lines.md)).

## Changing Hermes settings

Hermes keeps its settings in `~/pikkumies/local/hermes/config.yaml` and its keys in `~/pikkumies/local/hermes/.env`. Both are yours. Pikkumies writes `config.yaml` once, at the first start, and never rewrites either file, so what you change with Hermes' own commands stays: `hermes config set ...` (as `pikkumies run config set ...`), the display skin (`display.skin`), MCP servers, a web search backend and its key, fallback providers, memory, voice and terminal settings, plugins, checkpoints, extra skill directories ([ADR-0062](adr/0062-hermes-config-seeded-once-pins-in-hermes-managed-scope.md)).

A few settings are Pikkumies' own, rebuilt at every start in `~/pikkumies/local/hermes-managed/` (Hermes' "managed" settings, which win over your file): the provider, model and key, the safety hooks, `approvals.mode`, the web search fallback to outside services, the web search backend while `pikkumies websearch on` is in effect, borrowed logins, runtime installs, Hermes' update check and launchers, session pruning, sending Hermes' telemetry (`telemetry.shared_metrics.send`) and the `/goal` judge. `hermes config set` refuses them with "managed by your administrator"; here that means Pikkumies. Change them with `pikkumies configure` or in `~/pikkumies/local/config.php`; `pikkumies -m <model>` still picks the model for one session. `/model <name> --global` writes your file, but the managed model still wins.

Pikkumies edits your `config.yaml` only in three cases: to put its skills directory back into `skills.external_dirs` when it went missing, to put Hermes' shared-metrics answer back to "No thanks" (`telemetry.shared_metrics.enabled: false` and `offer_version`, which Hermes reads from your file only, [ADR-0072](adr/0072-hermes-shared-metrics-answer-recorded-as-no-thanks-in-the-users-file.md)), and once, when an install from before this change moves over (the old first line, its own key line in `.env`, and hidden shipped skills in Hermes' `skills.disabled`). Each edit is copied first to `~/pikkumies/local/backups/hermes/` and logged in `~/pikkumies/local/state/hermes-config.jsonl`. Hermes keeps its own copies in `~/pikkumies/local/hermes/backups/config/`. If Hermes says your settings file "has a formatting error", restore the newest good copy from one of those directories, or fix it with `pikkumies run config edit` and check with `pikkumies run config check`. `pikkumies doctor` shows the paths and the last edit.

## Forcing settings for one run

Three environment variables override the settings for one command, without editing `local/config.php` ([ADR-0038](adr/0038-plain-search-command-config-overlay-live-source-check.md)):

| Variable | What it forces |
|---|---|
| `PIKKUMIES_ENDPOINT` | The provider endpoint (an OpenAI-compatible base URL) |
| `PIKKUMIES_KEY` | The provider key |
| `PIKKUMIES_CONFIG` | A PHP file that returns a settings array, merged over `local/config.php`; any key works |

```sh
cat > ~/pikkumies/local/run.php <<'EOF'
<?php
return [
    'provider' => ['model' => 'MODEL-ID'],   // the model for this run
    'hooks' => ['model' => 'LIGHT-MODEL-ID'], // the model the hooks use
    'host' => ['family' => 'pmss'],           // which provider's documentation is "this host"
];
EOF
PIKKUMIES_CONFIG=~/pikkumies/local/run.php pikkumies doctor
```

`pikkumies doctor` names the overlay file when one is in use, and says so when the variable is set but the file is not used. The file is loaded as PHP, so it is used only when it belongs to your account and nobody else can write it; keep it in your own home, not in a shared directory such as `/tmp`. Running `pikkumies` itself with an overlay also writes the forced settings into the generated agent configuration, where they stay until the next start without it. The overlay is plain configuration: anything it can set, `local/config.php` can set too. Use it when automation would guess wrong (the model list's first entry, the detected host family) and in the question suite under `tests/testing/`.

[Back to the README](../README.md)

## Eternal windows

On a host enabled for Eternals, the per-minute cron tick restores the `pikkumies` tmux session. Its first window, `pm1`, is Primary. `pikkumies tmux [1-9]` opens the chosen named window. `pikkumies eternal new` adds the next window, up to `pm9`, without sending it a question. `pikkumies eternal quit pmN` closes a window. `pikkumies eternal off` stops its windows and keeps their records; `on` allows them to resume. `pikkumies eternal list --json` gives each record's state, including `quit` while off, held, or after Primary closes. `pikkumies eternal activity [--json]` says what Primary is doing now: its state and its newest tool call or the start of its newest agent text, never what the person typed. `pikkumies eternal hold --until <Unix timestamp>` pauses restoration for a transfer, on both the source and the target host, and returns after resident processes stop. Turning off `cron-tick` with `cron.disabled` also stops Pikkumies windows restarting.

`pikkumies ask` takes a question of up to 4000 characters from stdin and sends it to Primary only at its empty input prompt. It refuses text starting with `/`, which the runtime would treat as a command. It exits 0 when sent or when a nonce was already sent, 2 before setup, 3 with visible unsent input, 4 when rate capped, 5 while Primary is starting, 6 while Primary is busy or its idle state is unknown, or 1 on other errors. It does not print or log the question. For one-shot remote work, use `pikkumies run chat --query-file -` instead ([ADR-0051](adr/0051-claude-code-remote-skill.md)).

`pikkumies ui start` starts the platform-installed UI server in the account's user slice. Each process has its own data, log and UI PID files under `local/ui/`. The platform launcher calls it after login; the cron tick does not. `pikkumies ui web-build` builds the Hermes dashboard's web page when it is missing, as `pikkumies run dashboard` would before its first start, and exits 0 once the page is there; the platform's Power user launcher runs it in the background, because its own start waits seconds and the build takes minutes.

Without `/etc/seedbox/config/pikkumiesEternals.enabled`, the existing `pikkumies tmux` command continues to use the account's ordinary tmux server and plain sessions. `eternal`, `ask`, and `ui` are treated as prompt words. From an existing tmux client, `pikkumies tmux` switches that client to the `pikkumies` session. On an enabled host, it switches clients already on the private Eternals socket and attaches a new client when called from another tmux server.
