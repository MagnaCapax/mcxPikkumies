# ADR-0063: A list of common mistakes, rules for changing data, and no Python for tools the agent writes

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-19
- Status: Accepted

## Context
The operator asked on 2026-10-19 for detection of common mistakes after seeing someone say "load of 40.02 is 100% of 4 cores": about fifty of the most common ones, written from general mechanics and public facts only. Three were named: pushing IPv6 as a fix, recommending ZFS, and writing tools in Python, which keeps breaking. The same request asked the doctrine to say firmly that changing data needs care, a backup when that is feasible and sensible, and an adversarial review of anything that could destroy data.

Facts read for this decision:
- The load average is not a CPU percentage, and not only running processes: it is a damped average of runnable tasks plus tasks in uninterruptible sleep, most of them waiting on disk (`kernel/sched/loadavg.c`, proc(5)). 40 on 4 cores would be ten times the core count even if load were CPU.
- SOUL.md, sent with every turn, is built from `doctrine/{soul,voice,doctrine,safety}.md` and the tool categories' DOCTRINE.md ([ADR-0010](0010-soul-and-voice.md), [ADR-0029](0029-tool-doctrine-loaded-into-soul.md)). The new list is 53 entries and about 14 KB; about ten entries restate rules `doctrine.md` already has (causes need proof, "fixed" means checked, one check is not enough, disk failing needs health data).
- `safety.md` already forbids deleting, overwriting or truncating user data without a backup and the user's yes, and requires a `.bak` before editing a file; deletes go through the user's approval ([ADR-0020](0020-deletes-need-the-users-approval.md)). Nothing told the agent how to look at a destructive command before running it.
- The repository itself has no Python ([ADR-0003](0003-hermes-integration.md)), and the SOP for making a tool says PHP. Nothing told the agent which language to use for scripts it writes for the user's account.
- IPv6: the PMSS hosts are IPv4-only by decision (PMSS [ADR 0114](https://github.com/MagnaCapax/PMSS/blob/main/docs/adr/0114-ipv4-only-hosts-ipv6-disabled.md)): IPv4 cannot be dropped, so IPv6 only adds a second firewall, address and record set to keep correct, and per-account addresses would make accounts identifiable that now share one address.
- ZFS and double parity: RAID6 costs about six disk operations per small random write (read data and both parities, write all three), a RAID-Z vdev gives about the random-write rate of one disk, and copy-on-write plus checksums and a RAM cache cost write speed and memory. A torrent server is a random-write workload.

## Options Considered
- **The whole list in SOUL.md.** Every entry before every turn, but about 14 KB more on each call and ten entries said twice.
- **No list; add rules to `doctrine.md` instead.** Small, but fifty more rules in the core doctrine is the same cost, and most entries are cues for one kind of task, not rules for every turn.
- **A separate list read on demand, with one pointer in `doctrine.md`** (chosen). The agent reads the matching entries before it reports a number, a cause or a fix. A later check can name entries by id without the list being in SOUL.md.

## Decision
- **`pikkumies/doctrine/antipatterns.md`** lists the common mistakes, grouped by kind. Each entry is one line, `N. [id] mistake. Cue: ... Do: ...`: the id a check may name, the cue that you are making the mistake, and what to do instead. The entries sharpen the Doctrine and Safety rules and never loosen them. The file is not part of SOUL.md.
- **`doctrine.md`, Accuracy:** one bullet pointing to the list, to be read before reporting a number, a cause or a fix; a check naming an id that does not apply is answered as such and dropped.
- **`doctrine.md`, Memory:** tools, scripts, hooks and scheduled jobs the agent writes are PHP or a few lines of shell that call PHP, never Python, because the system Python varies by host, its upgrades break virtual environments and installed packages, and newer distributions refuse system-wide pip installs. A user's own Python project is the user's choice.
- **`safety.md`, a new section "Changing data"** after Never: a command that deletes, overwrites, moves, renames, truncates, re-permissions, reformats or bulk-edits data is reviewed adversarially first (what it touches, counted or dry-run; whether and how it can be undone; whether a copy exists), backed up first when that is feasible and sensible, kept to the smallest set, and checked afterwards. When no backup is possible the agent says so and asks first. The Never line on user data stays word for word; the new section ends by pointing back to it.
- **Content rule for the list:** general mechanics and public facts only, so the list holds on any server.

## Consequences
- The rules for changing data and the Python rule reach every turn (about 1.7 KB more in SOUL.md, well under the cut). The list costs nothing until it is read.
- The list is text only. An agent that does not open it gets no help from it; a check that flags entries by id is a separate decision.
- `tests/AntipatternsTest.php` fails when an entry does not parse, an id repeats, the count leaves 40 to 60, a named entry is missing, the file reaches SOUL.md, or a web address, home path, #N number or upper-case code name appears in it, and when the Never line on user data changes or the new section or either doctrine bullet goes missing.

## References
- [ADR-0010](0010-soul-and-voice.md), [ADR-0029](0029-tool-doctrine-loaded-into-soul.md): what SOUL.md contains and its size limit.
- [ADR-0020](0020-deletes-need-the-users-approval.md): deleting data needs the user's approval.
- [ADR-0003](0003-hermes-integration.md): no Python in this repository.
- PMSS [ADR 0114](https://github.com/MagnaCapax/PMSS/blob/main/docs/adr/0114-ipv4-only-hosts-ipv6-disabled.md): PMSS hosts are IPv4-only.
- Linux `kernel/sched/loadavg.c`, proc(5) (`/proc/loadavg`), and the kernel's PSI documentation (`Documentation/accounting/psi.rst`).
