# mcxPikkumies

A small helper agent that lives on the server it looks after. Ask it in plain words to check disk space, fix a stuck app, add a torrent, look after containers or backups, or explain what is going on, and it does the work with tools built for that, asking before it deletes anything. Its first home is the PMSS seedbox platform; it runs on other Linux servers with your own model provider.

## Install

```sh
curl -fsSLo install-pikkumies.sh https://raw.githubusercontent.com/MagnaCapax/mcxPikkumies/main/install.sh && bash install-pikkumies.sh
```

No curl? `wget -qO install-pikkumies.sh https://raw.githubusercontent.com/MagnaCapax/mcxPikkumies/main/install.sh && bash install-pikkumies.sh`. The [installer](install.sh) is a dozen lines: it clones this repository into `~/.mcxPikkumies` and runs `pikkumies install`, in your own account, with no root. It needs git and PHP 7.3 or newer. Run it again to update.

Then:

```sh
pikkumies doctor    # the model provider, your key (masked), credits, and whether the agent runtime is ready
pikkumies           # start a session
```

The first install builds the agent runtime in the background, so `doctor` may say "still building" for a few minutes. If `pikkumies` is not found, open a new shell.

**Model key:** on a PMSS seedbox, your key goes in `~/.virsilipasKey`; with your own OpenAI-compatible provider, in `~/.pikkumiesKey`. Details in [docs/install.md](docs/install.md).

**Experimental:** Pikkumies is early software. Keep backups of anything that matters. It asks before deleting data, but it can still be wrong, so read what it proposes before you say yes.

## What it can do

- Disk space, quota and rclone cloud or SFTP remotes: [storage](pikkumies/tools/storage/README.md)
- Torrent clients (rTorrent, qBittorrent, Deluge): [torrent clients](pikkumies/tools/torrent-clients/README.md)
- Sonarr, Radarr, Prowlarr and the other *arr apps: [arr](pikkumies/tools/arr/README.md)
- Plex, Jellyfin and Emby: [media servers](pikkumies/tools/media-servers/README.md)
- Rootless Docker containers: [containers](pikkumies/tools/containers/README.md)
- Backups with restic and borg: [backup](pikkumies/tools/backup/README.md)
- Performance and network checks: [performance](pikkumies/tools/system/performance/README.md)
- Know-how for the apps on a PMSS seedbox, with the wiki pages to read: [seedbox apps](pikkumies/tools/seedbox/seedbox-apps/README.md)
- Server administration when it has root: [server admin](pikkumies/tools/server-admin/server-admin/README.md)
- The provider's wiki and knowledge base, searched on your machine: [wiki](pikkumies/tools/documentation/wiki/README.md), [knowledge base](pikkumies/tools/documentation/kb/README.md)
- Lessons it saves, searched together with all the documentation: [memory](pikkumies/tools/memory/pikkumies/README.md)
- Longer research questions: [deep research](pikkumies/tools/research/deep-research/README.md)

It is not only for server work: it answers general questions and investigates too.

## Documentation

- [Installing and updating](docs/install.md): what the installer does, key files, a scripted install, updates
- [Commands](docs/commands.md): every command, several sessions at once, tmux
- [Design](docs/design.md): what it is meant to be, and the directory layout
- [The name](docs/name.md): the little man from the sea in the Kalevala
- [Decisions](docs/adr/0000-template.md): one ADR per decision in `docs/adr/`
- [Contributing](AGENTS.md)

## Attribution

Author: Aleksi Ursin / MCX (with Väinämöinen) — [Pulsed Media](https://pulsedmedia.com)

Built on [Hermes Agent](https://github.com/NousResearch/hermes-agent) (MIT), installed separately at install time. "OpenAI-compatible" describes an API format. OpenAI is a trademark of OpenAI; this project is not affiliated with or endorsed by OpenAI.

## License

Apache License 2.0. See [LICENSE](LICENSE) and [NOTICE](NOTICE).
