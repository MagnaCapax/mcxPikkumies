# mcxPikkumies

A lightweight sysadmin helper agent that lives on the server it looks after. Its first home is the PMSS seedbox platform, but it is built general enough to run on other systems with your own API and keys.

**Status:** early. The layout, installer, detection, memory and Hermes wiring work; the tool units are a first set, with torrent clients, *arr apps, media servers and backup tools on the way.

## Install

```sh
git clone --depth 1 https://github.com/MagnaCapax/mcxPikkumies ~/.mcxPikkumies
~/.mcxPikkumies/pikkumies/bin/pikkumies install
```

The installer needs no root and returns in seconds. It:
- links `~/pikkumies` to the runtime;
- detects the machine (OS, which seedbox host it is, root access, running apps, resources);
- generates one Hermes skill per tool unit and an index of all tools, writes the agent's configuration, and tells the agent what this machine allows;
- builds the Hermes Agent environment in the background: a pinned, checksum-verified Hermes release in your own account, with no root. It uses the system Python 3.11 to 3.13, or, on x86_64 where that is older, a pinned checksum-verified standalone Python in your own account (ADR-0014);
- puts `pikkumies` on your `PATH`: a symlink in `~/.local/bin` or `~/bin` when either is already on `PATH`, otherwise one marked block added to `~/.bashrc` and `~/.profile` (never duplicated on a re-run; ADR-0022). Open a new shell (or `hash -r`) afterwards.

Then set your provider, either in `~/pikkumies/local/config.php` or in a key file `~/.pikkumiesKey`:

```ini
endpoint=https://your-openai-compatible-endpoint/v1
key=your-key
```

Check everything with `pikkumies doctor`, then just type `pikkumies`.

## Update

```sh
~/pikkumies/bin/pikkumies update
```

Install also adds a daily `pikkumies update` to your own crontab (one tagged line; `autoUpdate.disabled` in `local/config.php` removes it; ADR-0013). Updates fast-forward to the latest `main`. Your own state is never touched: config, lessons, Hermes state and local skills are all outside version control. If a tracked file was edited, the update stops, lists the file and changes nothing. To customise a shipped skill, copy it to a local skill under a new name (directory and the `name:` field), and list the shipped name under `skills.disabled` in `local/config.php`; never reuse the shipped name, because Hermes refuses to load a name that exists twice (ADR-0007).

## Commands

| Command | What it does |
|---|---|
| `pikkumies install` | Detect the machine, generate the skills and the tool index, write config, build the pinned Hermes release in the background, add the daily auto-update line to your crontab, put `pikkumies` on your `PATH` |
| `pikkumies update` | Fast-forward to the latest version (refuses to overwrite local edits); builds a newly pinned Hermes release |
| `pikkumies detect` | Print the machine profile (JSON) |
| `pikkumies doctor` | Show provider, masked key, Hermes and tool status |
| `pikkumies generate` | Regenerate the skills, `tools/INDEX.md` and the agent config (install, update and run do this too) |
| `pikkumies` | Start a plain interactive session (same as `pikkumies run` with no further arguments) |
| `pikkumies <free text>` | Start a session with that text as the first prompt — interactive on a real terminal, one answer and exit otherwise (Hermes' own `-q`, ADR-0022) |
| `pikkumies -- <free text>` | Same, forced even when the text starts with one of the names above (a name followed by ordinary words is already a prompt) |
| `pikkumies run [hermes args]` | Pass arguments straight through to Hermes (`pikkumies run sessions list`, `pikkumies run --tui`, …) |

Any number of `pikkumies` instances can run at once in one account: they share `HERMES_HOME` on purpose (so sessions, memory and auth live in one place) and Hermes itself is built for several writers at a time; everything *pikkumies* regenerates on every run is written under a lock or through an atomic rename, so two instances starting together never race each other (ADR-0022).

## What it is meant to be

- **It runs as you.** By default it works under the user's own account, inside that user's slice of the server. Root access is one more capability layer on top of that. When the agent detects sudo or root deterministically, it can do full server administration. When it doesn't, it manages only that slice. A config switch turns the root layer off.
- **It knows where to look.** Basic memory (lessons), documentation sources it downloads and searches locally (`pikkumies/config/documentationSources.md`, ADR-0011), and a small doctrine: verify before claiming, cite the source and its trust level, ask before anything destructive. On the first turn of a task it searches before it acts.
- **It grows by tool units.** Every capability is one directory under `pikkumies/tools/<category>/<tool>/`: a skill, its tool, USAGE.md and README.md. Every tool prints JSON Lines for the agent and `--human` for people. All units load on every machine; the agent is told what the machine allows, and each skill checks that it applies (ADR-0015 to ADR-0017).
- **It asks before deleting data.** A torrent's files, a backup prune or an rclone sync go to the user for approval in the chat (ADR-0020).
- **Your model, your keys.** It talks to any OpenAI-compatible endpoint. A deployment chooses its own provider and keys.
- **It is not only for sysadmin work.** General investigation and research are in scope too.

## Layout

```
pikkumies/            the runtime (~/pikkumies)
  bin/pikkumies       entrypoint
  lib/                core PHP
  tools/<category>/<tool>/  tool units: skill.md, USAGE.md, README.md, <tool>.php; INDEX.md is generated
  skills/             core skills not yet moved into units (Hermes SKILL.md format)
  hooks/<event>/      safety hooks run by the agent (PHP, JSON in and out)
  config/             documentationSources.md: the wikis and knowledge bases it searches
  doctrine/           soul, voice, doctrine, safety; SOP/ for procedures
  memory/             your lessons, one markdown file each (never tracked)
  include/            third-party software, never tracked: hermes-<tag>/ builds, hermes -> current one
  local/              your config, machine profile and agent state (never tracked)
docs/adr/             architecture decisions
tests/                php tests/run.php
```

## The name

*Pikkumies*, the little man from the sea, comes from Runo II of the Kalevala. A great oak has grown until it "päivän peitti paistamasta" (hid the sun from shining), and nobody can fell it. Väinämöinen calls for someone of the water folk to fell it, and a man no taller than a thumb rises from the sea: "Olen mie mokoma miesi, uros pieni, veen väkeä. Tulin tammen taittamahan" (I am such a man, a little hero of the water folk; I came to fell the oak). Väinämöinen doubts him. The little man then grows into a giant and fells the tree in three strokes.

He comes back in Runo XLVIII. Väinämöinen needs someone to beat the water and drive the fish into his net, and the little man rises from the sea again and asks: "Tarvonko väen mukahan, oike'in olan takoa, vai tarvon asun mukahan?" (Shall I beat the water with all my strength, the full swing from the shoulder, or as the work needs?) Väinämöinen answers: "Jos tarvot asun mukahan, äijä on siinä tarpomista" (As the work needs: that is plenty). He does, and drives a great many fish into the net.

That is the job description: small by default, sent for when there is work, as big as the task needs, and no more effort than the work needs.

Verses: *Kalevala* (Elias Lönnrot, 1849), Runo II 137-142 and Runo XLVIII 151-176. The English is ours, from the Finnish. "Asun mukahan" is read from Runo XXXV, where the same couplet pairs it with "tarpehen takoa" (as the need demands); J. M. Crawford's 1888 translation is loose in this passage.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

Built on [Hermes Agent](https://github.com/NousResearch/hermes-agent) (MIT), installed separately at install time. "OpenAI-compatible" describes an API format. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
