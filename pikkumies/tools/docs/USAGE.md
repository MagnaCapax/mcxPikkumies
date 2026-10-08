# tools/docs

Documentation sources: wikis and knowledge bases listed in `pikkumies/config/documentationSources.md` (shipped) and `pikkumies/local/documentationSources.md` (yours; same format, same name replaces). Each source is downloaded into `pikkumies/local/docs-cache/<name>/` and searched there together with the lessons. Search words never leave the machine; only the documentation is downloaded.

```sh
php ~/pikkumies/tools/docs/docs.php search "rtorrent not starting"      # lessons + docs, best first; downloads stale sources first
php ~/pikkumies/tools/docs/docs.php search "refund policy" --offline     # use the copies already on disk
php ~/pikkumies/tools/docs/docs.php read ~/pikkumies/local/docs-cache/pm-wiki/rtorrent.md
php ~/pikkumies/tools/docs/docs.php sources                              # what is configured, what is wrong, how old each copy is
php ~/pikkumies/tools/docs/docs.php refresh [name]                       # download now
```

- `search` and `read` change nothing except the downloaded copies under `local/docs-cache/`. `refresh` replaces a copy only after the whole download succeeded; a failed download keeps the old copy.
- Each hit shows its trust level (`live`, `official`, `lessons`, `upstream`, `community`, `recall`; see the config file) and the page address to give the user.
- `read` only opens lessons and downloaded documentation.
- Downloads are https only, never follow redirects, stop at 5 MB per response and 40 requests per source, and never touch a `/clients/` page.
- Source types: `mediawiki` (the site's `api.php`, 50 pages per request) and `kb-json` (a knowledge base exported as JSON in the Pulsed Media format).
- Running `search` is what opens the search-first gate (`hooks/pre_tool_call/search-first-gate.php`).
