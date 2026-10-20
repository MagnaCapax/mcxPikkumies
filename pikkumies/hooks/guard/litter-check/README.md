# litter-check

Keeps the Pikkumies tree tidy ([ADR-0093](../../../../docs/adr/0093-scratch-folder-and-a-tidy-pikkumies-tree.md)). The tree (`~/.mcxPikkumies`, which `~/pikkumies` links into) holds Pikkumies itself and the state it keeps in `local/` and `memory/`. When the agent tries to make a new file or folder anywhere else in it, the call is not run, and the agent is told to use its session's scratch folder (`/tmp/pk-<uid>/<random>`, for small files, see [scratch-dir](../../system/scratch-dir/README.md)) or your own folders instead.

- **Allowed:** anything under `pikkumies/local/` (settings, logs, what Pikkumies downloads for its tools, Hermes' home and skills) and `pikkumies/memory/` (lessons). The installer and `pikkumies update` write the rest as programs, not as tool calls, so they are never stopped.
- **What is read:** shell redirections and the programs that create files (`mkdir`, `touch`, `tee`, `cp`, `mv`, `ln`, `rsync`, `git clone`, `curl -o`, `wget`, `tar`, `unzip` and a few more), and Hermes' `write_file` and `patch`. A path is followed through every symlink, so a link pointing into the tree does not get around it.
- **Not looked at:** changing a file that already exists (other checks ask about the files that matter), a path built in a variable or in code, a `cd` from an earlier call when the call has no working directory, and programs that write on their own. What slips past is listed by the tree check at the start of the next session and by `pikkumies doctor`.

Developing Pikkumies itself happens in a clone of your own, not in the installed tree, which updates only by fast-forward. A safety prompt for the agent's own mistakes, not a security boundary (AGENTS.md).
