# Documentation sources

The documentation Pikkumies searches before it acts: wikis, knowledge bases, provider manuals and app documentation. The memory skill (`php ~/pikkumies/tools/memory/pikkumies/pikkumies.php`) downloads each source into `~/pikkumies/local/docs-cache/` and searches that copy, together with the lessons, on this machine. Your search words never leave the machine; only the documentation itself is downloaded. The sources meant for this host (its `scope`) are downloaded and searched first; other providers' sources only as a lower tier (see Search tiers).

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
- `kind`: optional, which tool searches the source besides the memory search ([ADR-0037](../../docs/adr/0037-wiki-and-kb-tool-units.md)): `wiki` (the provider's wiki or documentation: `tools/documentation/wiki`), `kb` (the provider's knowledge base or help center: `tools/documentation/kb`), `manual` (an app's own documentation: the memory search only) or `link` (type `link` only). Without it, `mediawiki` is a wiki, `kb-json` a kb, `site` a manual and `link` a link.
- `url`: https only, the final address (downloads follow no redirect, except one on the same host that only adds or drops a trailing slash). Nothing downloaded may point at a Pulsed Media `/clients/` page; a `link` may, because it is only given to the user.
- `trust`: one of the levels above.
- `covers`: the topics this source is the authority for. Outside them it is only a lead.
- `scope`: the hosts the source is for, as a comma list of host names from `pikkumies/lib/Host.php` (the provider or the software stack, such as `pmss`, `feral` or `swizzin`), `generic` (a host no row matches), or `all`. Missing means `all`. `pikkumies.php sources` shows which sources match this host.
- `verified`: the date the address last answered 200 without a redirect when checked.
- `refresh`: how old the downloaded copy may get before it is downloaded again, such as `12h` or `7d`. A search refreshes stale `mediawiki` and `kb-json` sources (at most 40 requests each). A `site` source is never fetched by a search: the background fill (started by install and by the daily update) fetches up to 300 pages a day for a provider source and 100 for an app manual, missing pages first, then the oldest; robots.txt and its Crawl-delay are followed. A site that answers 403 or 429 is paused: nothing is fetched from it for the time its Retry-After asks, else 7 days (at most 30), and `sources` shows `paused_until`.
- `prefix`: `site` only, optional: fetch only pages whose path starts with this. The default is the index page's own folder, or `/` for a sitemap.
- `depth`: `site` index pages only, optional: `1` (the pages the index links to, the default) or `2` (also the pages those link to).
- `status`: `on` or `off`.
- `repo`: optional, the address of the project's source repository: where its code and recent commits are.
- `issues`: optional, the project's issue tracker or bug-report page.
  Both are https addresses on a public host name: no IP address, port, user name or password, and no local name such as `localhost` or `.lan`.
- `tracker`: optional, what `issues` is when it is not GitHub issues: `gitlab`, `forum` or `other` (Trac, Forgejo, Redmine, Bugzilla, a mailing list, a vendor's support page). Missing means `github`, and then `issues` must be on github.com.
- `docs`: upstream entries only (see Upstream projects), the sources that hold the project's documentation, as a comma list of source names.

Only pages on the same host as `url` are fetched, at most 500 per source and 200 MB for all sources together.

## Search tiers

A search returns its hits grouped by tier, highest first. A higher tier's hit always comes before a lower tier's, whatever the scores; within a tier the best match comes first.

1. **Memory on this machine**: the lessons, reference sheets and research notes saved here.
2. **This host's provider and app documentation**: every source below whose `scope` matches this host, including the app manuals (`scope: all`).
3. **Other providers' documentation**: the sources below that are on and valid but scoped to a different provider or stack than this host. Similar services often document the same app or problem. Each hit keeps its source's trust level and carries a note that another provider's documentation may not apply to this host; check it on this machine before relying on it.
4. **Deep research on the web**: never run by a search. The search names it as the next step when tiers 1-3 have nothing.

Tier 3 is never downloaded while you wait. It is searched only from copies already on disk, and nothing downloads it until a search on this account first finds neither a lesson nor an `official` page of this host (only app manuals, or nothing at all; that search creates `~/pikkumies/local/docs-peer-enabled`). From then on the daily background fill also takes the other providers' sources, at most 50 requests per source per day, each page refreshed at most every 30 days. To keep one of them out, switch it off in your overlay with `- status: off`. The search's status line counts the pages each tier had on disk (`pages_per_tier`), so an empty tier 3 is visible.

## Upstream projects

Each app's software project is listed with its repository and issue tracker (`repo`, `issues`, `tracker`), so that a problem in an app can be checked against the project's known issues and recent commits. A source that is a project's documentation carries the lines itself; a project with no downloaded documentation here (or one that shares it, such as the *arr apps and the Servarr wiki) has an upstream entry at the end of this file: a `## name` section with these lines and no `type`. An entry is never downloaded and is not a source. `pikkumies.php upstreams` lists them all.

Checking them is low priority: it is a step of deep research (tier 4), after memory and the documentation did not explain the problem, never the first step. `tools/development/upstream` searches a project's issues and reads its recent commits and releases; an issue search sends its words to GitHub, so it needs web search on (`pikkumies websearch on`, which only the user runs). Before an issue is filed upstream, the open and closed ones are searched first; an issue is public and cannot be taken back, so it is filed only when the user asked for it, otherwise the user gets the draft.

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

## pm-support

- type: link
- url: https://pulsedmedia.com/support.php
- trust: official
- covers: open a Pulsed Media support ticket, which department to pick, what Väinämöinen sets up for customers
- scope: pmss
- verified: 2026-10-16
- status: on

Pulsed Media's support page: the ticket links per department, what to include in a ticket, and the setup work Pulsed Media's own support agent does on request. Give it to the user to open; never fetch it.

## pm-support-charter

- type: link
- url: https://pulsedmedia.com/support-charter.php
- trust: official
- covers: human support at Pulsed Media: ticket rules and the human response times
- scope: pmss
- verified: 2026-10-16
- status: on

Pulsed Media's human support charter: ticket rules and time frames for the humans behind support, who step in only when the support agent cannot.

## pm-storage-boxes

- type: link
- url: https://pulsedmedia.com/storage-boxes.php
- trust: official
- covers: Pulsed Media storage boxes: plans and prices of off-server storage for backups
- scope: pmss
- verified: 2026-10-20
- status: on

Pulsed Media's storage box page: cheap storage on another machine, the place for this account's backups. Give it to the user to open; never fetch it. The backup safety unit (`tools/backup/backup-safety`) reads this address by name, on every host, when it suggests setting up backups ([ADR-0099](../../docs/adr/0099-backup-safety-layer.md)).

## pm-zero-knowledge-backups

- type: link
- url: https://wiki.pulsedmedia.com/wiki/Zero-Knowledge_Encryption_for_Your_Seedbox_or_Storage_Box
- trust: official
- covers: end-to-end encrypted (zero-knowledge) backups from a seedbox to a storage box
- scope: pmss
- verified: 2026-10-20
- status: on

Pulsed Media's wiki guide to backups that are encrypted before they leave the seedbox, so the storage box holds only data it cannot read. Give it to the user to open; never fetch it. Read by name by the backup safety unit, like pm-storage-boxes.

## feral-wiki

- type: site
- kind: wiki
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
- kind: wiki
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
- kind: wiki
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
- kind: kb
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
- kind: wiki
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
- kind: kb
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
- kind: kb
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
- kind: wiki
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
- kind: wiki
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
- kind: wiki
- url: https://swizzin.ltd/sitemap.xml
- trust: official
- covers: swizzin: installing and managing apps with box, getting started, guides
- scope: swizzin
- verified: 2026-10-15
- refresh: 7d
- repo: https://github.com/swizzin/swizzin
- issues: https://github.com/swizzin/swizzin/issues
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
- kind: wiki
- url: https://quickbox.io/sitemap.xml
- trust: official
- covers: QuickBox: the dashboard, applications, the command line, settings
- scope: quickbox
- verified: 2026-10-15
- refresh: 7d
- prefix: /docs/
- repo: https://github.com/Stonedestroyer/QuickBox
- issues: https://quickbox.io/docs/support
- tracker: other
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
- kind: wiki
- url: https://docs.saltbox.dev/sitemap.xml
- trust: official
- covers: Saltbox: installation, apps, the sandbox apps, reference, advanced setup
- scope: saltbox
- verified: 2026-10-15
- refresh: 7d
- repo: https://github.com/saltyorg/Saltbox
- issues: https://github.com/saltyorg/Saltbox/issues
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
- repo: https://github.com/rakshasa/rtorrent
- issues: https://github.com/rakshasa/rtorrent/issues
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
- repo: https://github.com/Novik/ruTorrent
- issues: https://github.com/Novik/ruTorrent/issues
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
- repo: https://github.com/qbittorrent/qBittorrent
- issues: https://github.com/qbittorrent/qBittorrent/issues
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
- repo: https://git.deluge-torrent.org/deluge
- issues: https://dev.deluge-torrent.org/
- tracker: other
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
- repo: https://github.com/morpheus65535/bazarr
- issues: https://github.com/morpheus65535/bazarr/issues
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
- repo: https://github.com/autobrr/autobrr
- issues: https://github.com/autobrr/autobrr/issues
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
- repo: https://github.com/Jackett/Jackett
- issues: https://github.com/Jackett/Jackett/issues
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
- issues: https://forums.plex.tv/
- tracker: forum
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
- repo: https://github.com/jellyfin/jellyfin
- issues: https://github.com/jellyfin/jellyfin/issues
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
- repo: https://github.com/MediaBrowser/Emby
- issues: https://emby.media/community/
- tracker: forum
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
- repo: https://github.com/rclone/rclone
- issues: https://github.com/rclone/rclone/issues
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
- repo: https://github.com/restic/restic
- issues: https://github.com/restic/restic/issues
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
- repo: https://github.com/borgbackup/borg
- issues: https://github.com/borgbackup/borg/issues
- status: on

The BorgBackup documentation (stable release; the master branch documents borg 2).

## linuxserver-docs

- type: site
- url: https://docs.linuxserver.io/sitemap.xml
- trust: upstream
- covers: LinuxServer.io container images: each image's setup, parameters and ports, PUID/PGID, rootless use, FAQ
- scope: pmss
- verified: 2026-10-19
- refresh: 30d
- repo: https://github.com/linuxserver
- issues: https://github.com/search?q=org%3Alinuxserver&type=issues
- status: on

The LinuxServer.io documentation. PMSS runs these images in each account's rootless Docker (its own docs/linuxserver.io.md); the PM wiki stays the official source for how that works here.

## sabnzbd-wiki

- type: site
- url: https://sabnzbd.org/wiki/
- trust: upstream
- covers: SABnzbd: setup, configuration of servers and categories, folders, troubleshooting, FAQ
- scope: all
- verified: 2026-10-19
- refresh: 30d
- prefix: /wiki/
- status: on

The SABnzbd user manual (usenet downloader; the PMSS media stack installs it).

## pmss-readme

- type: site
- url: https://raw.githubusercontent.com/MagnaCapax/PMSS/main/README.md
- trust: upstream
- covers: PMSS, the seedbox software on this host: what it is, running it on your own server, installation, updates, upgrading Debian, where its documentation and decisions are
- scope: pmss
- verified: 2026-10-20
- refresh: 7d
- prefix: /MagnaCapax/PMSS/main/README.md
- repo: https://github.com/MagnaCapax/PMSS
- issues: https://github.com/MagnaCapax/PMSS/issues
- status: on

The README of PMSS, Pulsed Media's open source seedbox software, read from raw.githubusercontent.com (github.com's robots.txt disallows `/tree/`, [ADR-0027](../../docs/adr/0027-provider-and-app-documentation-scoped-to-the-detected-host.md)). Only the README is fetched (`prefix`): the PMSS `docs/` folder has no index page that links its documents yet. The Pulsed Media wiki stays the official source for how things work on Pulsed Media.

## hermes-readme

- type: site
- url: https://raw.githubusercontent.com/NousResearch/hermes-agent/main/README.md
- trust: upstream
- covers: Hermes Agent, the agent framework Pikkumies runs on: installing it, troubleshooting the install, getting started, its command line and messaging commands, where its documentation is
- scope: all
- verified: 2026-10-20
- refresh: 30d
- prefix: /NousResearch/hermes-agent/main/README.md
- repo: https://github.com/NousResearch/hermes-agent
- issues: https://github.com/NousResearch/hermes-agent/issues
- status: on

The README of Hermes Agent (`HermesInstall::REPO`). Only the README is fetched (`prefix`), not its translations.

## whisper-cpp-readme

- type: site
- url: https://raw.githubusercontent.com/ggml-org/whisper.cpp/master/README.md
- trust: upstream
- covers: whisper.cpp, the speech-to-text engine the transcribe tool builds on this machine: building it, models, quantization, memory use, hardware acceleration options
- scope: all
- verified: 2026-10-20
- refresh: 30d
- prefix: /ggml-org/whisper.cpp/master/README.md
- repo: https://github.com/ggml-org/whisper.cpp
- issues: https://github.com/ggml-org/whisper.cpp/issues
- status: on

The whisper.cpp README, which is the project's documentation. Only the README is fetched (`prefix`).

## rootlesskit-docs

- type: site
- url: https://raw.githubusercontent.com/rootless-containers/rootlesskit/master/README.md
- trust: upstream
- covers: RootlessKit, the user-namespace helper under rootless Docker: setup (subuid, sysctl, AppArmor), network and port forwarding drivers, mounts, its command line options
- scope: all
- verified: 2026-10-20
- refresh: 30d
- prefix: /rootless-containers/rootlesskit/master/docs/
- repo: https://github.com/rootless-containers/rootlesskit
- issues: https://github.com/rootless-containers/rootlesskit/issues
- status: on

The RootlessKit README and the documents it links in its `docs/` folder (network, port, mount, process, subid, API).

## libtorrent

- repo: https://github.com/rakshasa/libtorrent
- issues: https://github.com/rakshasa/libtorrent/issues
- docs: rtorrent-wiki
- verified: 2026-10-20

The library under rTorrent. It is not driven directly; the rtorrent tool reaches it through rTorrent.

## transmission

- repo: https://github.com/transmission/transmission
- issues: https://github.com/transmission/transmission/issues
- verified: 2026-10-20

Transmission, a torrent client Pikkumies detects (`lib/Detect.php`) but no tool drives.

## sonarr

- repo: https://github.com/Sonarr/Sonarr
- issues: https://github.com/Sonarr/Sonarr/issues
- docs: servarr-wiki
- verified: 2026-10-20

Sonarr (tools/arr/sonarr). Its default branch is `develop`.

## radarr

- repo: https://github.com/Radarr/Radarr
- issues: https://github.com/Radarr/Radarr/issues
- docs: servarr-wiki
- verified: 2026-10-20

Radarr (tools/arr/radarr). Its default branch is `develop`.

## lidarr

- repo: https://github.com/Lidarr/Lidarr
- issues: https://github.com/Lidarr/Lidarr/issues
- docs: servarr-wiki
- verified: 2026-10-20

Lidarr (tools/arr/lidarr). Its default branch is `develop`.

## prowlarr

- repo: https://github.com/Prowlarr/Prowlarr
- issues: https://github.com/Prowlarr/Prowlarr/issues
- docs: servarr-wiki
- verified: 2026-10-20

Prowlarr (tools/arr/prowlarr). Its default branch is `develop`.

## readarr

- repo: https://github.com/Readarr/Readarr
- issues: https://github.com/Readarr/Readarr/issues
- docs: servarr-wiki
- verified: 2026-10-20

Readarr (tools/arr/readarr). The repository is archived: its issues can still be searched, but nothing new is filed or fixed there.

## whisparr

- repo: https://github.com/Whisparr/Whisparr
- issues: https://github.com/Whisparr/Whisparr/issues
- docs: servarr-wiki
- verified: 2026-10-20

Whisparr (tools/arr/whisparr). Its default branch is `v2-develop`.

## docker

- repo: https://github.com/moby/moby
- issues: https://github.com/moby/moby/issues
- verified: 2026-10-20

Docker Engine (the Moby project): the daemon the containers tool talks to; on PMSS each account runs it rootless.

## docker-cli

- repo: https://github.com/docker/cli
- issues: https://github.com/docker/cli/issues
- verified: 2026-10-20

The `docker` command, which the containers tool runs.

## slirp4netns

- repo: https://github.com/rootless-containers/slirp4netns
- issues: https://github.com/rootless-containers/slirp4netns/issues
- verified: 2026-10-20

User-mode networking for network namespaces; the browse tool uses it to give the browser its own network. Its README is its documentation.

## podman

- repo: https://github.com/podman-container-tools/podman
- issues: https://github.com/podman-container-tools/podman/issues
- verified: 2026-10-20

Podman: reported when present, not managed. github.com/containers/podman now leads here.

## ffmpeg

- repo: https://git.ffmpeg.org/ffmpeg.git
- issues: https://code.ffmpeg.org/FFmpeg/FFmpeg/issues
- tracker: other
- verified: 2026-10-20

FFmpeg (tools/media). Its tracker is a Forgejo site; the GitHub copy is a mirror with issues turned off.

## cmake

- repo: https://gitlab.kitware.com/cmake/cmake
- issues: https://gitlab.kitware.com/cmake/cmake/-/issues
- tracker: gitlab
- verified: 2026-10-20

CMake, which the transcribe tool uses to build whisper.cpp. The issue pages check for bots; GitLab's API answers without one. The GitHub copy is a mirror with issues turned off.

## uv

- repo: https://github.com/astral-sh/uv
- issues: https://github.com/astral-sh/uv/issues
- verified: 2026-10-20

uv, which sets up Hermes' Python environment.

## agent-browser

- repo: https://github.com/vercel-labs/agent-browser
- issues: https://github.com/vercel-labs/agent-browser/issues
- verified: 2026-10-20

The browser driver of the browse tool, pinned by the Hermes lock file.

## chromium

- repo: https://chromium.googlesource.com/chromium/src
- issues: https://issues.chromium.org/issues
- tracker: other
- verified: 2026-10-20

Chromium, the browser the browse tool drives. Its tracker is the Google Issue Tracker, which needs a real browser to read.

## ddgs

- repo: https://github.com/deedy5/ddgs
- issues: https://github.com/deedy5/ddgs/issues
- verified: 2026-10-20

The keyless DuckDuckGo search package behind web search when it is on ([ADR-0082](../../docs/adr/0082-web-search-opt-in-keyless-ddgs.md)). Its README is its documentation.

## pikkumies

- repo: https://github.com/MagnaCapax/mcxPikkumies
- issues: https://github.com/MagnaCapax/mcxPikkumies/issues
- verified: 2026-10-20

Pikkumies itself. Its documentation is on this machine (`docs/`, `pikkumies/`). Feedback for Pulsed Media goes through `/feedback`.

## virsilipas

- repo: https://github.com/MagnaCapax/mcxVirsilipas
- issues: https://github.com/MagnaCapax/mcxVirsilipas/issues
- verified: 2026-10-20

Virsilipas, the Pulsed Media endpoint whose balance the credits tool reads.

## php

- repo: https://github.com/php/php-src
- issues: https://github.com/php/php-src/issues
- verified: 2026-10-20

PHP, which every Pikkumies tool runs on.

## iperf3

- repo: https://github.com/esnet/iperf
- issues: https://github.com/esnet/iperf/issues
- verified: 2026-10-20

iperf3, the network test the performance tool runs only on request.

## fio

- repo: https://github.com/axboe/fio
- issues: https://github.com/axboe/fio/issues
- verified: 2026-10-20

fio, the disk benchmark of the performance tool.

## mtr

- repo: https://github.com/traviscross/mtr
- issues: https://github.com/traviscross/mtr/issues
- verified: 2026-10-20

mtr, the route test of the performance tool.

## gnupg

- repo: https://github.com/gpg/gnupg
- issues: https://dev.gnupg.org/
- tracker: other
- verified: 2026-10-20

GnuPG, which encrypts the remote backup. The repository address is the read-only GitHub mirror (issues turned off); the project's own git server answered 429 over https. Its tracker now shows only a static ticket index.

## pigz

- repo: https://github.com/madler/pigz
- issues: https://github.com/madler/pigz/issues
- verified: 2026-10-20

pigz, the parallel gzip of the remote backup.

## openssh

- repo: https://anongit.mindrot.org/openssh.git
- issues: https://bugzilla.mindrot.org/
- tracker: other
- verified: 2026-10-20

OpenSSH (portable), the transport of the remote backup. Its Bugzilla needs a login to search or read bugs.

## tmux

- repo: https://github.com/tmux/tmux
- issues: https://github.com/tmux/tmux/issues
- verified: 2026-10-20

tmux, which holds the sessions PMSS restarts apps in and shows the credits line.

## git

- repo: https://git.kernel.org/pub/scm/git/git.git
- issues: https://lore.kernel.org/git/
- tracker: other
- verified: 2026-10-20

Git. It has no issue tracker: bugs go to its mailing list, archived at lore.kernel.org.

## nginx

- repo: https://github.com/nginx/nginx
- issues: https://github.com/nginx/nginx/issues
- verified: 2026-10-20

nginx, which the system-status tool checks; Pikkumies does not drive it.

## lighttpd

- repo: https://git.lighttpd.net/lighttpd/lighttpd1.4.git
- issues: https://redmine.lighttpd.net/projects/lighttpd/issues
- tracker: other
- verified: 2026-10-20

lighttpd, which the system-status tool checks; Pikkumies does not drive it. Its Redmine tracker needs a real browser to read.
