# ADR-0027: Provider and app documentation, scoped to the detected host

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-15
- Status: Accepted

## Context
The operator's request of 2026-10-15: when Pikkumies detects the hosting provider, map that provider's primary sources (wiki, manuals, docs, knowledge base, support services) for all providers, and search them like memory ("this is like memory, no different just different name"). The hooks that enforce searching, and the model they use, are [ADR-0028](0028-search-first-every-turn-and-a-light-model-for-hooks.md).

What already existed covered Pulsed Media's own sources only:
- `documentationSources.md` lists the sources.
- Downloaded copies are searched together with the lessons by one command ([ADR-0011](0011-documentation-sources-and-search-first.md), [ADR-0012](0012-one-search-command-and-curl.md), [ADR-0018](0018-memory-tool-unit.md)).
- The deep-research unit handles questions the local search cannot answer ([ADR-0021](0021-web-access-off-unless-configured.md)).

Research on 2026-10-15 (raw responses kept with the session notes) found:
- **Providers and stacks:** seven of the ten serve a sitemap. Those are Whatbox, Ultra.cc, Bytesized, Seedhost, RapidSeedbox, QuickBox, and the Docusaurus/MkDocs sites of Swizzin, Saltbox and the old Seedbox.io. Feral's wiki has no sitemap; one index page links its 72 pages.
- **Upstream apps with sitemaps:** rclone, Jellyfin, autobrr, Bazarr (whose sitemap uses `http://` addresses on its own host) and Plex (a sitemap index).
- **Upstream apps with index pages only:** the GitHub wikis (rTorrent, ruTorrent, qBittorrent), Read the Docs sites (Deluge, restic, Borg), the Servarr wiki and Emby.
- **No GitHub repository or archive download** was needed for any of them.

## Options Considered
- **Let the agent fetch provider pages on demand with Hermes' web tools.** Rejected:
  - `web_extract` needs a configured backend once keyless fallback is off (ADR-0021).
  - It would send the user's words off the machine, which ADR-0011 rules out for search.
- **Download GitHub repositories, zip exports or one-file search indexes.** Rejected:
  - They need new formats and extensions.
  - Several exceed the 5 MB response limit.
  - Crawling index pages covers the same sites.
- **One shared cache per server, maintained by root.** Not done here, because it is a PMSS-side change. It is the answer if per-account downloads prove too much on large shared servers (see Consequences).
- **Extend the existing sources (chosen).**

## Decision
- **Scope:**
  - Each source in `documentationSources.md` has `scope:` (Host::TABLE names, `generic` or `all`) and `verified:` (the date its URL answered 200 with no redirect).
  - A scope name that is not a Host::TABLE key, `generic` or `all` is a configuration problem.
  - Only sources whose scope matches this machine's detected family or stack, plus `all`, are downloaded and searched.
  - Pulsed Media's wiki and KB are scope `pmss`. App manuals are scope `all`, trust `upstream`.
  - RapidSeedbox's sources ship switched off: there is no verifiable shell hostname to detect it by (Host.php). A user can switch them on in the local overlay.
- **`site` type** (`lib/DocSite.php`):
  - `url` is a sitemap, a sitemap index, or an index page whose links are followed to depth 1 or 2.
  - Only links on the same host under the path prefix are kept. GitHub wiki action pages (`/_new`, `/_history`, …) are dropped.
  - A same-host `http://` address is upgraded to https.
  - Redirects: one same-host redirect that only adds or drops a trailing slash is followed; no other.
  - robots.txt Disallow and Crawl-delay are honoured. An unreachable or failing robots.txt skips the run.
  - Pages are stored as text with the existing front matter, and refreshed incrementally under a per-source lock.
  - Limits: 500 pages per source, plus a cap on the whole cache.
- **Download budgets:**
  - A search never crawls `site` sources. A search may still refresh a stale MediaWiki or KB source with up to 40 requests (ADR-0011, unchanged).
  - `site` sources are filled only in the background: a detached `pikkumies docs-fill`, started by install and by the daily update. It begins after a random wait of up to an hour.
  - Each source gets a daily budget: 300 requests for a provider source, 100 for an app manual. Provider sources refresh every 7 days, app manuals every 30.
- **`link` type:**
  - Support, help and status pages that are never downloaded.
  - When a search finds nothing, the matching providers' links are shown, so the agent points the user at the provider instead of guessing.
- **Detection data:**
  - Host::TABLE marks the Whatbox and Bytesized hostnames verified against their own SSH pages.
  - It also gains `hostingbydesign`, Seedbox.io's successor (`appNN.hostingby.design`).
- **Doctrine:**
  - The "Search first" line names the ladder at no extra size: memory and this host's docs, then the deep-research skill, then the provider's support link, then ask the user.
  - The memory skill's text and the search tool's next-step hint say the same.

## Consequences
- The agent answers provider and app questions from the provider's own documentation on every supported host. When nothing answers, it points the user at the provider's support instead of guessing.
- **Downloads per account:** each account fills its own copy; nothing is shared between accounts, which keeps poisoning across accounts impossible.
  - About 2,000 pages across 17 sources for a host with every source in scope.
  - At most 300 + 100 requests per source per day while filling.
  - Afterwards roughly pages ÷ refresh days, about 70 requests per day for the app manuals.
  - On a shared server this multiplies by the number of accounts, all from one IP, towards GitHub, Read the Docs and others. For 100 accounts that is in the order of 7,000 requests a day once filled.
  - Random start times and the 30-day app refresh spread the load. If sites start refusing, the next step is a root-maintained shared cache in PMSS.
- **Disk:** each account can use up to the cache cap of its own quota (200 MB), shown by `pikkumies doctor`.
- **Coverage:** crawling index pages covers less than a full export on the deepest sites (GitHub wikis, Read the Docs, the Servarr wiki). This is accepted, and noted per source.
- **Not shipped:** sites behind bot walls (the Deluge Trac wiki, qbittorrent.org). A Cloudflare challenge on a shipped source keeps the old copy and shows the error in `sources`.


## Addendum 2026-10-16: a `link` may point at a `/clients/` page

ADR-0011 forbids downloading any `/clients/` address, because unauthenticated requests there can look like failed logins. A `link` is never downloaded; it is only given to the user. So `DocSources::problems()` now refuses `/clients/` only for downloaded types, and the Pulsed Media `pm-support` link points at the ticket form, the same address the PMSS landing page gives. Fetching still refuses every `/clients/` address on its own: `DocSources::get()` and `DocSite` check the URL before any request. The agent is told to give links to the user and never fetch them.

## References
- ADR-0011, ADR-0012, ADR-0018, ADR-0021, [ADR-0023](0023-host-detection-cache.md), ADR-0028.
