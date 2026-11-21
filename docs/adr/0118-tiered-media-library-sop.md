# ADR-0118: Setting up a tiered media library around a Pulsed Media seedbox as one SOP

## Metadata
- Author: Väinämöinen (for Aleksi Ursin / MCX)
- Date: 2026-11-21
- Status: Accepted. Follows [ADR-0108](0108-server-hardening-sop.md) and [ADR-0112](0112-linux-desktop-procedures-and-a-read-only-desktop-status-tool.md) (a procedure the operator asked for ships as one SOP plus this record) and leaves [ADR-0106](0106-hard-task-sop-memories.md) unchanged.

## Context
The operator's request, verbatim (2026-11-21): "Vcontent wiki how to setup tiered storage media powerhouse with media stack etc. In separate systems, caching the super advanced thibg people have vinvestigate thoroughly ... The uber madter elite setup, made simple. And then vplan that pikkumies has easy time to make it happen, and test it all."

What was there: the Pulsed Media wiki page "Tiered media storage with rclone and mergerfs" (documentation source `pm-wiki`, searched before any answer) carries the recipe, and the `rclone` tool has no `mount` verb by design ([ADR-0101](0101-refuse-and-report-never-ask.md): the agent decides and does, it does not ask). Nothing told the agent which layout to take from the account's facts, what a customer's PMSS account cannot do, or how to prove the result, so a request to "use my storage box for my library" fell back to a generic mount. Running the recipe's commands on a throwaway PMSS account (rclone 1.69.1, Debian 11) showed what the page does not say or gets wrong: the page's `--sftp-chunk-size 255k` makes a cached mount return wrong bytes from byte 65537 of a 16 MiB file and makes `rclone copy` of it fail, against both rclone's test server and OpenSSH's sftp-server; hardlinks do not exist through an rclone mount (only mergerfs passes them); the extracted `mergerfs-fusermount` makes the mount fail and removing it works; a daemonised mount started under the watchdog's lock keeps the lock open so every later run exits silently; and `minfreespace=50G` on a filesystem with less free space stops new files landing in the hot folder.

## Options Considered
- **Wiki page only.** Rejected: the page is the right home for the values and the reasons, and stays so, but it is not a procedure: no decision rules, no checks, and its scripts carry the faults above. The SOP links it instead of repeating its prose.
- **A tool unit (`tiered-storage`) with plan, mover and watch verbs.** Rejected for now: the cohort asking for this is small (a handful of tickets), the layouts differ by what is installed on the account, and a tool would encode one of them. The mover is the only part with real logic, and it is carried and tested as a small PHP script in the SOP; a second real use is the trigger to lift it into a unit.
- **A memory SOP (`save --kind sop`, [ADR-0106](0106-hard-task-sop-memories.md)).** Rejected: it lives on one account.
- **One shipped SOP, pointed at from the two skills that own the trigger (chosen).**

## Decision
- Add `pikkumies/doctrine/SOP/set-up-a-tiered-media-library.md`: read-only facts first, a layout table that decides without asking (S: cached mount; T: hot and cold merged with mergerfs or an rclone union; P: pull copies to a library machine, with the Remote Path Mapping rule), the Pulsed Media limits that hold in every layout (no `--allow-other`, Plex not on shared seedboxes, upload counting, update and migration tools walking into live mounts), the verified commands, the mover and watchdog scripts, and the checks to run before saying done.
- Point at it from the `rclone` skill (where "no mount verb" is stated) and the `seedbox-apps` quick list.
- Before shipping, the SOP's script blocks and commands were extracted and run verbatim on a throwaway PMSS test account, with negative controls for the lock, the host key and `minfreespace`; `tests/TieredMediaSopTest.php` keeps the shipped text parseable (markers, PHP lint, `sh -n`) and the pointers in place.

## Consequences
- A request for a storage-box library gets the same layout decision and the same checks every time, and the answer names what was not tested (a reboot, the order the apps start in, a quota with the real cache in it).
- The mergerfs path depends on deleting the extracted `mergerfs-fusermount` so the system `fusermount3` is used: the SOP records the result of the on-account run and falls back to the rclone union, with Sonarr and Radarr set to copy, when it does not mount.
- The wiki page disagrees with the tested behaviour in two places (`--sftp-chunk-size 255k` on mounts and downloads; hardlinks through an rclone union) and its mergerfs install step leads into the `mergerfs-fusermount` failure; fixing them is a wiki change, reported with the test record, and until it is made an agent reading the page meets the SOP's "Where the two differ, check this machine" rule.

## References
- `pikkumies/doctrine/SOP/set-up-a-tiered-media-library.md`, `pikkumies/tools/storage/rclone/skill.md`, `pikkumies/tools/seedbox/seedbox-apps/skill.md`, [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md).
- https://wiki.pulsedmedia.com/wiki/Tiered_media_storage_with_rclone_and_mergerfs
