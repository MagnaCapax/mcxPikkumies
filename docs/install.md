# Installing and updating Pikkumies

The one-line install in the [README](../README.md) clones this repository into `~/.mcxPikkumies` and runs the installer below; this page is what that installer does and how to set it up by hand.

## Install

```sh
git clone --depth 1 https://github.com/MagnaCapax/mcxPikkumies ~/.mcxPikkumies
~/.mcxPikkumies/pikkumies/bin/pikkumies install
```

The installer needs no root and returns in seconds. It:
- on a fresh clone, moves it to the newest release (a tag named by its date, `YYYY-MM-DD`, or `YYYY-MM-DD.N` for a second release that day) unless you install with `--channel=git/<branch>`; with no release published yet it stays on `main` ([ADR-0057](adr/0057-release-channel.md));
- links `~/pikkumies` to the runtime;
- detects the machine (OS, which seedbox host it is, root access, running apps, resources);
- generates one Hermes skill per tool unit and an index of all tools, writes the agent's configuration, and tells the agent what this machine allows;
- installs Hermes Agent in the background: an unmodified checkout of its newest release in `pikkumies/hermes`, set up by Hermes' own package manager (its pinned, checksum-verified uv and Python) in your own account, with no root and without Hermes' installer scripts, which would edit your shell files ([ADR-0033](adr/0033-vanilla-hermes-git-checkout-updated-separately.md));
- puts `pikkumies` on your `PATH`: a symlink in `~/.local/bin` or `~/bin` when either is already on `PATH`, otherwise one marked block added to `~/.bashrc` and `~/.profile` (never duplicated on a re-run; [ADR-0022](adr/0022-pikkumies-is-the-one-command-prompt-routing-and-path-install.md)). Open a new shell (or `hash -r`) afterwards.

Then give it a model key. A platform key in `~/.virsilipasKey` (on Pulsed Media: the key shown under AI credits in the client area, pasted after `key=`) is used as it is. For your own provider, write a key file `~/.pikkumiesKey` (`endpoint=` and `key=` lines, then `chmod 600 ~/.pikkumiesKey`); it wins over the platform key. A key file others can read is set to 0600 when Pikkumies reads it. `~/pikkumies/local/config.php` can set the endpoint and the model, never the key.

```ini
endpoint=https://your-openai-compatible-endpoint/v1
key=your-key
```

Check everything with `pikkumies doctor`, then just type `pikkumies`.

On a Pulsed Media seedbox with at least 1000 MiB RAM, Pikkumies comes preinstalled on new accounts. The platform runs the installer with `PIKKUMIES_SKIP_HERMES=1`, which skips the agent runtime build. The runtime then builds at the account's daily update time, or the first time you run `pikkumies`: that run starts the build and asks you to come back in a few minutes (ADR-0033).

On a terminal, the install and the first session ask what to call your agent, and the first interactive session shows the [terms of service](https://pulsedmedia.com/terms.php) and [privacy policy](https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy) and starts only once you accept them. Flags answer every question, so a scripted install (PMSS) asks nothing:

```sh
~/.mcxPikkumies/pikkumies/bin/pikkumies install --name=Helper --accept-terms --telemetry=on --perf-logging=on --retention-days=0 --channel=release
```

`--retention-days=0` keeps session history forever (the default); a number of days lets Hermes prune older ended sessions. Telemetry is a setting only for now: nothing is sent. Performance logging keeps, on this machine only and never sent, the time and result counts of each memory search (per tier, and per documentation source by name), its words only when it found no lesson, and the time of each lesson save (`local/state/memory-metrics/`, shown by the memory skill's `stats`). With `--retention-days` above 0, lines older than that are pruned; with 0 they are kept until you delete them. `--perf-logging=off` stops new lines; files already written stay until you delete them. Every shell command the agent runs is logged, always, on this machine only: one line per command in `local/logs/commands/YYYYMMDD.jsonl` (UTC day) with the time, session, directory, the command with passwords and tokens removed, the exit code and duration, never the output; kept as long as the session history ([ADR-0044](adr/0044-every-shell-command-is-logged.md)). Runs without a terminal (cron, scripts, piped one-shot prompts) never ask and are never blocked. The settings can be reviewed and changed later with the `pikkumies-configure` skill ([ADR-0032](adr/0032-settings-configure-skill-first-run-and-install-flags.md)).

## Update

```sh
~/pikkumies/bin/pikkumies update
```

Install also adds a daily `pikkumies update` to your own crontab (one tagged line; `autoUpdate.disabled` in `local/config.php` removes it; [ADR-0013](adr/0013-daily-auto-update-from-the-user-crontab.md)). Updates follow the update channel (ADR-0057):

- `release` (the default): move to the newest release, fast-forward only. A checkout already ahead of the newest release (for example one that followed `main` before releases existed) stays where it is until a newer release comes out, and says so. Until the first release is published, updates follow `main` as before.
- `git/<branch>`: follow that branch of the repository, fast-forward only; `git/main` gets every change as it lands.

Choose it at install with `--channel=git/main` (or `--channel=release`), or later with `pikkumies configure` (or ask the agent; the `pikkumies-configure` skill sets `update.channel`). `pikkumies update` prints the channel it followed, and `pikkumies doctor` shows the channel and the release or commit you are on.

Your own state is never touched: config, lessons, Hermes state and local skills are all outside version control. If a tracked file was edited, the update stops, lists the file and changes nothing. Hermes is updated separately, when you choose: `/update` in a session, or `pikkumies run update` from a shell with no other session open. Each start checks a changed Hermes against the settings Pikkumies relies on and refuses to run it if they no longer apply (ADR-0033). To customise a shipped skill, copy it to a local skill under a new name (directory and the `name:` field), and list the shipped name under `skills.disabled` in `local/config.php` (the shipped skill is then not generated); never reuse the shipped name, because Hermes refuses to load a name that exists twice ([ADR-0007](adr/0007-customising-shipped-skills.md)). Changes you make with Hermes' own commands persist; see [Changing Hermes settings](commands.md#changing-hermes-settings).

[Back to the README](../README.md)
