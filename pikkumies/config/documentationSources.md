# Documentation sources

The documentation Pikkumies searches before it acts: wikis, knowledge bases and similar. `php ~/pikkumies/tools/docs/docs.php` downloads each source into `~/pikkumies/local/docs-cache/` and searches that copy, together with the lessons, on this machine. Your search words never leave the machine; only the documentation itself is downloaded.

## How to change this list

This file ships with Pikkumies and is replaced by updates, so do not edit it here (an edited file stops `pikkumies update`). Instead create `~/pikkumies/local/documentationSources.md` in the same format:

- To add a source, write a new section.
- To change a shipped source, write a section with the same name; yours replaces it.
- To turn a shipped source off, write a section with the same name and `- status: off`.

Then run `php ~/pikkumies/tools/docs/docs.php sources` to check what is in use.

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

- `type`: `mediawiki` (a MediaWiki site; `url` is its `api.php`) or `kb-json` (a knowledge base exported as JSON in the Pulsed Media format).
- `url`: https only.
- `trust`: one of the levels above.
- `covers`: the topics this source is the authority for. Outside them it is only a lead.
- `refresh`: how old the downloaded copy may get before the next search downloads it again, such as `12h` or `7d`.
- `status`: `on` or `off`.

## pm-wiki

- type: mediawiki
- url: https://wiki.pulsedmedia.com/api.php
- trust: official
- covers: how-to guides, seedbox apps and their setup, using the seedbox and storage box, Linux basics, backups
- refresh: 1d
- status: on

Pulsed Media's wiki: guides for the apps on a seedbox (rTorrent, ruTorrent, autodl-irssi and others), app installation scripts, checking disk and bandwidth use, backups, and general Linux, BitTorrent and hardware articles. Pulsed Media staff write it; the public cannot edit it. Use it for "how do I..." and "how does ... work". Some product pages describe older plans, so for current prices and terms use the knowledge base.

## pm-kb

- type: kb-json
- url: https://pulsedmedia.com/remote/kb.json
- trust: official
- covers: policies, terms, prices, billing, accounts, the affiliate program, transfer speeds, what each service includes
- refresh: 1d
- status: on

Pulsed Media's customer knowledge base: billing, the affiliate program, seedboxes and storage boxes, services, transfer speeds, VPS and server hardware. Each article links to its page on the Pulsed Media client site; give that link to the user to open, but never fetch it.
