# bazarr

The user's own Bazarr (subtitles) through its API on 127.0.0.1 (`<base_url>/api`, key in the `X-API-KEY` header). Shared rules and records: `tools/arr/README.md`.

| verb | arguments | changes | what it does |
|---|---|---|---|
| list | `[--search <text>] [--limit N]` | no | Every series and movie Bazarr tracks: `id` (`series:<n>` or `movie:<n>`, the Sonarr or Radarr id), `kind`, `title`, `year`, `monitored`, `path`, `profile_id` (languages profile), `missing` (series: episodes missing subtitles; movies: missing languages), and for movies `missing_languages` and `subtitles` (files present). |
| show | `<id>...` | no | The list record plus `overview`, `audio_languages` and `subtitle_files` (`language`, `path`). One unknown id fails the call. |
| stats | | no | `version`, `started_at`, `sonarr_version` and `radarr_version` (empty when Bazarr has no link to it), `found` (default, docker, override) and `config`, `port`, `url_base`, `series`, `movies`, `episodes_wanted`, `movies_wanted`, `health_warnings`. |
| health | | no | Bazarr's health issues: `source` (the file or setting), `type`, `message`, `wiki`. |
| history | `[--limit N]` | no | Newest subtitle events of series and movies: `event_at` (0 when Bazarr's local time text does not parse), `when` (Bazarr's own text), `item`, `title`, `event`, `provider`, `language`, `score`. No `--since`: Bazarr sends no machine-readable time. |
| restart | | yes | Docker: `docker restart bazarr` against the user's own daemon, then waits up to 60 s for a newer `started_at` (`answered`). Bazarr is not one of the PMSS media-stack installer's apps, so anywhere else the tool refuses and says how. |
| wanted | `[--limit N]` | no | Episodes and movies with missing subtitles: `kind`, `item`, `title`, `episode`, `episode_title`, `episode_id`, `missing_languages`; the status line has `episodes_wanted` and `movies_wanted`. |
| search | `<id>...` | yes | Asks Bazarr to search and download the missing subtitles of each item, one at a time within 60 s: `state` searched (Bazarr accepted it; 1.6.2 runs it in the background, `history` shows what it downloads), or running (still searching when the wait ran out; Bazarr goes on). The status line names the `providers` it can use. When no subtitle provider is enabled, or every enabled one is throttled, nothing is sent and the call ends `ok:false` saying which. The first failure or timeout stops the rest (`not_sent`) and the call ends `ok:false` naming every item. Downloads subtitle files only; deletes nothing. |
| login-check | | no | Why the user cannot log in to Bazarr's web page: auth.type, then one login with `BAZARR_USERNAME` and `BAZARR_PASSWORD` from `~/.media-stack-credentials.txt` (works: says where it is and stops), else one record per cause (`cause`, `detail`, `next`) and status `next`. Causes: README.md "Why a Bazarr login fails". |
| set-login | | yes | A new random password for Bazarr's user (its own user name, else the file's, else the account name): written to `~/.media-stack-credentials.txt` first, then set through Bazarr's settings API with its own key (form login); a refused change restores the file. Checked by a real login, with auth.type and the user name read back. Never printed: `password_file` and `password_line` say where it is. |
| config | `show [<section>]` \| `set <section> <field> <value>` | yes (`show`: no) | Bazarr's own settings (`system/settings`). `show` hides every secret and skips lists; `set` changes one field, keeps its type (true/false, a whole number, text) and reads it back. Never set here: auth, cors, proxy, postgresql, addresses, ports, base URLs, keys, update and permission settings, the post-processing command, webhooks, certificate checks. |

```sh
Z=~/pikkumies/tools/arr/bazarr/bazarr.php
php $Z list --search "matrix"
php $Z wanted --limit 50
php $Z show movie:5
php $Z search movie:5 series:12
php $Z history --human
```

- Ids are `series:<n>` and `movie:<n>` as `list` prints them.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
- The API key is read from config.yaml on every call and never printed.
