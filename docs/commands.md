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
| `pikkumies -- <free text>` | Same, forced even when the text starts with one of the names above (a name followed by ordinary words is already a prompt) |
| `pikkumies -m <model> [free text]` | A session with that model, for this session only; `--model <model>` and `--model=<model>` work too. `--reasoning <level>` sets the reasoning effort the same way. Only at the start of the line: the words after them are the prompt, even a name from above. `php ~/pikkumies/tools/system/credits/credits.php models` lists the models ([ADR-0052](adr/0052-launcher-model-flag-and-configure.md)) |
| `pikkumies --prompt-file FILE` | A session whose first prompt is the file's text; `--prompt-file -` reads it from stdin (`cat notes.txt | pikkumies --prompt-file -`), and `--query-file` is the same flag. Use it for a long prompt: one command-line argument holds at most 128 KB, and a longer prompt as words cannot start the agent. The file replaces the words, so give one or the other; it is read by Hermes as `chat --query-file`, which `--tui` ignores (ADR-0052) |
| `pikkumies --resume <session id> [free text]` | Continue that session, with the text as the next prompt; `pikkumies run sessions list` shows the ids. Combines with the flags above, for example `pikkumies --resume <id> --prompt-file -` |
| `pikkumies configure` | On a terminal: a session that reviews your settings and helps you change them. Without one: prints the settings review and starts nothing (ADR-0052) |
| `pikkumies configure undo` | Set the setting the last configure change replaced back to its old value, without a session, for example after a model that no longer starts. It shows `key old -> new (tier)` and, on a terminal, asks y/N first (the agent can write the change log too); without a terminal it makes only a change the agent could make itself. A model is checked against the endpoint's list as with any change; the provider, the terms and turning a safety layer off stay yours to change in `~/pikkumies/local/config.php` ([ADR-0059](adr/0059-settings-tiers-free-asked-and-the-persons-own.md)) |
| `pikkumies run [hermes args]` | Pass arguments straight through to Hermes (`pikkumies run sessions list`, `pikkumies run --tui`, …); started from inside an agent it refuses the flags and Hermes commands that turn approvals, hooks or rules off or pick another config ([ADR-0020](adr/0020-deletes-need-the-users-approval.md)) |
| `pikkumies run update` | Update Hermes with its own updater, separately from Pikkumies; refuses while another session runs |
| `pikkumies tmux [1-9]` | Open window `pmN` (default `pm1`) of the tmux session `pikkumies`, running `pikkumies`; the session and the window are created when missing. Without tmux it says so |

Any number of `pikkumies` instances can run at once in one account: they share `HERMES_HOME` on purpose (so sessions, memory and auth live in one place) and Hermes itself is built for several writers at a time; everything *pikkumies* regenerates on every run is written under a lock or through an atomic rename, so two instances starting together never race each other (ADR-0022).

To keep several sessions one keystroke away, add aliases to your `~/.bashrc` (the installer does not add them):

```sh
alias pm1='pikkumies tmux 1'
alias pm2='pikkumies tmux 2'   # ... up to pm9
```

`pm2` attaches to the `pikkumies` tmux session at window `pm2`, starting a new agent session there if that window is not open yet; detach with `Ctrl-b d` and the sessions keep running.

With a Pulsed Media AI key, `pikkumies` prints the credits line when a session starts, and the `pikkumies` tmux session shows it at the right of its status line, refreshed every minute: `💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≥1.1M tokens · today 35k`. The bar is what is left of the newest grant; the tokens are a floor from the model's rates, since the platform counts credits. Your other tmux sessions are not changed, and a provider that does not report usage shows nothing. The same line on demand: `php ~/pikkumies/tools/system/credits/credits.php line` ([ADR-0040](adr/0040-credits-line.md)).

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
