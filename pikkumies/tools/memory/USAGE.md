# tools/memory

Lessons: what you learned solving a problem, as flat markdown files.

```sh
php tools/memory/memory.php save "rtorrent fails after disk full" ~/pikkumies/local/lesson-draft.md --tags rtorrent,disk --sources "journalctl, df -h"
```

- User lessons live in `memory/` as `YYYYMMDD-slug.md` (gitignored, never touched by updates). Enabled packs may ship read-only lessons too. Search both, together with the documentation, with `php tools/docs/docs.php search "<words>"`.
- `save` refuses to overwrite and prints related lessons so you can link them with `[text](file.md)`.
- What goes where: short facts and user preferences go to Hermes memory; "how this got solved" goes here; repeatable procedures become skills.
