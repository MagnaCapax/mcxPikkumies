# Common mistakes

Mistakes agents (and people) often make on servers. Each entry: the mistake, the cue that you are making it, and what to do instead. These sharpen the Doctrine and Safety rules; they never loosen them. The id in brackets is what a check names when it flags one.

## Numbers and metrics

On a PMSS seedbox, this account's own disk, quota, memory and traffic come from `system-status account`, not from the commands below (Doctrine, Sources and trust); the entries here are for everything else.

1. [load-is-not-cpu] Reading the load average as CPU percent. Cue: "load 40 on 4 cores is 100%" or "load is high, so the CPU is maxed". Do: load is a damped average count of tasks that are running, waiting to run, or blocked in uninterruptible sleep (usually waiting on disk), so it is not a percentage of anything (40 on 4 cores would be ten times the core count, not 100%). For CPU use busy time from /proc/stat, top or mpstat, steal time on a VM, and /proc/pressure/cpu.
2. [psi-some-vs-full] Reading pressure "some" as saturation. Cue: quoting the `some` line of /proc/pressure/io as "the disk is saturated". Do: `some` is the share of time at least one task was stalled; `full` is the share of time every non-idle task was stalled at once (the system-wide `full` line of /proc/pressure/cpu means nothing). High `some` with low `full` is waiting, not a standstill; judge saturation from `full` and from actual request latency.
3. [util-is-not-capacity] Reading disk %util as "disk maxed out". Cue: iostat %util near 100 on an SSD, NVMe or RAID set. Do: %util only says the device had requests in flight that share of the time, and devices that serve requests in parallel can do more; look at latency (await), queue depth and throughput.
4. [free-is-not-available] Reading "free" memory as what is left. Cue: low "free" in `free` output reported as "out of RAM". Do: read "available"; page cache is given back on demand. Real shortage shows as swap activity (si/so in vmstat), memory pressure and OOM kills in the kernel log. In a container or a per-user limit, the limit that applies is the cgroup's, not the machine's.
5. [df-du-mismatch] Explaining away a full disk that `du` cannot find. Cue: df says full, du says small, conclusion "corrupted" or "a bug". Do: check deleted files still held open (`lsof +L1`), files hidden under a mount point, reserved blocks, snapshots, and inodes (`df -i`).
6. [quota-is-not-disk] Mixing up the account's quota and the disk's free space. Cue: "the disk has 2 TB free, so you cannot be out of space". Do: check the account's quota and the filesystem separately; either one can be the limit.
7. [unit-mixup] Mixing units. Cue: "a 1 Gbit link gives 1 GB/s", GB and GiB used as one, a per-second rate compared with a total. Do: write every unit and convert explicitly (1 Gbit/s is 125 MB/s, about 119 MiB/s, before protocol overhead).
8. [average-hides-minority] Calling something healthy from an average. Cue: "average response time is fine, so nobody is affected". Do: look at the worst cases (maximum, percentiles, per user or per request) before saying nobody is affected.
9. [one-sample-trend] Calling one reading a trend. Cue: one `top` or one speed test, then "it is always slow". Do: sample several times or read history (sar, atop logs, the app's own stats) before claiming a pattern.
10. [unchecked-arithmetic] Numbers that were never computed. Cue: a percentage, sum, ratio or "more than" stated by eye. Do: compute it (`php -r`, `bc`), and state the claim and its opposite to check which the figures support.

## Checking your work

11. [fixed-not-retested] Saying "fixed" without repeating the check that showed the problem. Cue: "this should fix it", "fixed" right after a change. Do: rerun the same test that failed; say fixed only when it now passes.
12. [check-cannot-fail] Using a check that could not have failed. Cue: comparing a file with the copy you just made, reading back a value you just wrote, "it starts" as proof it works. Do: check the way the user sees it: the web page loads, the client answers, the download completes.
13. [exit-status-lost] Missing a failure because the exit status or error went somewhere else. Cue: `cmd 2>&1 | tail`, reading `$?` after a pipe, output that is really an error message. Do: check the status of the command that matters (`set -o pipefail`, `PIPESTATUS`) and read stderr.
14. [truncated-output-absence] Concluding "not there" from cut-off output. Cue: `| head`, `tail -n`, a grep filter, then "it is not in the list". Do: count first (`wc -l`), then search for the thing directly.
15. [absent-without-search] Saying something is not installed or does not exist after a check that could not find it. Cue: `which x` fails, so "x is not installed". Do: look where it could be (package manager, ~/.local/bin, the service list, `find`), then say what you checked.
16. [guessed-option-names] Proving an option does not exist by guessing names. Cue: "tried --x, --y and --z, all errors, so there is no such option". Do: read `--help`, the man page or the documentation.
17. [running-is-not-working] Treating "running" as "working". Cue: the service is active or the process exists, so "it works". Do: test what it does: the port answers, the page loads, the client accepts a command.
18. [config-not-loaded] Changing a config and testing the old one. Cue: edited a file, no effect, or "fixed" with no reload. Do: validate the file with the program's own check, reload it, and confirm the running process reads that file.
19. [all-from-some] Claiming all after checking some. Cue: "all users / all files / all torrents are fine" after a sample. Do: go through the whole set, or say "N of M checked".
20. [own-guess-as-evidence] Repeating your earlier guess as a finding. Cue: a summary states as fact what an earlier step only supposed. Do: recheck before the summary, or mark it unverified.
21. [done-without-receipts] A status report with nothing to check it by. Cue: "done", "all set" with no command output, path or result. Do: give each done item the check that shows it.

## Finding the cause

22. [coincidence-as-cause] Taking timing as the cause. Cue: "it broke after the update, so the update broke it". Do: find the step that links them in a log, config or the code, and say what would prove it wrong.
23. [invented-mechanism] Explaining a failure with a mechanism nobody checked. Cue: "this is probably because X saturates / times out / blocks". Do: say "I haven't found the cause yet" until a log, config or source shows it.
24. [restart-before-diagnosis] Restarting first and asking later. Cue: the first action on a failing service is a restart. Do: read its status and logs first; a restart may be the fix, but it can wipe the evidence of why it failed.
25. [proxy-for-the-real-source] Using a convenient stand-in instead of the real source. Cue: `pidof x | head -1` as "the" process, the first search hit as the answer. Do: ask the source that knows: the service manager's main PID, the pid file, the app's own status.
26. [busy-is-not-broken] Calling slow hardware broken. Cue: a slow or busy disk called "failing". Do: busy is too much work for healthy hardware; failing needs the device's own health data (SMART), errors in the kernel log, or array status.
27. [one-smart-counter-verdict] Condemning a disk from one SMART number. Cue: "pending sectors: 8, replace the disk". Do: a pending count is a reason to test, not a verdict; pending sectors are often cleared or remapped when they are written again. Check whether the count grows, run a long self-test, read the array status, and tell the owner what you found.
28. [stale-note-over-live] Trusting an old note over the machine. Cue: acting on a lesson, a doc or your earlier finding without looking now. Do: notes are leads; check the live state before acting on them.
29. [copied-advice-unchecked] Applying a forum or AI answer as it is. Cue: running a pasted command with paths, versions or service names from another system. Do: check versions, paths and service names here first.
30. [first-lines-only] Concluding from the start of a log or file. Cue: read the top of a log, the error is further down or at the time it happened. Do: search the whole window (`grep -n`, the lines around the time of the failure).
31. [inverted-logic] Getting a direction backwards. Cue: more/less, before/after, enabled/disabled, a sign or comparison stated without checking. Do: state the claim and its negation and see which the evidence supports.
32. [wrong-question] Answering a nearby question. Cue: asked "why is it slow?", answered "how to make it faster"; one phrase answered, the rest ignored. Do: restate what was asked and check the answer covers it.

## Changing things safely

33. [delete-before-backup] Deleting, overwriting or truncating before a backup exists. Cue: rm, a cleanup or a "small" overwrite with no copy. Do: back up first; when no copy is possible, follow Changing data (Safety).
34. [backup-beside-original] A backup that dies with the original. Cue: the only copy is `file.bak` on the same failing disk, or a snapshot on the same pool. Do: a `.bak` next to a config protects against a bad edit, not against losing the disk; put a backup meant to survive the disk somewhere else. Tell the user where temporary backups are, so they can remove them once the change is confirmed.
35. [blind-rerun] Rerunning a half-finished change from the start. Cue: a script or loop stopped halfway and is run again as it is. Do: find what already happened, then continue from there or make each step safe to repeat.
36. [pkill-matches-itself] Killing your own command with a pattern. Cue: `pkill -f name` (or a pgrep loop) in a command line that itself contains "name". Do: stop services through the service manager or a pid file; list matches with `pgrep -a` and check them before killing.
37. [kill-the-slow-job] Killing a job that was only slow. Cue: "it is hung" judged from elapsed time alone. Do: check progress first (output growing, `/proc/<pid>/io` counters moving, what it is waiting on).
38. [edit-live-in-place] Editing a running script, cron job or config in place. Cue: editing the file a running job is reading. Do: write a new copy, check it, then move it into place in one step; keep the old one.
39. [unknown-purpose-removal] Removing something because it looks unused. Cue: "this cron line / rule / file seems pointless, removing it". Do: find out what it does and who uses it; disable it reversibly first.
40. [security-loosened-to-work] Loosening security to make something work. Cue: chmod 777, turning off authentication or the firewall, running as root "to be sure". Do: find the real cause (Safety).
41. [retry-as-fix] Hiding a failure with retries or sleeps. Cue: a loop or a longer timeout added around something that fails. Do: find why it fails; retries belong on top of a fix, not instead of one.
42. [workaround-around-refusal] Doing by hand what a tool just refused. Cue: a tool or guard says no, and the same thing is done another way. Do: the refusal is information; read why and tell the user.
43. [capability-assumed] Assuming the machine has root, sudo, systemd for the user, or a package. Cue: commands that only work as root on a shared account. Do: check first (`id`, `command -v`, `systemctl --user status`).

## Scope and talking to the user

44. [scope-creep] Changing more than was asked. Cue: "while I am here", "might as well". Do: do what was asked; name the rest in one line, as a statement, not a question.
45. [answered-not-done] Answering a how-to about this account with steps, then offering to do them. Cue: asked "how do I make X do Y?" about software in this account, and the answer ends with "want me to?". Do: when the change can be undone, make it (back up first), check it, and show what changed and how to undo it; a question about how something works in general, or about another machine, gets the answer alone.
46. [asking-what-you-can-check] Asking the user what a read-only check can answer. Cue: "which OS are you on?", "is it running?". Do: check it yourself; ask only what only the user knows and the task cannot go on without. Otherwise take the plain default and say which you took.
47. [plan-instead-of-doing] Handing back steps when the user asked you to do it. Cue: "here are the commands you can run" after "please fix it". Do: do every step the request covers; only a step with no copy and no undo is asked about (Safety, Changing data).
48. [fixed-one-of-many] Fixing one copy of a problem that exists in several places. Cue: one config, user or vhost fixed; the same pattern sits next to it. Do: look for the same problem nearby and fix it within the request, or say where it remains.
49. [reinvented-tool] Building what already exists. Cue: a hand-written script for something the app, the OS or a Pikkumies tool already does. Do: search tools and documentation first and use what is there.
50. [given-up-early] Saying it cannot be done after one try. Cue: "not possible" or "not supported" after a single failed attempt. Do: look further (documentation, another method), then say exactly what you tried.

## Advice that sounds right and is not

51. [ipv6-as-the-fix] Pushing IPv6 as the fix. Cue: "enable IPv6" or "ask the provider for IPv6" offered for a problem on a host that works over IPv4, or a setup called incomplete without it. Do: fix the problem on the protocol the host uses. IPv6 switched off is often deliberate: IPv4 stays needed either way, so turning IPv6 on doubles the firewall, DNS and listener setup to keep correct, can make each account separately identifiable where they otherwise share one address, and a half-done setup (a stale AAAA record, no reverse DNS) sends visitors to the wrong place or gets mail rejected.
52. [zfs-or-double-parity] Recommending ZFS, or double-parity RAID such as RAID6 or RAID-Z2, for a torrent box or any other random-write workload. Cue: "switch to ZFS for checksums and snapshots", "RAID6 is safer", judged on capacity and redundancy only. Do: weigh write performance first. Copy-on-write, checksumming and a large RAM cache cost write speed and memory on a write-heavy server; RAID6 turns each small write into about six disk operations, and a RAID-Z vdev gives roughly the random-write speed of a single disk. For more redundancy prefer mirrors (RAID10); never suggest reformatting a working server as a fix.
53. [python-for-new-tools] Writing new tooling in Python. Cue: a new helper, hook or scheduled script started in Python, or `pip install` to make one work. Do: write it in PHP (always installed where Pikkumies runs) or a few lines of shell that call PHP. The system Python differs from host to host, its upgrades silently break virtual environments and installed packages, and newer distributions refuse system-wide pip installs. Use Python only when the user asks for it.

## Where files go

54. [litter-in-the-tree] Leaving scratch files in a working directory or in the Pikkumies tree. Cue: test output, screenshots, downloads or notes written to a relative path, the current project folder or ~/pikkumies, then removed (or forgotten) afterwards. Do: write them to the session's scratch folder (named on your first turn); a project folder gets only what the user asked to have there, and ~/pikkumies only Pikkumies' own state in local/.

## Disks and RAID

55. [recovery-is-assemble] Rebuilding a broken array with create. Cue: `mdadm --create` (often with `--assume-clean`) on the old members to "bring the array back", or a re-add on a failed array. Do: recovery is assemble: compare the members' Events, Update Time and Role (`mdraid status`), assemble read-only from the members at the newest Events, look at the data, then re-add the one left out. A create writes new superblocks, and with one stale or misordered member the result is garbage that a filesystem repair then makes permanent.
56. [no-signature-is-not-empty] Calling a disk blank from `blkid` or `wipefs` alone. Cue: "no filesystem signature, so it is empty" before a format, an add or a create. Do: read samples across the whole disk (`mdraid disks` counts non-zero bytes at fixed offsets) and look for an md superblock and partitions; data from an old array or a resold disk can sit where no signature starts. Name what was found and wipe only after the owner says so.
