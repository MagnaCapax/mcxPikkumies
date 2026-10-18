# Commands

| Command | What it does |
|---|---|
| `pikkumies install` | Detect the machine, generate the skills and the tool index, write config, install Hermes in the background, add the daily auto-update line to your crontab, put `pikkumies` on your `PATH` |
| `pikkumies update` | Fast-forward to the latest version (refuses to overwrite local edits); never updates Hermes |
| `pikkumies detect` | Print the machine profile (JSON) |
| `pikkumies doctor` | Show provider, masked key, Hermes and tool status |
| `pikkumies generate` | Regenerate the skills, `tools/INDEX.md` and the agent config (install, update and run do this too) |
| `pikkumies` | Start a plain interactive session (same as `pikkumies run` with no further arguments) |
| `pikkumies <free text>` | Start a session with that text as the first prompt — interactive on a real terminal, one answer and exit otherwise (Hermes' own `-q`, ADR-0022) |
| `pikkumies -- <free text>` | Same, forced even when the text starts with one of the names above (a name followed by ordinary words is already a prompt) |
| `pikkumies run [hermes args]` | Pass arguments straight through to Hermes (`pikkumies run sessions list`, `pikkumies run --tui`, …) |
| `pikkumies run update` | Update Hermes with its own updater, separately from Pikkumies; refuses while another session runs |
| `pikkumies tmux [1-9]` | Open window `pmN` (default `pm1`) of the tmux session `pikkumies`, running `pikkumies`; the session and the window are created when missing. Without tmux it says so |

Any number of `pikkumies` instances can run at once in one account: they share `HERMES_HOME` on purpose (so sessions, memory and auth live in one place) and Hermes itself is built for several writers at a time; everything *pikkumies* regenerates on every run is written under a lock or through an atomic rename, so two instances starting together never race each other (ADR-0022).

To keep several sessions one keystroke away, add aliases to your `~/.bashrc` (the installer does not add them):

```sh
alias pm1='pikkumies tmux 1'
alias pm2='pikkumies tmux 2'   # ... up to pm9
```

`pm2` attaches to the `pikkumies` tmux session at window `pm2`, starting a new agent session there if that window is not open yet; detach with `Ctrl-b d` and the sessions keep running.

## Forcing settings for one run

Three environment variables override the settings for one command, without editing `local/config.php` (ADR-0038):

| Variable | What it forces |
|---|---|
| `PIKKUMIES_ENDPOINT` | The provider endpoint (an OpenAI-compatible base URL) |
| `PIKKUMIES_KEY` | The provider key |
| `PIKKUMIES_CONFIG` | A PHP file that returns a settings array, merged over `local/config.php`; any key works |

```sh
cat > /tmp/run.php <<'EOF'
<?php
return [
    'provider' => ['model' => 'MODEL-ID'],   // the model for this run
    'hooks' => ['model' => 'LIGHT-MODEL-ID'], // the model the hooks use
    'host' => ['family' => 'pmss'],           // which provider's documentation is "this host"
];
EOF
PIKKUMIES_CONFIG=/tmp/run.php pikkumies doctor
```

`pikkumies doctor` names the overlay file when one is in use. The overlay is plain configuration: anything it can set, `local/config.php` can set too. Use it when automation would guess wrong (the model list's first entry, the detected host family) and in the question suite under `tests/testing/`.

[Back to the README](../README.md)
