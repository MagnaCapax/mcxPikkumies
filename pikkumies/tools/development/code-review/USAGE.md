# code-review

The starting material for a review of a change in a git project: the project root, the project's own rules files and ADRs, whether the review falls back to the engineering principles shipped with Pikkumies, and the changed files. Read-only: it runs `git rev-parse`, `git diff --numstat` and `git status --porcelain` and reads files; it writes nothing and sends nothing anywhere.

| verb | arguments | changes | what it does |
|---|---|---|---|
| context | `[--base REF] [--path DIR] [--human]` | no | Records, in order: the project (`root`, `compared`: which change is listed, `fallback`: true when the project has none of the rules files below, `principles`: the bundled engineering principles, `principles_found`); one `rules_file` per file found, with `lines`: every root Markdown file named in capitals (`AGENTS.md`, `CONTRIBUTING.md`, `SECURITY.md`, a coding agent's instruction file) except `README.md`, `CHANGELOG.md`, `LICENSE.md`, `NOTICE.md` and `HISTORY.md`, then `.github/CONTRIBUTING.md` and `development/*.md`; `adr_dir` with `count` and the `newest` five ADR files by number; one `file` per changed path (`lines_added`, `lines_removed`, `null` for a binary file; `lines` now, `null` when deleted, binary, a symlink or over 2 MiB; `over_150`). The status line gives `changed_files` and `files_over_150`. |

```sh
R=~/pikkumies/tools/development/code-review/code-review.php
php $R context                          # here: uncommitted changes, or the last commit on a clean tree
php $R context --base main --limit 0    # everything since main, all records
php $R context --path ~/src/app --human
```

- Which change: with `--base REF`, REF to the working tree (commits since REF plus uncommitted changes) and untracked files. Without it, uncommitted changes (HEAD to the working tree, staged or not) and untracked files; on a clean tree, the last commit (`HEAD~1..HEAD`). A project without commits lists every file as new. A clean tree whose HEAD is the first commit lists nothing; give `--base`.
- Untracked files count all their lines as added. Renames show as a delete and an add.
- `--base` must name a commit; a value starting with `-` is refused, and the ref reaches git as one escaped word, so it is never read as an option or by a shell.
- The project's own git config cannot run anything through this tool: `core.fsmonitor` is forced off, external diff and textconv drivers are skipped, and `status` does not rewrite the index (`GIT_OPTIONAL_LOCKS=0`).
- Records follow the usual limit (20); the status line says `truncated` and gives `next_offset`. Use `--limit 0` for all of them (within the tool output budget) or `--offset N`.
- Not a git work tree: an error, exit 1.
