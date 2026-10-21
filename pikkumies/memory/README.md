# memory

What the agent learned on this machine, as markdown files. They are yours: updates never touch them (everything here except this README is gitignored).

- `lessons/<topic>/`: how a problem got solved, one file each, and for a hard task its full process as a separate SOP (`SOP: ...`, saved with `--kind sop`) to follow for the same kind of request;
- `reference/`: facts and data worth keeping;
- `research/`: longer findings, to verify before relying on them.

Lessons saved before this layout sit directly in this directory and are still searched. The memory skill writes and searches all of it: `php ~/pikkumies/tools/memory/pikkumies/pikkumies.php` (see its USAGE.md).
