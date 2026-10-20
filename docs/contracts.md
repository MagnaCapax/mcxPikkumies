# Eternal interface contract

Install root: `~/.mcxPikkumies/pikkumies`; state is under its `local/`. The host opt-in is `/etc/seedbox/config/pikkumiesEternals.enabled`. The tmux session is `pikkumies`, with windows `pm1` through `pm9`. The exact resident invocation is:

```text
pikkumies run --eternal pm1
```

A host process probe may match the `run --eternal pmN` words. Record path: `local/eternals/pmN`; fields: `sessionId`, `fails`, `lastStart`. `pikkumies eternal list --json` returns objects with `id` and `state`. Every Pikkumies tmux call uses `TMUX_TMPDIR=local/tmux` and `TMPDIR=local/tmp` (both mode 0700) and clears an inherited `TMUX`. `pikkumies ask` takes stdin and returns status 0 sent, 1 error, 2 setup unfinished, 3 approval prompt, 4 rate cap, 5 Primary starting, or 6 Primary busy. It pastes only at a verified empty Primary input prompt; unknown screens are busy.

If the visible input line already contains unsent text, `ask` returns 3. A multiline or otherwise unrecognized editor state stays busy (6).

`pikkumies ui start` uses `/opt/pikkumies-agentboard/agentboard` and a socket at `local/ui/ui.sock`. Each start gets `local/ui/<ui>/` (a unique `ui-<random>` directory) with `AGENTBOARD_DATA_DIR` set to it, `LOG_FILE=<ui>/agentboard.log`, and `AGENTBOARD_TMUX_PID_FILE=<ui>/tmux-server.pid`. The UI process also inherits the private tmux and temp directories. Shared single-use tokens remain in `local/ui/tokens/`.

See [ADR-0075](adr/0075-eternals-primary-ask-and-ui.md) for the lifecycle decision.
