# ADR-0112: Linux desktop procedures, and a read-only status tool for the graphical side

## Metadata
- Author: Väinämöinen (for Aleksi Ursin / MCX)
- Date: 2026-11-08
- Status: Accepted. Follows [ADR-0108](0108-server-hardening-sop.md) (a standard the operator asked for ships as one SOP plus this record) and leaves [ADR-0106](0106-hard-task-sop-memories.md) unchanged.

## Context
The operator's request, verbatim: "form all these issues /videate SOPS, information etc. for pikkumies and document it all on väinä, and have a väinä deepseek flash agent add this kind of stuff to pikkumies, so pikkumies can manage both a linux desktop, server etc. generalized information etc. and how to research, what issues existed etc. basicly SOPs /vinvestigate /vjoukahainen /videate /vplan /vprompt".

What was there: every shipped procedure and every tool addressed a server. `doctrine/SOP/` had seven files, all about a machine with no screen (a failing service, an array, a host upgrade, hardening). `system-status`, `performance`, `hardware/drives` and `server-admin` read quota, memory, disk, load, clients and services. Nothing read a session type, an output, or where name resolution went, and nothing said what to do when a screen freezes.

The evidence: one recorded day on a laptop with an external dock produced fifteen faults with verified root causes. Seven were in the display path, and the agent working them made four recordable mistakes — blaming an inherited configuration before falsifying it, restarting the display manager while a user was logging in, turning a tunnel on to connect by itself without reading what name space it claimed, and printing a secret by trusting a field order and then filtering it afterwards. Those are habits, not facts about one machine.

## Options Considered
- **Memory SOPs only** (`save --kind sop`, ADR-0106). Rejected: a memory SOP lives on one account. The operator asked for pikkumies to carry this for any user, which is the shipped tree.
- **One combined "desktop" SOP.** Rejected: five triggers with five different first steps in one file does two jobs and hides the second.
- **A tool unit with no script per procedure.** Rejected: the repository already has one home for a procedure (`doctrine/SOP/`), and a second would make the same thing findable in two places.
- **A new `desktop/` tool category.** Rejected: one unit does not earn a category.
- **Seven verbs on the tool** (`session`, `outputs`, `dock`, `errors`, `bluetooth`, `graphics`, `dns`). Cut to three: `dock` cannot be specified without naming a vendor's device database, `errors` would encode the diagnosis the agent is supposed to make, `bluetooth` reads one desktop's configuration format rather than machine state, and `graphics` survives only as "whatever driver is bound", which is not a verb. `outputs` and `session` cover what `graphics` was for.
- **The authoritative-source method as five lines in `doctrine.md`.** Rejected on measurement: `doctrine.md`, `safety.md`, `soul.md` and `voice.md` are concatenated into SOUL.md and paid on every turn, and the section it would have joined is 250 bytes from its test pin. It ships as an on-demand SOP instead.
- **Five shipped SOPs (chosen)**, plus a section in the existing host SOP, plus four entries in the mistakes list, plus one sentence in Safety, plus one three-verb read-only tool.

## Decision
- Add `doctrine/SOP/triage-a-frozen-or-blank-desktop-session.md`, `doctrine/SOP/audit-a-desktop-against-its-own-defaults.md`, `doctrine/SOP/triage-name-resolution-and-tunnel-dns.md`, `doctrine/SOP/move-a-user-profile-to-a-new-machine.md` and `doctrine/SOP/find-the-authoritative-source.md`.
- Add a "changing a kernel boot parameter" section to `doctrine/SOP/upgrade-a-host-and-reboot.md`, whose recovery path is a precondition, not a step: where no recovery path exists the change is not made.
- Strengthen three existing entries in `doctrine/antipatterns.md` rather than adding four: `[coincidence-as-cause]` (a theory must be run against its own disproof before it is named), `[check-cannot-fail]` (the guard whose condition reads a field that cannot answer its question) and `[copied-advice-unchecked]` (a configuration imported from another machine, read before it is trusted). The list is capped at 60 entries by its own test, which is a deliberate bound on a file the agent reads whole; the cap is respected and reported, not raised. The fifth candidate, `[pkill-matches-itself]`, already exists. The secrets mechanism is one sentence in `doctrine/safety.md`, where it is paid on every turn and therefore cannot drift out of notice.
- Add one sentence to `doctrine/safety.md`: read only allowlisted fields, because a denylist cannot know every field name in every format.
- Add the `desktop-status` tool unit with `session`, `outputs` and `dns`, read-only, JSON Lines with `--human`, and a `null` with a `why` for any value it cannot read.
- Point at each new SOP from the skill that owns its trigger, with a backticked path: the new tool for the three display and resolution procedures, the backup skill for the move, and deep research for the authoritative-source procedure.
- Describe the host rather than promise a capability, in `README.md` and `AGENTS.md`.

**The three named methods that do not port.** The request ended "basicly SOPs /vinvestigate /vjoukahainen /videate /vplan /vprompt". `investigate` ports as the authoritative-source SOP and the ladders in the other four; `challenge` ports as the four mistakes entries. `ideate`, `plan` and `prompt` do NOT port: each hands the user a menu or a plan to approve, which [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md) and [ADR-0101](0101-refuse-and-report-never-ask.md) forbid, and `prompt` duplicates doctrine.md's existing bounded-subagent rule. This is recorded here so the next agent sees the reasoning instead of re-litigating it.

## Consequences
- An agent asked about a frozen screen, strange settings, a dock, a site this machine cannot reach, or a profile move finds a procedure for that trigger, written for any distribution and any desktop.
- The tool's three verbs are the ones an agent cannot derive from a server tool. Everything else the procedures give as a command, because a command is cheaper than a verb that has to be maintained.
- `outputs` reports `position` and `scale` as null with a reason. They belong to the running compositor, and a second copy in this tool would drift from it.
- The new SOPs add nothing to SOUL.md, so they cost nothing per turn; the one added sentence in `safety.md` is paid on every turn and was written to fit.
- Nothing here runs against a real desktop: the repository has no Linux-desktop test machine. The procedures were checked by walking them against the recorded evidence, and that limit is stated rather than papered over.

## References
- `pikkumies/doctrine/SOP/`, `pikkumies/doctrine/{antipatterns,safety}.md`, `pikkumies/tools/system/desktop-status/`, `pikkumies/tools/{server-admin/server-admin,backup,research/deep-research}/skill.md`, `README.md`, `AGENTS.md`.
- [ADR-0106](0106-hard-task-sop-memories.md), [ADR-0108](0108-server-hardening-sop.md), [ADR-0073](0073-no-questions-no-deferrals-one-reviewed-exception.md), [ADR-0101](0101-refuse-and-report-never-ask.md), [ADR-0016](0016-jsonl-is-the-default-output.md), [ADR-0017](0017-human-output-option.md).
