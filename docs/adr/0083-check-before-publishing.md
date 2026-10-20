# ADR-0083: Check what goes out before publishing to a public place

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-20
- Status: Accepted. Adds the hook unit `guard/publish-check` ([ADR-0030](0030-hooks-as-units-run-by-one-orchestrator.md)) and one rule to `pikkumies/doctrine/safety.md`. Its question goes through the ask policy of [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) as a send.

## Context
The operator asked on 2026-10-20 that every submit (a git commit, a GitHub comment and the like) be checked first: for what the user counts as private or not to be revealed, and for secrets nobody should publish, such as passwords; and that the check tell a public repository from a private one, and from one that is going to be public. Pikkumies is the end-user version: it has to be light, because every model call costs the user credits.

What was found:
- Users' agents push, open issues and pull requests, comment and make gists and releases for them, through `git` and `gh` in the terminal. Something published in a public repository, issue, pull request or gist is copied, indexed and archived by others within minutes; deleting it later does not take it back. A commit's history keeps it even after the file is removed.
- safety.md already says never print, log or send secrets, and the agent works on the user's data at their request. Nothing checked what a push or a gh write actually carries.
- `lib/Redact.php` already finds the common secret forms (options, headers, variables, config lines, private key blocks, common token prefixes) for the command log ([ADR-0044](0044-every-shell-command-is-logged.md)) and `/feedback` ([ADR-0056](0056-feedback-what-pikkumies-sends-to-pulsed-media.md)). It is deterministic and free.
- A private repository is the user's own space: what they keep there is theirs to keep. Pushes between the user's own machines (ssh, rsync, a self-hosted git server, another folder) are not publishing.
- `gh repo view <owner/repo> --json visibility` answers PUBLIC, PRIVATE or INTERNAL for a repository the logged-in user can see. GitLab, Codeberg and Bitbucket have no such tool installed by default.
- A hook's approve is turned by the ask policy into a block with a fixed message, so the agent never sees a hook's own question text (ADR-0073). memory-check ([ADR-0081](0081-tool-linked-memories-and-an-agent-facing-soft-confirm.md)) and fileops-check ([ADR-0048](0048-fileops-check-recheck-and-file-list.md)) block once with their own text and let the same call through the second time.
- The hook payload's `cwd` is Hermes' own directory; the terminal tool takes a per-command `workdir` (ADR-0048).
- Local notes ([ADR-0076](0076-local-notes-for-a-tool-or-category.md)) are free text for a tool's skill, not a per-repository mark a hook can read.

## Options Considered
- **A model reviews every push and post, several rounds.** Catches personal data and context a pattern cannot, but costs the user credits on every push and adds seconds to each. Rejected for the end-user product; the internal tooling of the operator's own agent is where that belongs.
- **Scan every push and post, whatever the target.** Holds pushes to the user's own servers and private repositories, where a `.env` may be exactly what they want kept. Rejected: a private place is the user's own.
- **A doctrine rule only.** The agent's own habit, with nothing that sees the commits. Kept, and not enough alone.
- **A new detector list.** Duplicates the redactor and drifts from it. Rejected: one list, the redactor's.
- **Approve on the first hit.** The ask policy replaces the question with its fixed message, so the agent could not show the user which line it is. Rejected for block once, then approve.
- **A deterministic hook on the publishing commands, reusing the redactor, only for public places (chosen).**

## Decision
- **Doctrine.** safety.md, under Always: before pushing, opening a pull request, posting an issue or comment or making a gist in a public place for the user, check what will go out for secrets and for other people's personal data; on a find, stop and show the user the line. A private repository is the user's own space; one the user says will become public is treated as public.
- **The hook** `hooks/guard/publish-check/` (`pre_tool_call`, fail closed, 10 s) reads `terminal` commands only, word by word with the SSH check's splitter (`SshWords`, [ADR-0068](0068-ssh-connections-need-a-stated-intent-and-the-persons-allow-and-deny-lists.md)), after assignments and `env`, `command`, `nohup`, `time`. Its directory is `git -C`, then a `cd` earlier in the same command, then the call's `workdir`, then the payload's `cwd`.
- **What it fires on:** `git push` (not `--dry-run` or `--delete`), and `gh issue create|comment`, `gh pr create|comment`, `gh gist create`, `gh release create`. Never on ssh, scp or rsync.
- **Public place.** A push is looked at only when its remote (the named remote's push URL, or the URL given; a plain `git push` uses `branch.<b>.pushRemote`, `remote.pushDefault`, `branch.<b>.remote`, else `origin`) is on github.com, gitlab.com, codeberg.org or bitbucket.org. An SSH host alias is read from the HostName of a plain `Host` line in `~/.ssh/config` (Include and Match are not followed). Any other remote is not looked at. On those hosts: a GitHub repository `gh repo view` calls PRIVATE or INTERNAL (4 s timeout) is not looked at; PUBLIC, no answer, gh missing, and the other three hosts count as public. A gh write uses `-R` (a host other than those four is not looked at) or the directory's repository. A gist is always looked at: a secret gist is readable by anyone with its link.
- **What is read,** until 4 seconds: for a push, `git log -p` of the pushed revisions (`HEAD`, the refspecs' sources, `--branches` for `--all`, `--mirror` and wildcards, `--tags`), excluding `--remotes=<remote>` when that remote has remote-tracking branches, otherwise everything reachable; commit messages, added lines and the names of added, renamed or copied files. For gh: `--title`, `--body`, `--notes`, `--desc`, the body or notes file, gist files and release assets (files of at most 1 MiB without NUL bytes are read; all are checked by name); `-` (stdin) reads the command text, where a here-document is.
- **What counts:** a line `Redact::secrets()` changes (read in 4096-byte pieces; a piece the regex engine cannot finish is skipped); a file name that looks like a credential file: `.env` and `.env.*` but not `.example`, `.sample`, `.template`, `.dist`, `.default(s)`; `*.pem`, `*.key`, `*.p12`, `*.pfx`; `id_rsa`, `id_dsa`, `id_ecdsa`, `id_ed25519` and their variants except `.pub`; `credentials` with no extension or a data extension; `.netrc`. Anything not read (time ran out, a file could not be read, the repository could not be told) counts like a hit. At most 20 hits are collected.
- **The hold.** On a hit the call is blocked with where it goes, up to five lines as Redact masks them, and what was not read, and the agent is told to show the user the lines and run the same command again only if they say yes. A marker `local/state/publish-check/<sha1 of session, command, hits>` (directory 0700, 7 days) is written. The same call again, with the marker, answers `approve` with `kind` `send` and `rule_key` `pikkumies-publish:<id>`, a message within `HookUnits::APPROVE_SHOWN` naming the place and the first line, so the ask policy runs it only when the user's own words cover publishing it, and never where no yes can be checked. No valid session id, or a marker that cannot be written: the block stays and the agent gives the user the command to run themselves.
- **Not done:** a mark for a private repository that is going to be public. No existing local mechanism holds one per repository; the doctrine rule covers it, and the agent treats such a repository as public. No personal-data detector: names and addresses have no deterministic form that would not hold every push with an author line; the doctrine rule covers them. No setting to turn the hook off.

## Consequences
- A secret or a credential file on its way to a public repository, issue, pull request, release or gist stops before it is published, and the user sees exactly which line, masked.
- Nothing is sent to a model; the only outside call is `gh repo view` for a GitHub remote, to the place the push is going to anyway. Pushes to private repositories and the user's own machines cost one quick `git remote get-url`.
- False hits: code that names a password variable (`password = request.password`) or a test fixture counts; the user says yes once per command and session. Pushes to private GitLab, Codeberg and Bitbucket repositories are read like public ones.
- Out of its sight: a push or post run from code, a script, an alias or `sh -c`; `gh api`; text typed in an editor or a browser (`--editor`, `--web`); a branch `gh pr create` pushes by itself; a `cd` made in an earlier call when the call has no `workdir` and Hermes' directory is another repository; a secret in a form the redactor does not know. A large first push may run out of the 4 seconds and is then held as not read.
- The `pre_tool_call` entry's timeout grows by 10 seconds, so `config.yaml` changes once at the next start; every terminal command that does not mention `git ... push` or `gh` with one of those groups costs one regex.
- Tests: `tests/PublishCheckTest.php`, with the visibility lookup stubbed, so no network is used.

## References
- ADR-0030, ADR-0044, ADR-0048, ADR-0056, ADR-0068, ADR-0073, ADR-0076, ADR-0081
- Code: `pikkumies/hooks/guard/publish-check/`, `pikkumies/lib/Redact.php`, `pikkumies/doctrine/safety.md`
- gh: `gh repo view --json visibility`; git: `git log --not --remotes=<remote>`, `git remote get-url --push`
