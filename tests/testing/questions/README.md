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
| `contains` / `not_contains` | text that must / must not be in the final answer (any case, spacing ignored: `200KB/s` matches `200 KB/s`) |
| `contains_any` | alternatives split by ` \| `; one must be in the final answer |
| `judge` | one YES/NO criterion per line, graded by the judge model |
| `source` / `note` | for people only: where the expected facts come from, notes for maintainers |

**Stale facts.** For a question tagged `source:<name>` with `contains` lines, each `contains` value must still be in the downloaded copy of that source (`local/docs-cache/<name>/` of the install, after its family's refresh). If one is gone, the question is not asked, costs no tokens and is reported as `stale` with the missing fact: fix the question, not the agent. `--regrade DIR --install KEPT` runs the same guard against a kept install.

Regular expressions are PCRE without delimiters, any case, matched against the tool name, a space and the tool input as JSON with slashes unescaped, e.g. `terminal {"command":"php ~/pikkumies/tools/system/credits/credits.php balance"}`. A judge YES counts only when the judge quotes words that really are in the answer.

Then run `php tests/testing/questions/run.php --self-test`: it lints every question file (unknown key, no check, bad regular expression, missing setup) and proves each check type on the records in `samples/`. Every asking run does this first, so a broken file never costs a token.

## Run

```sh
php tests/testing/questions/run.php --list                        # the questions
php tests/testing/questions/run.php --targets ~/.qa-targets.php --token-budget 2000000
php tests/testing/questions/run.php --targets ~/.qa-targets.php --token-budget 0 --target target-a --tag memory
```

`--token-budget N` is required: the run stops starting new questions once the agent's and the judge's tokens reach N (`0` means no limit, chosen on purpose). It is a budget for the whole run, not a per-request output cap; the older spelling `--max-tokens` is still accepted, and giving both is refused. Other flags: `--only ID`, `--max-turns N` (passed to Hermes), `--timeout S` per question (default 900), `--out DIR`, `--keep` (keep the throwaway install), `--install DIR` (reuse a kept install; a runtime build takes minutes), `--regrade DIR` (grade an earlier run's transcripts again with the current question files, no tokens).

One install serves every target. Before each question the throwaway install goes back to how it was after the install and the documentation download: new lessons, sessions and files are deleted, the settings file (`local/config.php`) is put back as it was, and the session database is restored.

## Read the results

Results go to a new private directory under the system temp dir (or `--out`): `results.jsonl` with one line per question and target (checks, code and judge scores, tokens, seconds, error, Hermes version, pikkumies commit) and `transcripts/<target>/<id>.jsonl` with what the agent did. Each row also has `command_log_new` (how many lines the question added to the install's command log, [ADR-0044](../../../docs/adr/0044-every-shell-command-is-logged.md)), `command_log_fields_ok` (every one has the ADR-0044 fields) and `command_log_session_ok` (every one names the transcript's session; `null` when the transcript named none); a question with a terminal call and no new line fails the check `command-log-missing`. This run's lines of the day files are copied to `command-log/` in the results directory, with keys masked as in the transcripts; paths stay as written, so a command on the sandbox home (`~`) and one on the account's real home stay distinct. Each row has a `status`: `pass` (no error, every code check passed), `fail` or `stale`. The scorecard printed at the end has one line per target and one per target and tag: questions asked, questions that passed, code checks passed, judge criteria met (`-` when not graded), tokens, seconds, errors, and stale questions (counted apart, in no other column). Code checks and the judge are separate columns: the first is exact, the second is a model's opinion.

## What the seed questions cover

The 50 seed questions (2026-10-18) cover every provider family's documentation (one probe per source: pm-wiki, pm-kb, feral-wiki, whatbox-wiki, ultra-docs, bytesized-wiki, seedhost-kb, hostingbydesign-docs, swizzin-docs, quickbox-docs, saltbox-docs), ten app manuals (rtorrent, ruTorrent, qBittorrent, Deluge, Servarr, Jackett, autobrr, rclone, restic, borg), the tool units (credits, storage, performance, settings, Docker, Sonarr, Plex, status), safety (deleting, other users' data, an instruction hidden in a file, the key, the terms), general and off-topic questions, and two in Finnish. Each documentation probe's facts were read from the downloaded page and its `source:` line names the page.

Not probed yet, next to add: bytesized-guides, seedboxio-docs, bazarr-wiki, plex-support, jellyfin-docs, emby-docs, rtorrent-handbook. `tests/testing/sources/check-sources.php` still checks that every source downloads.
