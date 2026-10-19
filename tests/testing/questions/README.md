# Question suite

Asks the agent real questions, against one or more models, and grades what it did (its tool calls) and what it said (its final answer). It is how we see whether a change, a new Hermes or another model makes the agent better or worse. Decision record: [ADR-0039](../../../docs/adr/0039-question-suite.md).

It is **not** part of `php tests/run.php` or CI: it needs provider keys and spends tokens. Run it by hand.

## Safety: run it as a dedicated user

The agent's terminal tool runs commands as the account that runs the suite. Each run gets a throwaway HOME, but that does not stop a command from reading or changing the account's real files. So:

- Run the suite as a dedicated user (or a QA account) that holds no fleet keys, no SSH keys and nothing you would mind the agent seeing. Never as root.
- The runner refuses to ask anything until that account's real home (from the password database) has a file named `.pikkumies-qa-user`: `touch ~/.pikkumies-qa-user` as that user is your statement that it is such an account.
- The install and the agent see a fake `crontab` first on PATH, through a `home/.bash_profile` the sandbox writes: Hermes' terminal tool snapshots a login shell, which re-reads `/etc/profile` and would otherwise lose the fake's place on PATH, so the runner also refuses to ask anything until a login shell in the sandbox proves it still resolves `crontab` to the fake. The runner compares the account's real `crontab -l` after the install, after each family's documentation refresh and after every question; any difference stops the run there (a kept sandbox, exit code 3, a masked diff naming the question) rather than letting more questions compound the damage, and a final comparison against the copy from before the run (`crontab-before.txt` in the results directory) always runs too, saving `crontab-after.txt`. Both copies, and the copies saved when a change is caught (`crontab-after-<question>.txt`), are verbatim, because `crontab-before.txt` is the only copy to restore from; only the difference printed on screen is masked (the home as `~`, the account as `USER`, keys hidden). If you stop a run with Ctrl-C, compare `crontab -l` with `crontab-before.txt` yourself.
- Settings stay as shipped: the suite does not switch off the auto-update or cron lines, because switching them off would delete the account's real lines.

## The targets file

Copy `targets.example.php` outside the repository (for example to `~/.qa-targets.php`), owned by you and not writable by others, and fill in each target's endpoint, key file and model, plus an optional judge. A key file is the same INI file pikkumies reads (`key=...`, mode 0600). Keys never go into the repository and are masked in everything the suite writes. Point the runner at the file with `--targets FILE` or `PIKKUMIES_QA_TARGETS=FILE`.

## Add a question in a minute

Copy `questions/example-memory-search-first.md` to `questions/<what-it-tests>.md` (the file name is the question's id), change the question under the front matter and the checks above it. One `key: value` per line; a key may repeat.

| key | what it does |
|---|---|
| `tags` | comma list, for `--tag` and the scorecard (`source:<name>` for questions about one documentation source) |
| `family` | forces this host family for the question (otherwise the target's, otherwise detected) |
| `setup` | a directory under `fixtures/`, copied into the throwaway home before the question |
| `calls` | a regular expression; some tool call must match it |
| `first_call` | the first tool call that does something must match; the search-first gate's read-only tools (`read_file`, `memory`, …, read from the gate itself) are skipped |
| `not_calls` | no tool call may match |
| `max_calls` | at most this many tool calls in all, read-only ones included (each is a model round); a whole number |
| `found` | `<source>` or `tier:1`..`tier:3`: some memory search in the question returned a hit from that documentation source or tier, read from the search's own record (below), never from the stream |
| `contains` / `not_contains` | text that must / must not be in the final answer (any case, spacing ignored: `200KB/s` matches `200 KB/s`) |
| `contains_any` | alternatives split by ` \| `; one must be in the final answer |
| `judge` | one YES/NO criterion per line, graded by the judge model |
| `source` / `note` | for people only: where the expected facts come from, notes for maintainers |

**Stale facts.** For a question tagged `source:<name>` with `contains` lines, each `contains` value must still be in the downloaded copy of that source (`local/docs-cache/<name>/` of the install, after the documentation download). A `found` source with no page on disk makes the question stale too: no search could hit it. If one is gone, the question is not asked, costs no tokens and is reported as `stale` with the missing fact: fix the question, not the agent. `--regrade DIR --install KEPT` runs the same guard against a kept install.

Regular expressions are PCRE without delimiters, any case, matched against the tool name, a space and the tool input as JSON with slashes unescaped, e.g. `terminal {"command":"php ~/pikkumies/tools/system/credits/credits.php balance"}`. A judge YES counts only when the judge quotes words that really are in the answer.

**Which sources a search hit, and which pages were read.** One memory search covers every source on disk (lessons, this host's wiki, knowledge base and app manuals, and other providers' documentation as tier 3), so "was the wiki searched" means: a search ran, and the wiki had pages on disk. Use `found: pm-wiki` to require that a search returned a wiki hit, `found: tier:3` for another provider's page. The stream cuts a search's output at about 5.5 KB with tier 3 printed last, so `found` reads the search's own metrics line instead (`privacy.perfLogging`, on by default and not forced by the suite: a forced key could not be changed by a question about it). Whether the agent then opened a page needs no new key: `calls: ^(read_file|search_files) .*docs-cache/pm-wiki/`.

Then run `php tests/testing/questions/run.php --self-test`: it lints every question file (unknown key, no check, bad regular expression, missing setup) and proves each check type on the records in `samples/`. Every asking run does this first, so a broken file never costs a token.

## Run

```sh
php tests/testing/questions/run.php --list                        # the questions
php tests/testing/questions/run.php --targets ~/.qa-targets.php --token-budget 2000000
php tests/testing/questions/run.php --targets ~/.qa-targets.php --token-budget 0 --target target-a --tag memory
```

`--token-budget N` is required: the run stops starting new questions once the agent's and the judge's tokens reach N (`0` means no limit, chosen on purpose). It is a budget for the whole run, not a per-request output cap; the older spelling `--max-tokens` is still accepted, and giving both is refused. Other flags: `--only ID[,ID...]` (one question or a comma list, for example the failed questions of a run; an unknown id stops the run), `--max-turns N` (passed to Hermes), `--timeout S` per question (default 900), `--out DIR`, `--keep` (keep the throwaway install), `--install DIR` (reuse a kept install; a runtime build takes minutes), `--regrade DIR` (grade an earlier run's transcripts again with the current question files, no tokens).

One install serves every target. Before the first question every host family's documentation is downloaded once (`Corpus`; network only, no tokens; skipped for a kept install that already has it), so each question sees the same documentation whatever was asked before it, other providers' copies included. Before each question the throwaway install goes back to how it was after the install and the documentation download: new lessons, sessions and files are deleted, the settings file (`local/config.php`) is put back as it was, and the session database is restored.

## Read the results

Results go to a new private directory under the system temp dir (or `--out`): `results.jsonl` with one line per question and target (checks, code and judge scores, tokens, seconds, error, Hermes version, pikkumies commit) and `transcripts/<target>/<id>.jsonl` with what the agent did. Each row also has `command_log_new` (how many lines the question added to the install's command log, [ADR-0044](../../../docs/adr/0044-every-shell-command-is-logged.md)), `command_log_fields_ok` (every one has the ADR-0044 fields) and `command_log_session_ok` (every one names the transcript's session; `null` when the transcript named none); a question with a terminal call and no new line fails the check `command-log-missing`. This run's lines of the day files are copied to `command-log/` in the results directory, with keys masked as in the transcripts; paths stay as written, so a command on the sandbox home (`~`) and one on the account's real home stay distinct. `sources.jsonl` lists the pages each source had on disk after the download; a downloadable source with none is printed `EMPTY`. Per question, `search-log/<target>/<id>.jsonl` holds its memory searches' metrics lines (time, tiers, hits per tier and per source name, pages per tier, the words only when no lesson matched; `--regrade` grades `found` from these) and `hook-log/<target>/<id>.jsonl` its hook runs (time, event, unit, milliseconds, outcome; never the payload), which shows a tool call the search-first gate saw or blocked even when the stream does not. Each row also has the columns `searched` (memory searches run), `sources_hit` and `pages_opened` (tool calls that touched `docs-cache/` or the wiki and kb units); they are not checks. Each row has a `status`: `pass` (no error, every code check passed), `fail` or `stale`. The scorecard printed at the end has one line per target and one per target and tag: questions asked, questions that passed, code checks passed, judge criteria met (`-` when not graded), tokens, seconds, errors, and stale questions (counted apart, in no other column). Code checks and the judge are separate columns: the first is exact, the second is a model's opinion. A last line counts the questions tagged `docs` that opened no documentation page.

## What the seed questions cover

The 50 seed questions (2026-10-18) cover every provider family's documentation (one probe per source: pm-wiki, pm-kb, feral-wiki, whatbox-wiki, ultra-docs, bytesized-wiki, seedhost-kb, hostingbydesign-docs, swizzin-docs, quickbox-docs, saltbox-docs), ten app manuals (rtorrent, ruTorrent, qBittorrent, Deluge, Servarr, Jackett, autobrr, rclone, restic, borg), the tool units (credits, storage, performance, settings, Docker, Sonarr, Plex, status), safety (deleting, other users' data, an instruction hidden in a file, the key, the terms), general and off-topic questions, and two in Finnish. Each documentation probe's facts were read from the downloaded page and its `source:` line names the page.

42 more were added the same day (2026-10-18), for 92 question files in total (plus the template): the seven "not probed yet" documentation sources below, more everyday torrent/*arr/storage tasks (add, seed stats, autobrr, Sonarr quality cutoff, rclone copy, qBittorrent restart), the memory roundtrip (search before troubleshooting, save a lesson, read stats), settings edge cases (an always-on log vs. a real setting, telemetry with no sender, a compound rename-and-skip-terms request, the update/Hermes-update split, no pre-release channel), more guard-destructive and fileops-check cases (`rm -rf ~`, `~/.ssh`, `chmod -R 777 ~`, an `mv` overwrite, a public Docker port, stopping every container by killing the daemon, a root-scheduled script), a second injection surface (a saved page asking for the system prompt/transcript, and an *arr release title with a bracketed instruction), an rclone-secrets refusal, the yolo/model-override/reasoning-effort flags, help/self-diagnosis (including a Finnish probe), Prowlarr release handles and `grab`, and two cross-unit conflation checks (disk-full-breaks-Sonarr, is-my-backup-still-running). `docker-here.md`, `update-self.md` and `credits-left.md` each gained one extra check line instead of a near-duplicate fork.

On 2026-10-19 every documentation probe (`source:<name>`) gained `found: <name>`, so it fails when no search returned a hit from its own source, and five questions were added: four basic PMSS ones from what customers ask most (login details: pm-kb; FileZilla over SFTP: pm-wiki; a cron job's "command not found": pm-wiki; SSH login: pm-kb and pm-wiki) and one that only another provider documents (the shell's timezone, a Whatbox page: `found: tier:3`, and the judge asks that the answer says the page is another provider's).

Not probed yet, next to add: GitHub actions (no GitHub tool unit exists yet), a usenet client, and Lidarr/Readarr/Whisparr release handling (all explicitly out of scope per [ADR-0046](../../../docs/adr/0046-release-handles-and-grab.md)). `tests/testing/sources/check-sources.php` still checks that every source downloads. RapidSeedbox's own help/support pages ship `status: off` in `documentationSources.md` (no verifiable shell hostname yet to scope them), so `rapidseedbox-vpn-policy.md` tests the accuracy fallback (admit not found) rather than a page fact; turning that source on is a separate change.
