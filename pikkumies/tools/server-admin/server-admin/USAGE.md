# server-admin

A procedure, not a tool: how to do server administration when root is available, one privileged step at a time.

```sh
php ~/pikkumies/tools/system/system-status/system-status.php show    # root.available must be true
sudo systemctl status <unit>; journalctl -u <unit> -n 100                  # read before changing
```

Changes: only what the user asked for, after saying that root will be used and why. Hidden when `root.disabled` is set in `local/config.php`.
