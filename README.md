# mcxPikkumies

A lightweight sysadmin helper agent that lives on the server it looks after. Its first home is the PMSS seedbox platform, but it is built general enough to run on other systems with your own API and keys.

**Status:** early. The layout, installer, detection, packs, memory and Hermes wiring work; skills and packs are a first set.

## Install

```sh
git clone --depth 1 https://github.com/MagnaCapax/mcxPikkumies ~/.mcxPikkumies
~/.mcxPikkumies/pikkumies/bin/pikkumies install
```

The installer needs no root and returns in seconds. It:
- links `~/pikkumies` to the runtime;
- detects the machine (OS, root access, running apps, resources);
- writes the agent's configuration with every pack loaded, and tells the agent what this machine allows;
- builds the Hermes Agent environment in the background.

Then set your provider, either in `~/pikkumies/local/config.php` or in a key file `~/.pikkumiesKey`:

```ini
endpoint=https://your-openai-compatible-endpoint/v1
key=your-key
```

Check everything with `~/pikkumies/bin/pikkumies doctor`, then start the agent with `~/pikkumies/bin/pikkumies run`.

## Update

```sh
~/pikkumies/bin/pikkumies update
```

Updates fast-forward to the latest `main`. Your own state is never touched: config, lessons, Hermes state and local skills are all outside version control. If a tracked file was edited, the update stops, lists the file and changes nothing. To customise a shipped skill, copy it to a local skill under a new name and list the shipped name under `skills.disabled` in `local/config.php`; never reuse the shipped name, because Hermes refuses to load a name that exists twice (ADR-0007).

## Commands

| Command | What it does |
|---|---|
| `pikkumies install` | Detect the machine, write config (every pack is loaded), build Hermes in the background |
| `pikkumies update` | Fast-forward to the latest version; refuses to overwrite local edits |
| `pikkumies detect` | Print the machine profile (JSON) |
| `pikkumies doctor` | Show provider, masked key, Hermes and pack status |
| `pikkumies packs [index]` | List packs; regenerate `packs/INDEX.md` |
| `pikkumies run [args]` | Start the agent |

## What it is meant to be

- **It runs as you.** By default it works under the user's own account, inside that user's slice of the server. Root access is one more capability layer on top of that. When the agent detects sudo or root deterministically, it can do full server administration. When it doesn't, it manages only that slice. A config switch turns the root layer off.
- **It knows where to look.** Basic memory (lessons), knowledge sources, and a small doctrine: verify before claiming, cite the source, ask before anything destructive.
- **It grows by packs.** Skills, tools and hooks come in packs. Every pack is loaded on every machine; the agent is told what the machine allows, and each skill checks that it applies (ADR-0006).
- **Your model, your keys.** It talks to any OpenAI-compatible endpoint. A deployment chooses its own provider and keys.
- **It is not only for sysadmin work.** General investigation and research are in scope too.

## Layout

```
pikkumies/            the runtime (~/pikkumies)
  bin/pikkumies       entrypoint
  lib/                core PHP
  tools/<target>/     tools by target system, each with USAGE.md
  skills/             core skills (Hermes SKILL.md format)
  hooks/<event>/      safety hooks run by the agent (PHP, JSON in and out)
  doctrine/           soul, voice, doctrine, safety; SOP/ for procedures
  memory/             your lessons, one markdown file each (never tracked)
  include/            third-party software (hermes/, installed, never tracked)
  local/              your config, machine profile and agent state (never tracked)
packs/                bundles: INDEX.md (generated), README.md, one directory per pack
docs/adr/             architecture decisions
tests/                php tests/run.php
```

## The name

*Pikkumies*, the little man from the sea, comes from Runo II of the Kalevala. A great oak has grown until it "päivän peitti paistamasta" (hid the sun from shining), and nobody can fell it. Väinämöinen calls for someone of the water folk to fell it, and a man no taller than a thumb rises from the sea: "Olen mie mokoma miesi, uros pieni, veen väkeä. Tulin tammen taittamahan" (I am such a man, a little hero of the water folk; I came to fell the oak). Väinämöinen doubts him. The little man then grows into a giant and fells the tree in three strokes.

That is the job description: small by default, sent for when there is work, and as big as the task needs.

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

Built on [Hermes Agent](https://github.com/NousResearch/hermes-agent) (MIT), installed separately at install time. "OpenAI-compatible" describes an API format. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
