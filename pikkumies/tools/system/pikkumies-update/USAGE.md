# pikkumies-update

A procedure, not a tool: it tells the agent how to update Pikkumies, what each answer of the updater means, and how Hermes is updated separately.

```sh
~/pikkumies/bin/pikkumies update      # fast-forward, regenerate the agent config and skills; never updates Hermes
~/pikkumies/bin/pikkumies run update  # Hermes' own updater, run by the user from a shell (or /update in a session)
```

- Changes: the Pikkumies checkout (fast-forward only) and the generated files under `local/`. It never touches files the user edited; it stops and lists them instead.
- The same command runs daily from the user's crontab (line tagged `# pikkumies-update`); the last run is in `local/update.log`.
