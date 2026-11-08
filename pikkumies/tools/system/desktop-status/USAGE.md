# desktop-status

Read-only facts about the graphical side of this machine and about where name resolution goes. Nothing is written and nothing goes over the network.

| verb | arguments | changes | what it does |
|---|---|---|---|
| session | `[--human]` | no | One record: whether a graphical session is present at all (`desktop`), the `session_type`, the desktop and session names, whether a display server is reachable, how many graphics cards the kernel reports, and how many outputs are connected. A missing environment value is null with a `why`. |
| outputs | `[--human]` | no | One record per connected output: the `connector` name, its `status`, the monitor's `identity` decoded from its own display data (`vendor`, `product`, `serial`), the number of `modes` the kernel lists, and `enabled`. `position` and `scale` are null with a `why`: they belong to the running compositor. When nothing is connected, one record says so instead. |
| dns | `[--human]` | no | One record: the `nameservers` and `search_domains` in use, and `default_route_link`, the link that answers every query, when the resolver reports it. A null `default_route_link` carries a `why`. |

```sh
php ~/pikkumies/tools/system/desktop-status/desktop-status.php session
php ~/pikkumies/tools/system/desktop-status/desktop-status.php outputs --human
php ~/pikkumies/tools/system/desktop-status/desktop-status.php dns
```

- Every verb exits 0 with `{"ok":true}` on success. An unknown verb or option exits 2 and runs nothing.
- A value that could not be read is `null` with a `why`, never `0` and never a guess.
- `identity` is null when the output reports no readable display data, which is normal for a virtual machine's virtual screen.
- Commands are limited to the resolver's own status output, run with a fixed argument list and read-only; nothing else is executed.
