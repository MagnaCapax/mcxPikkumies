# pikkumies-update

A procedure, not a tool: it tells the agent how to update Pikkumies and what each answer of the updater means.

```sh
~/pikkumies/bin/pikkumies update      # fast-forward, regenerate the agent config and skills, keep Hermes on its pinned release
```

- Changes: the Pikkumies checkout (fast-forward only) and the generated files under `local/`. It never touches files the user edited; it stops and lists them instead.
- The same command runs daily from the user's crontab (line tagged `# pikkumies-update`); the last run is in `local/update.log`.
