# Documentation sources

The documentation Pikkumies searches before it acts: wikis, knowledge bases, provider manuals and app documentation. The memory skill (`php ~/pikkumies/tools/memory/pikkumies/pikkumies.php`) downloads each source into `~/pikkumies/local/docs-cache/` and searches that copy, together with the lessons, on this machine. Your search words never leave the machine; only the documentation itself is downloaded. Only the sources meant for this host (its `scope`) are downloaded and searched.

## How to change this list

This file ships with Pikkumies and is replaced by updates, so do not edit it here (an edited file stops `pikkumies update`). Instead create `~/pikkumies/local/documentationSources.md` in the same format:

- To add a source, write a new section.
- To change a shipped source, write a section with the same name; yours replaces it.
- To turn a shipped source off, write a section with the same name and `- status: off`.

Then run `php ~/pikkumies/tools/memory/pikkumies/pikkumies.php sources` to check what is in use.

## Trust levels

When sources disagree, the higher level wins for the topics it covers. The same list is in `pikkumies/doctrine/doctrine.md`; keep the two identical.

| Level | What it is |
|---|---|
| live | What you see on this machine now: command output, files, logs, the running config. Decides what this machine is and does. |
| official | Documentation published by the provider of the service: the sources below. Decides policies, prices, terms and how the service is meant to work. |
| lessons | Lessons saved on this machine. How a past problem here was solved; check it still applies. |
| upstream | The documentation of a software project (an app's own manual). Decides how that app works in general. |
| community | Forums, blogs, answers on the web. A lead, never proof. |
| recall | Your own memory of how things usually are. Never state it as fact. |

## Fields

Each source is a `## name` section (lowercase letters, digits and dashes) with these lines, then a description in plain words:

- `type`: `mediawiki` (a MediaWiki site; `url` is its `api.php`), `kb-json` (a knowledge base exported as JSON in the Pulsed Media format), `site` (a documentation website; `url` is its sitemap.xml, a sitemap index, or an index page that links to every page), or `link` (a support, help or status page that is never downloaded; when a search finds nothing, its address is given to the user instead).
- `url`: https only, the final address (downloads follow no redirect, except one on the same host that only adds or drops a trailing slash).
- `trust`: one of the levels above.
- `covers`: the topics this source is the authority for. Outside them it is only a lead.
- `scope`: the hosts the source is for, as a comma list of host names from `pikkumies/lib/Host.php` (the provider or the software stack, such as `pmss`, `feral` or `swizzin`), `generic` (a host no row matches), or `all`. Missing means `all`. `pikkumies.php sources` shows which sources match this host.
- `verified`: the date the address last answered 200 without a redirect when checked.
- `refresh`: how old the downloaded copy may get before it is downloaded again, such as `12h` or `7d`. A search refreshes stale `mediawiki` and `kb-json` sources (at most 40 requests each). A `site` source is never fetched by a search: the background fill (started by install and by the daily update) fetches up to 300 pages a day for a provider source and 100 for an app manual, missing pages first, then the oldest; robots.txt and its Crawl-delay are followed.
- `prefix`: `site` only, optional: fetch only pages whose path starts with this. The default is the index page's own folder, or `/` for a sitemap.
- `depth`: `site` index pages only, optional: `1` (the pages the index links to, the default) or `2` (also the pages those link to).
- `status`: `on` or `off`.

Only pages on the same host as `url` are fetched, at most 500 per source and 200 MB for all sources together.

## pm-wiki

- type: mediawiki
- url: https://wiki.pulsedmedia.com/api.php
- trust: official
- covers: how-to guides, seedbox apps and their setup, using the seedbox and storage box, Linux basics, backups
- scope: pmss
- verified: 2026-10-15
- refresh: 1d
- status: on

Pulsed Media's wiki: guides for the apps on a seedbox (rTorrent, ruTorrent, autodl-irssi and others), app installation scripts, checking disk and bandwidth use, backups, and general Linux, BitTorrent and hardware articles. Pulsed Media staff write it; the public cannot edit it. Use it for "how do I..." and "how does ... work". Some product pages describe older plans, so for current prices and terms use the knowledge base.

## pm-kb

- type: kb-json
- url: https://pulsedmedia.com/remote/kb.json
- trust: official
- covers: policies, terms, prices, billing, accounts, the affiliate program, transfer speeds, what each service includes
- scope: pmss
- verified: 2026-10-15
- refresh: 1d
- status: on

Pulsed Media's customer knowledge base: billing, the affiliate program, seedboxes and storage boxes, services, transfer speeds, VPS and server hardware. Each article links to its page on the Pulsed Media client site; give that link to the user to open, but never fetch it.

## feral-wiki

- type: site
- url: https://www.feralhosting.com/wiki/index
- trust: official
- covers: Feral Hosting: slots, SSH, the web manager, installing and using apps, payments, tickets
- scope: feral
- verified: 2026-10-15
- refresh: 7d
- status: on

Feral Hosting's wiki, which replaced its FAQ. The index page links to every article (no sitemap).

## feral-support

- type: link
- url: https://www.feralhosting.com/wiki/getting-started/tickets
- trust: official
- covers: Feral Hosting support tickets
- scope: feral
- verified: 2026-10-15
- status: on

How to open a support ticket with Feral Hosting.

## whatbox-wiki

- type: site
- url: https://whatbox.ca/sitemap.xml
- trust: official
- covers: Whatbox: the wiki (apps, SSH, connecting), plans, policies and the FAQ
- scope: whatbox
- verified: 2026-10-15
- refresh: 7d
- status: on

Whatbox's wiki, policies and FAQ, listed in its sitemap. The wiki text is CC BY-SA 4.0.

## whatbox-support

- type: link
- url: https://whatbox.ca/wiki/Support
- trust: official
- covers: Whatbox support
- scope: whatbox
- verified: 2026-10-15
- status: on

Whatbox's page on getting help.

## ultra-docs

- type: site
- url: https://docs.ultra.cc/sitemap.xml
- trust: official
- covers: Ultra.cc: connection details, applications, torrent clients, rclone, the client area, SSH utilities
- scope: ultra
- verified: 2026-10-15
- refresh: 7d
- status: on

Ultra.cc's documentation and knowledge base.

## ultra-support

- type: link
- url: https://docs.ultra.cc/client-area/submit-a-support-ticket
- trust: official
- covers: Ultra.cc support tickets
- scope: ultra
- verified: 2026-10-15
- status: on

How to submit a support ticket to Ultra.cc.

## ultra-status

- type: link
- url: https://status.ultra.cc/status/sites-servers
- trust: official
- covers: Ultra.cc server and site status
- scope: ultra
- verified: 2026-10-15
- status: on

Ultra.cc's status page.

## bytesized-wiki

- type: site
- url: https://bytesized-hosting.com/system/sitemap.xml
- trust: official
- covers: Bytesized Hosting: the knowledge base (apps, SSH, accounts)
- scope: bytesized
- verified: 2026-10-15
- refresh: 7d
- prefix: /pages/
- status: on

Bytesized Hosting's knowledge base (wiki), the /pages/ part of its sitemap.

## bytesized-guides

- type: site
- url: https://bytesized-hosting.com/system/sitemap.xml
- trust: official
- covers: Bytesized Hosting: how-to guides for apps on its seedboxes
- scope: bytesized
- verified: 2026-10-15
- refresh: 7d
- prefix: /guides/
- status: on

Bytesized Hosting's guides, the /guides/ part of its sitemap.

## bytesized-contact

- type: link
- url: https://bytesized-hosting.com/contact
- trust: official
- covers: Bytesized Hosting support and contact
- scope: bytesized
- verified: 2026-10-15
- status: on

How to contact Bytesized Hosting.

## seedhost-kb

- type: site
- url: https://www.seedhost.eu/sitemap_new.xml
- trust: official
- covers: Seedhost: the knowledge base (how-to, FAQ, apps)
- scope: seedhost
- verified: 2026-10-15
- refresh: 7d
- prefix: /client-area/knowledgebase/
- status: on

Seedhost's knowledge base, the knowledge base part of its sitemap. Its pages are public; nothing behind the login is fetched.

## seedhost-contact

- type: link
- url: https://www.seedhost.eu/contact-us/
- trust: official
- covers: Seedhost support and contact
- scope: seedhost
- verified: 2026-10-15
- status: on

How to contact Seedhost.

## rapidseedbox-help

- type: site
- url: https://help.rapidseedbox.com/sitemap.xml
- trust: official
- covers: RapidSeedbox: the help center (apps, SSH, accounts)
- scope: all
- verified: 2026-10-15
- refresh: 7d
- prefix: /en/articles/
- status: off

RapidSeedbox's help center articles. Off because host detection cannot scope it: RapidSeedbox has no verifiable shell hostname yet (see the comment in `pikkumies/lib/Host.php`), so no host is ever recognised as RapidSeedbox. On a RapidSeedbox server, turn it on in `~/pikkumies/local/documentationSources.md` with a section of the same name, `- scope: all` and `- status: on`. Its robots.txt asks for one second between requests.

## rapidseedbox-support

- type: link
- url: https://www.rapidseedbox.com/help
- trust: official
- covers: RapidSeedbox help and support
- scope: all
- verified: 2026-10-15
- status: off

RapidSeedbox's help and support page. Off for the same reason as rapidseedbox-help: RapidSeedbox has no verifiable shell hostname yet (`pikkumies/lib/Host.php`), so detection cannot scope it. On a RapidSeedbox server, turn it on in `~/pikkumies/local/documentationSources.md` with a section of the same name, `- scope: all` and `- status: on`.

## hostingbydesign-docs

- type: site
- url: https://docs.hostingby.design/sitemap.xml
- trust: official
- covers: HostingByDesign (formerly Seedbox.io): application hosting, getting started, connecting
- scope: hostingbydesign
- verified: 2026-10-15
- refresh: 7d
- status: on

HostingByDesign's documentation.

## seedboxio-docs

- type: site
- url: https://docs.seedbox.io/sitemap.xml
- trust: official
- covers: Seedbox.io legacy services (now HostingByDesign)
- scope: hostingbydesign
- verified: 2026-10-15
- refresh: 7d
- status: on

The legacy Seedbox.io documentation, kept by HostingByDesign for older services.

## hostingbydesign-status

- type: link
- url: https://status.hostingby.design/
- trust: official
- covers: HostingByDesign service status
- scope: hostingbydesign
- verified: 2026-10-15
- status: on

HostingByDesign's status page.

## hostingbydesign-kb

- type: link
- url: https://my.hostingby.design/index.php?rp=/knowledgebase
- trust: official
- covers: HostingByDesign knowledge base and support
- scope: hostingbydesign
- verified: 2026-10-15
- status: on

HostingByDesign's client-area knowledge base, where tickets are opened too.

## swizzin-docs

- type: site
- url: https://swizzin.ltd/sitemap.xml
- trust: official
- covers: swizzin: installing and managing apps with box, getting started, guides
- scope: swizzin
- verified: 2026-10-15
- refresh: 7d
- status: on

The swizzin documentation. swizzin is installed on the user's own server, so its documentation is the authority for how it works.

## swizzin-discussions

- type: link
- url: https://github.com/swizzin/swizzin/discussions
- trust: official
- covers: swizzin questions and community help
- scope: swizzin
- verified: 2026-10-15
- status: on

swizzin's discussion forum.

## quickbox-docs

- type: site
- url: https://quickbox.io/sitemap.xml
- trust: official
- covers: QuickBox: the dashboard, applications, the command line, settings
- scope: quickbox
- verified: 2026-10-15
- refresh: 7d
- prefix: /docs/
- status: on

The QuickBox documentation, the /docs/ part of its sitemap.

## quickbox-support

- type: link
- url: https://quickbox.io/docs/support
- trust: official
- covers: QuickBox support
- scope: quickbox
- verified: 2026-10-15
- status: on

How to get support for QuickBox.

## saltbox-docs

- type: site
- url: https://docs.saltbox.dev/sitemap.xml
- trust: official
- covers: Saltbox: installation, apps, the sandbox apps, reference, advanced setup
- scope: saltbox
- verified: 2026-10-15
- refresh: 7d
- status: on

The Saltbox documentation.

## saltbox-support

- type: link
- url: https://docs.saltbox.dev/community/support/
- trust: official
- covers: Saltbox support
- scope: saltbox
- verified: 2026-10-15
- status: on

How to get support for Saltbox.

## rtorrent-wiki

- type: site
- url: https://github.com/rakshasa/rtorrent/wiki/_pages
- trust: upstream
- covers: rTorrent: commands, configuration, scheduling, RPC
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The rTorrent project's own wiki on GitHub; the page list links to every page.

## rtorrent-handbook

- type: site
- url: https://rtorrent-docs.readthedocs.io/en/latest/
- trust: community
- covers: rTorrent configuration recipes and scripting (a community handbook)
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The rTorrent Handbook, written by the PyroScope project and the rTorrent community, not by the rTorrent project; it dates from 2018, so check it against the version on the machine.

## rutorrent-wiki

- type: site
- url: https://github.com/Novik/ruTorrent/wiki/_pages
- trust: upstream
- covers: ruTorrent: configuration, plugins, troubleshooting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The ruTorrent project's own wiki on GitHub (some pages also in French and Russian).

## qbittorrent-wiki

- type: site
- url: https://github.com/qbittorrent/qBittorrent/wiki/_pages
- trust: upstream
- covers: qBittorrent: the Web UI and its API, settings, running without a desktop, compiling
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The qBittorrent project's own wiki on GitHub.

## deluge-docs

- type: site
- url: https://deluge.readthedocs.io/en/latest/
- trust: upstream
- covers: Deluge: installation, how-to guides, configuration, the console and web UI
- scope: all
- verified: 2026-10-15
- refresh: 30d
- depth: 2
- status: on

The Deluge documentation (user guide and reference).

## servarr-wiki

- type: site
- url: https://wiki.servarr.com/
- trust: upstream
- covers: Sonarr, Radarr, Lidarr, Readarr, Prowlarr and Whisparr: installation, settings, FAQ, troubleshooting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- depth: 2
- status: on

The Servarr wiki, shared by the *arr apps.

## bazarr-wiki

- type: site
- url: https://wiki.bazarr.media/sitemap.xml
- trust: upstream
- covers: Bazarr: setup, settings, subtitle providers, troubleshooting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The Bazarr wiki.

## autobrr-docs

- type: site
- url: https://autobrr.com/sitemap.xml
- trust: upstream
- covers: autobrr: installation, configuration, filters, release notes
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The autobrr documentation and release notes.

## jackett-wiki

- type: site
- url: https://github.com/Jackett/Jackett/wiki/_pages
- trust: upstream
- covers: Jackett: indexer definitions, troubleshooting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The Jackett project's wiki on GitHub.

## plex-support

- type: site
- url: https://support.plex.tv/sitemap_index.xml
- trust: upstream
- covers: Plex Media Server: setup, libraries, remote access, troubleshooting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- prefix: /articles/
- status: on

Plex's support articles (the articles part of its sitemap index).

## jellyfin-docs

- type: site
- url: https://jellyfin.org/sitemap.xml
- trust: upstream
- covers: Jellyfin: installation, configuration, networking, clients
- scope: all
- verified: 2026-10-15
- refresh: 30d
- prefix: /docs/
- status: on

The Jellyfin documentation, the /docs/ part of its sitemap.

## emby-docs

- type: site
- url: https://emby.media/support/articles/Home.html
- trust: upstream
- covers: Emby Server: setup, libraries, users, troubleshooting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- depth: 2
- status: on

The Emby support articles.

## rclone-docs

- type: site
- url: https://rclone.org/sitemap.xml
- trust: upstream
- covers: rclone: commands, remotes and their setup, flags, mounting
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The rclone documentation.

## restic-docs

- type: site
- url: https://restic.readthedocs.io/en/stable/
- trust: upstream
- covers: restic: repositories, backup, restore, forgetting snapshots, tuning
- scope: all
- verified: 2026-10-15
- refresh: 30d
- status: on

The restic documentation (stable release).

## borg-docs

- type: site
- url: https://borgbackup.readthedocs.io/en/stable/
- trust: upstream
- covers: BorgBackup: quickstart, usage of every command, deployment, FAQ
- scope: all
- verified: 2026-10-15
- refresh: 30d
- depth: 2
- status: on

The BorgBackup documentation (stable release; the master branch documents borg 2).
