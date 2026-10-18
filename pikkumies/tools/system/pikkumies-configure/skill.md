---
name: pikkumies-configure
description: Show, review and change Pikkumies' own settings (the agent's name, also "from now on call yourself X", performance logging, telemetry, session history retention).
---

# Configure Pikkumies

Use it when the user asks about Pikkumies' own settings: what the agent is called, whether it may log or send performance data, how long session history is kept.

- `review` first when the user wants to know what is set: it explains every setting and warns about the ones that need a look (for example session history kept forever).
- `set` changes only `agent.name`, `privacy.perfLogging`, `privacy.telemetry` and `sessions.retentionDays`, and only when the user asked for that change. Say what you set; it takes effect when the next session starts.
- "From now on call yourself Kalle" (or "always", "your name is") asks for a lasting name: `set agent.name Kalle`, then say it applies from the next session. Answering as Kalle only in this chat is not what was asked.
- Never accept the terms of service or privacy policy for the user: `terms.accepted` is refused here, so do not run `set terms.accepted` at all, not even to see the refusal (`review` already shows `settable: false`). The person accepts them when the first interactive session asks, or installs with `pikkumies install --accept-terms`.
- Every other setting (provider, tools, hooks, root, cron, auto-update, Hermes) is the person's: the tool refuses it. Tell them it lives in `~/pikkumies/local/config.php`; do not edit that file yourself.
- `privacy.perfLogging` (on by default) keeps performance logs on this machine only: today the memory searches (the words searched and how many results each found) and lesson saves, under `~/pikkumies/local/`. Telemetry is a setting only: nothing is sent, because no sender exists yet. Say so if asked.
