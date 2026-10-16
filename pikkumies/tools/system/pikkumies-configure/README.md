# pikkumies-configure

Pikkumies' own settings, for people and for the agent: the agent's name, whether performance logging and telemetry are allowed, how long Hermes keeps ended sessions, and whether the terms were accepted. The agent may change the first four when the user asks; the terms and every other setting stay with the person (ADR-0032).

The same settings can be given at install, so an install from a script asks nothing:

```sh
~/pikkumies/bin/pikkumies install --name=Helper --accept-terms --telemetry=off --perf-logging=on --retention-days=90
```

Without flags, an install or the first session on a terminal asks for the agent's name, and the first interactive session asks the person to accept the terms of service (https://pulsedmedia.com/terms.php) and privacy policy (https://wiki.pulsedmedia.com/index.php/Pulsed_Media_Privacy_Policy). Runs without a terminal (cron, scripts) are never asked and never blocked.
