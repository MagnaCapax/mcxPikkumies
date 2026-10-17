# Installing and updating Pikkumies

The one-line install in the [README](../README.md) clones this repository into `~/.mcxPikkumies` and runs the installer below; this page is what that installer does and how to set it up by hand.

## Install

```sh
git clone --depth 1 https://github.com/MagnaCapax/mcxPikkumies ~/.mcxPikkumies
~/.mcxPikkumies/pikkumies/bin/pikkumies install
```

The installer needs no root and returns in seconds. It:
- links `~/pikkumies` to the runtime;
- detects the machine (OS, which seedbox host it is, root access, running apps, resources);
- generates one Hermes skill per tool unit and an index of all tools, writes the agent's configuration, and tells the agent what this machine allows;
- installs Hermes Agent in the background: an unmodified checkout of its newest release in `pikkumies/hermes`, set up by Hermes' own package manager (its pinned, checksum-verified uv and Python) in your own account, with no root and without Hermes' installer scripts, which would edit your shell files (ADR-0033);
- puts `pikkumies` on your `PATH`: a symlink in `~/.local/bin` or `~/bin` when either is already on `PATH`, otherwise one marked block added to `~/.bashrc` and `~/.profile` (never duplicated on a re-run; ADR-0022). Open a new shell (or `hash -r`) afterwards.

Then give it a model key. A platform key in `~/.virsilipasKey` (on Pulsed Media: the key shown under AI credits in the client area, pasted after `key=`) is used as it is. For your own provider, write a key file `~/.pikkumiesKey` (`endpoint=` and `key=` lines, then `chmod 600 ~/.pikkumiesKey`); it wins over the platform key. A key file others can read is set to 0600 when Pikkumies reads it. `~/pikkumies/local/config.php` can set the endpoint and the model, never the key.

```ini
endpoint=https://your-openai-compatible-endpoint/v1
key=your-key
```

Check everything with `pikkumies doctor`, then just type `pikkumies`.

On a terminal, the install and the first session ask what to call your agent, and the first interactive session shows the [terms of service](https://pulsedmedia.com/terms.php) and [privacy policy](https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy) and starts only once you accept them. Flags answer every question, so a scripted install (PMSS) asks nothing:

```sh
~/.mcxPikkumies/pikkumies/bin/pikkumies install --name=Helper --accept-terms --telemetry=on --perf-logging=on --retention-days=0
```

`--retention-days=0` keeps session history forever (the default); a number of days lets Hermes prune older ended sessions. Telemetry and performance logging are settings only for now: nothing is sent or logged. Runs without a terminal (cron, scripts, piped one-shot prompts) never ask and are never blocked. The settings can be reviewed and changed later with the `pikkumies-configure` skill (ADR-0032).

## Update

```sh
~/pikkumies/bin/pikkumies update
```

Install also adds a daily `pikkumies update` to your own crontab (one tagged line; `autoUpdate.disabled` in `local/config.php` removes it; ADR-0013). Updates fast-forward to the latest `main`. Your own state is never touched: config, lessons, Hermes state and local skills are all outside version control. If a tracked file was edited, the update stops, lists the file and changes nothing. Hermes is updated separately, when you choose: `/update` in a session, or `pikkumies run update` from a shell with no other session open. Each start checks a changed Hermes against the settings Pikkumies relies on and refuses to run it if they no longer apply (ADR-0033). To customise a shipped skill, copy it to a local skill under a new name (directory and the `name:` field), and list the shipped name under `skills.disabled` in `local/config.php`; never reuse the shipped name, because Hermes refuses to load a name that exists twice (ADR-0007).

[Back to the README](../README.md)
