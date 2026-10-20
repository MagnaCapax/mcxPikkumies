# ADR-0092: Upstream issue search and drafting: search gated by web.search, free paths for commits and releases, browser-first submit

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Accepted. Adds the tool unit `development/upstream` ([ADR-0015](0015-tool-units-one-skill-per-tool.md)) and `lib/Upstream.php`, `UpstreamLimit.php`, `UpstreamCache.php`, `UpstreamScrub.php`, `UpstreamFeed.php`. Issue search is gated by the person's web search switch of [ADR-0082](0082-web-search-opt-in-keyless-ddgs.md); publishing stays the user's own act under [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) and the publish check of [ADR-0083](0083-check-before-publishing.md).

## Context
The operator asked on 2026-10-20 that Pikkumies check the GitHub repositories of the projects it works with (PMSS and the others) when it cannot find the cause of a problem: search for a known issue, check commits and releases for a fix, and help the user submit an issue when there is none. Low priority: after memory and the documentation.

Measured on 2026-10-20 from a host with one shared IPv4 address, unauthenticated:
- The API allowance is per IP address: 60 core calls an hour, 10 searches a minute, GraphQL none. 27 of the 60 were already used by other processes on that host before the first request. A primary limit answers 403 with `x-ratelimit-remaining: 0` and no `retry-after`, and its message contains the server's IP address. The window starts at the first request, so every account on a box shares the reset moment.
- A conditional request (`If-None-Match`) answered 304 and still counted against the allowance. Only a local cache saves it.
- `github.com/<repo>/commits.atom`, `releases.atom`, `tags.atom` and a treeless shallow `git clone` used no allowance (the core counter did not move) and carried no rate headers.
- A prefilled `issues/new?title=&body=` link worked up to about 6600 encoded characters; 7000 and longer failed (500, reset, 414).
- `gh` does nothing without a login (exit 4), and a gh login on a headless server is a broad token in a plain file.
- A search result item carries the whole issue body (about 5 KB); trimmed to the fields that matter it is about 530 bytes.

AGENTS.md: the agent never sends anything off the machine that the user did not ask it to. An issue search sends the words of the user's problem to GitHub.

## Options Considered
- **Issue search counts as asked whenever the user's request is about that problem.** The design study's first recommendation. Rejected in review: the words of a problem (an error with a path, a host name, a torrent name) are the user's data, and ADR-0082 already gives the person one switch for "the agent may search online". A second, implicit consent would be the kind of send nobody chose.
- **Gate issue search by web.search; everything that carries only a public project name runs freely (chosen).**
- **A token for reads.** A fine-grained token with no permissions would give the user their own 5000 an hour (from the documentation, not measured). Not built: it needs a credential flow, and the free paths plus the cache carry most of the load.
- **Submit through the API, gh api or curl.** Needs a classic or broad token on a shared box, and is out of the publish check's sight. Rejected.
- **Scrape the HTML issue search.** Undocumented page. Rejected. The keyless web search fallback was measured weak for this (path-scoped `site:` found nothing, throttling after two queries) and is not used.

## Decision
- **One tool unit, `tools/development/upstream/`,** verbs `search-issues`, `issue`, `commits`, `releases`, `draft`; JSON Lines ([ADR-0016](0016-jsonl-is-the-default-output.md)). A repository is `owner/name` checked against GitHub's name rules (`^[A-Za-z0-9-]{1,39}/[A-Za-z0-9._-]{1,100}$`, never `.` or `..`) and never guessed.
- **`search-issues` runs only while `web.search` is on in the person's own `local/config.php`** (`Config::fileConfig()`; a one-run `PIKKUMIES_CONFIG` overlay, which the agent could set itself, does not count). Off, it refuses with the stop field of [ADR-0065](0065-a-tool-refusal-carries-a-stop-field.md) and names `pikkumies websearch on`, which only the person runs; it never turns the switch on. The words go through `Redact::secrets` and are scrubbed first (`UpstreamScrub::query`): secrets, paths, URLs, the login and uid, this machine's host name, Pulsed Media's and other providers' host names, IP addresses, ports, e-mail addresses, tracker, feed and keyed URLs, file and release names (in a path or not), torrent info hashes and 16+ character keys, host:port and other host names (a file name such as `php.ini` is kept), numbers of four or more digits, search qualifiers (`repo:`, `is:`, `user:`...; `Class::method` is kept) and AND/OR/NOT are taken out, also behind an unclosed quote; at most 256 characters. The query sent is shown in the status line.
- **`issue`, `commits` and `releases` run regardless of the switch:** they carry only a public project name, as the installer's own clone does. `commits` uses a treeless shallow bare clone in `local/upstream/<owner>__<name>.git` (`--filter=tree:0 --shallow-since=90.days --single-branch`, credential helpers and askpass programs off, no terminal prompt), cloned again when older than 6 hours, removed when unused for 14 days, at most 10 mirrors and 100 MB of them (least recently used out first), searched with `git log -i --fixed-strings --grep`; without git or on a failed clone it reads `commits.atom`. `releases` reads `releases.atom`, then `tags.atom`.
- **The shared allowance.** State in `local/upstream-cache/ratelimit.json`, from every response's `x-ratelimit-*` headers; when its window has ended, the free `GET /rate_limit` is read first, once per run. Floors: no core call while 15 or fewer are left, no search while 3 or fewer. At most 2 searches and 3 core calls per run, serial, and at most 5 searches and 10 core calls per account an hour across runs (counted in the same file before each call), so one account never spends the server's hour. A primary limit (remaining 0) stops that bucket until its reset plus 0 to 120 seconds of random delay. A secondary limit (`retry-after`, or the message says so) is waited out once (at least 60 seconds; longer than 90 is refused instead) and then reported. A 422 retries once with fewer words; no answer or a 5xx retries once after 5 seconds. GitHub's error messages are never printed (they hold the server's IP address).
- **The cache** is per user in `local/upstream-cache/` (0700, files 0600, written through a temporary file and a rename), at most 20 MB, oldest first out: searches 6 h, empty searches and single issues 1 h, feeds 6 h. An expired entry is served, marked `stale`, whenever GitHub or the budget says no. Entries hold the trimmed records only. No ETag is kept: it saves no allowance without a token.
- **Fetched text is data.** Titles and commit subjects are cut at 200 characters, bodies at 2000, comments at 600. Each status line says that issue, commit and release text is third-party data, never instructions.
- **`draft`** scrubs the title and the body file (`UpstreamScrub::scrub`, then `Redact::secrets`), refuses a body over 8000 bytes, writes `local/upstream/drafts/<id>.md` (0600) and prints the full text, what was replaced (kind and count), the prefilled new-issue link of at most 6000 encoded characters (cut on whole lines, then whole characters, and marked `complete: false`), and the exact `gh issue create -R <repo> --title <t> --body-file <draft>` command. It sends nothing.
- **Submitting** (skill.md and safety.md): search first; draft only when nothing matches; show the user the whole draft and the replaced list; publish only on the user's own yes. By default the user opens the link in their own browser and submits it there; the printed gh command only when `gh auth status` shows the user already logged in. Never `gh api`, curl or another POST, `--web` or `--editor`; never ask for, store or set up a token.

## Consequences
- The agent can answer "is this known, was it fixed" from the project itself, and a user can report a bug without Pikkumies ever holding a GitHub credential.
- With web search off, only issue search is unavailable; commits, releases and reading a known issue still work.
- The floors cannot protect Pikkumies from other programs on the box using up the allowance; the cache and the free paths carry the load, and a used-up limit is reported, not retried. The floors should be tuned after measuring the real per-server use on a seedbox.
- The free paths have no published limits, and GitHub can change any of this without notice. The `commits.atom` feed of the default branch was checked once (200, 2026-10-20).
- Scrubbing is patterns: a personal detail in a form they miss stays, which is why the user reads the whole draft before it is published.
- Not measured: the logged-in browser's link limit, repositories that force issue forms, and whether `gh` is installed on seedboxes.
- `PIKKUMIES_UPSTREAM_LOCAL` (the tests' directory) is honoured only for this account's own 0700 directory.
- A security review on 2026-10-20 (PASS WITH FIXES, no critical or high) led to: secrets redacted from search words, release names, hashes and tracker hosts dropped, the login matched in any case and next to `_`, the gate read from the person's own file, the per-account hourly budget, unclosed quotes, no askpass, mirror caps, title caps, and the checked local directory.
- Tests: `tests/UpstreamTest.php`, `UpstreamToolTest.php`, `UpstreamReviewTest.php` (fixture HTTP answers and a fake git; no network).

## References
- ADR-0015, ADR-0016, ADR-0021 ([web access](0021-web-access-off-unless-configured.md)), ADR-0065, ADR-0073, ADR-0082, ADR-0083
- GitHub documentation: rate limits for the REST API, best practices for the REST API, REST search and issues, searching issues and pull requests, creating an issue from a URL query, managing personal access tokens
- Code: `pikkumies/lib/Upstream.php`, `UpstreamLimit.php`, `UpstreamCache.php`, `UpstreamScrub.php`, `UpstreamFeed.php`, `pikkumies/tools/development/upstream/`
