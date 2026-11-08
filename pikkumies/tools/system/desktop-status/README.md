# desktop-status

What the graphical side of this machine looks like to the agent, and where its name resolution goes. It exists because the server-side tools answer "how much disk, is it up, what is running" and nothing answered "which session is the user in, what is on the screens, and who answers their DNS" — the questions three of the procedures for a Linux desktop start from ([ADR-0112](../../../../docs/adr/0112-linux-desktop-procedures-and-a-read-only-desktop-status-tool.md)).

Each verb is read-only and answered from this machine now:

- `session` reads the session environment the agent inherited and the kernel's graphics devices. It answers "is there a desktop here at all", which is the first thing a frozen-screen report needs: a machine with no graphical session has no display fault.
- `outputs` walks the kernel's output connectors and decodes each connected monitor's own identity from the data it publishes. That identity, not the connector name, is what a layout must match on: behind a dock or hub the same monitor arrives under a different name, and a name-based change lands on the wrong screen or nowhere.
- `dns` reads the resolver configuration and, when the resolver exposes it, the link that owns the default-route name domain — the one fact that explains "this machine cannot reach sites the others reach" when a tunnel profile claims the whole name space but routes a narrow range.

What it deliberately does not do: it does not read or change the compositor's layout (position and scale are the compositor's own state and are reported as null with a reason), it does not count kernel log messages (that is a bounded read the procedure gives, and the count is a decision the agent makes, not a fixed menu), and it does not report peripheral firmware (that needs a device database the machine may not have). Those four were designed, reviewed, and deleted rather than built.

It reads `/sys/class/drm`, `/etc/resolv.conf` and this process's own environment. Beyond that it runs one fixed, read-only command — the resolver's own status — and nothing else.
