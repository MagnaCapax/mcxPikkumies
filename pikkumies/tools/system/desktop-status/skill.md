---
name: desktop-status
description: "A Linux desktop machine: which session type is running, what outputs are connected and what each monitor is, and where name resolution goes — read before diagnosing a frozen screen, a blank or missing display, a dock, or a site this machine cannot reach."
---

# Desktop status

Read this before you touch anything on a machine with a screen: it is the facts the display and name-resolution procedures start from, and it is read-only.

- `session` first. `desktop: false` means there is no graphical session here, so a frozen or blank screen is not the fault — stop and re-read the user's words. `session_type` is what the user is actually in; a fault that follows the session type points at the driver path, not at their settings.
- `outputs` before any layout change. Match a monitor by its `identity`, never by `connector`: behind a dock or hub the same monitor appears under a different connector name. A connected count of zero on a machine the user is looking at is itself the finding.
- `position` and `scale` are null with a reason. They live in the running compositor's own state, not in the kernel; read them through the compositor's own query, and never infer them.
- `dns` when the user says one machine cannot reach sites the others reach. `default_route_link` names the link that answers every query; a null with `why` means this resolver does not report it — say so rather than guessing which link wins.
- A value that could not be read is `null` with a `why`. Report it as unknown. Never fill it with a typical value.

The procedures that use these facts: `~/pikkumies/doctrine/SOP/triage-a-frozen-or-blank-desktop-session.md`, `~/pikkumies/doctrine/SOP/audit-a-desktop-against-its-own-defaults.md`, and `~/pikkumies/doctrine/SOP/triage-name-resolution-and-tunnel-dns.md`.
