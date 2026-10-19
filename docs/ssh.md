# SSH to your other machines

When the agent connects to another machine over SSH (ssh, scp, sftp, rsync, sshfs, mosh, git with an SSH remote), three things happen ([ADR-0068](adr/0068-ssh-connections-need-a-stated-intent-and-the-persons-allow-and-deny-lists.md)):

- it says why, in the command itself;
- hosts you allowed need no question, and hosts you denied are refused;
- any other host is asked about in the chat, with the agent's reason, before the command runs.

## Saying why

The agent ends the command with an intent line:

```sh
rsync -a ~/reports/ nas.example.org:reports/ # intent: copy this week's reports to the NAS
```

The `#` makes it a shell comment, so it changes nothing about what runs; the command log keeps it with the command. Once the agent has given a reason for a host, it need not repeat it for that host in the same session. A command with no reason, and none given earlier, is refused with the format to use.

## Your lists

Two lists, sometimes called a whitelist and a blacklist:

- `ssh.allow`: hosts the agent may connect to without asking you. It still says why.
- `ssh.deny`: hosts the agent must never connect to. The deny list wins over the allow list.

An entry is a host name (`nas.example.org`), an address (`192.168.1.20`, `2001:db8::5`), or a pattern with `*` and `?` (`*.home.example`, `192.168.1.*`); `*` alone is every host. An entry matches the whole name, never a part of it: `nas` matches `nas`, not `nas.example.org` or `mynas`. Users and ports are not part of an entry. On the allow list an address pattern matches only addresses, so `10.0.0.*` never allows a host named `10.0.0.7.example.net`.

Every name a connection uses is checked: the host typed, a `-o HostName`, every jump host (`-J`, `ProxyJump`, `-W`), the ssh hop in a `ProxyCommand`, and a host in a command run on the other side (`ssh nas 'ssh other'`). Any one on the deny list refuses the connection; all of them must be on the allow list for it to go without a question. Write the name or address you actually type: the lists do not look up DNS or read `~/.ssh/config`.

## Changing them

Tell the agent, or run the configure tool yourself:

```sh
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set ssh.allow "nas.example.org,*.home.example"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set ssh.deny "*.example.net"
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php set ssh.blockUnknown on
php ~/pikkumies/tools/system/pikkumies-configure/pikkumies-configure.php review --human
```

`set` replaces the whole list, so name every entry you want to keep; `none` empties it. Who may make which change:

| change | who |
|---|---|
| adding a host to `ssh.allow` | the agent, after you answer the question in the chat |
| removing a host from `ssh.allow` | the agent may |
| adding a host to `ssh.deny` | the agent may |
| removing a host from `ssh.deny` | only you, by hand in `~/pikkumies/local/config.php` |
| `ssh.blockUnknown on` | the agent may |
| `ssh.blockUnknown off` | the agent, after you answer the question |

A change that adds and removes at once counts as the stricter of the two: swapping a deny entry for another one is yours. By hand, the settings sit in `~/pikkumies/local/config.php`:

```php
'ssh' => ['allow' => ['nas.example.org', '*.home.example'], 'deny' => ['*.example.net'], 'blockUnknown' => false],
```

Defaults: both lists empty and `blockUnknown` off. Every connection needs a reason, every host is asked about, nothing is denied.

## Strict mode

`ssh.blockUnknown on` refuses every host that is not on the allow list instead of asking. Use it with an allow list when the agent should reach only a few known machines. A deny list alone is weaker: the same machine can have several names and addresses, and the lists compare names, not where a name resolves.

## Scheduled jobs and unattended runs

Nobody can answer a question in a scheduled job, a one-shot run or an agent started by another agent, so there a question is a refusal. Put the hosts those jobs need on `ssh.allow`. When approvals are switched off (yolo), questions pass, but the deny list and strict mode still refuse.

## What it cannot see

It reads the commands the agent types. Out of its sight: a script or program that connects by itself (`./sync.sh`, `make deploy`, ansible); `git push` to a remote stored in the repository; an alias or shell function defined earlier or in `~/.bashrc`; an `Include` in `~/.ssh/config` that points outside `~/.ssh`; a name that resolves to a denied address; port forwards (`-L`, `-R`, `-D`) that reach other machines through an allowed one; the rsync daemon (`host::module`, `rsync://`) and plain network connections, which are not SSH; and commands you type yourself. A host it cannot read for certain (from a variable or a substitution, a pattern, an unusual address form) is refused, and SSH started from code the agent runs (`execute_code`) is refused: the agent is told to write the host literally in a terminal command. Writes to `~/.ssh/config` are asked, because that file decides where a host name really connects. When the reason or the command is too long to show in full in the question, the call is refused instead of half-shown.

The agent runs as your user and could edit the settings file itself, so this is a safety check against mistakes and against text that tries to steer the agent, not a lock.

## The real limit is on the other machine

What holds whatever the agent types is the other machine's `~/.ssh/authorized_keys`: give this server's key `from="<this server's address>",restrict`, and `command="..."` where one command is enough. Backups to another machine already work this way ([backup remote](../pikkumies/tools/backup/remote/README.md)).

## Backups to another machine

The deny list applies inside `backup remote` too: `setup` refuses a denied host before it connects, and `run`, `list` and `restore`, scheduled runs included, refuse once the recorded host is denied.

## More

The check is the [ssh-connect hook](../pikkumies/hooks/guard/ssh-connect/README.md). Questions in the chat: [ADR-0020](adr/0020-deletes-need-the-users-approval.md). Who may change which setting: [ADR-0059](adr/0059-settings-tiers-free-asked-and-the-persons-own.md).
