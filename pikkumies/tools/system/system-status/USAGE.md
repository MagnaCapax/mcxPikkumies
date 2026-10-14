# system-status

Read-only snapshot of this machine. Nothing is written and nothing goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| show | `[--human]` | no | One record: OS, user, host family, root access, running apps of this user only, CPUs, memory, home disk free and total, load average. |

```sh
php ~/pikkumies/tools/system/system-status/system-status.php show            # JSON Lines for the agent
php ~/pikkumies/tools/system/system-status/system-status.php show --human    # for people
```

- `root.available` comes from what the OS allows (real uid 0 or passwordless sudo), never from configuration.
- `host` is `pmss`, a known seedbox host family (`feral`, `whatbox`, `ultra`, ...), or `generic` (ADR-0019).
- `apps` lists only the current user's processes.
