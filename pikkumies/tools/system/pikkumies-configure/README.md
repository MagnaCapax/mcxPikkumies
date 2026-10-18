# pikkumies-configure

Pikkumies' own settings, for people and for the agent: the agent's name, performance logging and telemetry, how long Hermes keeps ended sessions, which version updates follow, the model, the hooks, tool categories and skills. The agent makes the changes that tighten something when the user asks; changes that loosen something, cost credits or delete history get Hermes' approval prompt first (the `config-change` hook); turning a safety layer off, the provider and the terms stay with the person (ADR-0032, ADR-0059). `USAGE.md` has the table of who makes which change; every change is logged with its old value, and the person's `pikkumies configure undo` shows the last one and asks before setting it back.

The user settings can be given at install, so an install from a script asks nothing:

```sh
~/pikkumies/bin/pikkumies install --name=Helper --accept-terms --telemetry=off --perf-logging=on --retention-days=90 --channel=release
```

Without flags, an install or the first session on a terminal asks for the agent's name, and the first interactive session asks the person to accept the terms of service (https://pulsedmedia.com/terms.php) and privacy policy (https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy). Runs without a terminal (cron, scripts) are never asked and never blocked.
