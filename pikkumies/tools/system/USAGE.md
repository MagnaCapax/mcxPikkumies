# tools/system

```sh
php tools/system/status.php     # JSON: os, user, root access, running apps (own user only), cpu, memory, disk, load
```

Read-only. Root is reported from what the OS allows (real uid 0 or passwordless sudo), never from configuration.
