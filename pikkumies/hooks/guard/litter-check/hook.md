---
name: litter-check
event: pre_tool_call
description: Blocks a terminal command or a write_file or patch call that would create a file or folder in the Pikkumies tree outside local/ and memory/, and names this session's scratch folder instead (ADR-0093).
failClosed: true
timeout: 3
---
Reads `terminal` commands (`LitterTargets.php`, words split by `../ssh-connect/SshWords.php`: output redirections, `mkdir`, `touch`, `tee`, `truncate`, `dd of=`, `cp`, `mv`, `install`, `ln`, `rsync`, `git clone`/`init`, `curl`, `wget`, `unzip`, `tar`, `zip`, `mktemp`, the text after `sh -c`/`bash -c`; wrappers such as `sudo -u x` skipped with their values; here-document bodies not read; a relative path from a `cd` in the same command or the call's `workdir`; a target that starts with a variable not placed) and the path of `write_file` and `patch`, with every file a V4A patch names (`lib/PatchPaths.php`; relative from the payload's `cwd`). A target that does not exist yet, whose real path (`lib/TreeLitter.php`: every symlink on the way followed, a dangling one to where it points) is inside the clone but outside `pikkumies/local/` and `pikkumies/memory/`, is blocked; a glob or an extracted archive counts by its folder. The message names the session's scratch folder (`lib/Scratch.php`) and says the refusal is the answer. Overwriting an existing file is left to config-change and guard-destructive. Fails closed: a crash, a timeout or a payload it cannot read blocks ([ADR-0093](../../../../docs/adr/0093-scratch-folder-and-a-tidy-pikkumies-tree.md)).
