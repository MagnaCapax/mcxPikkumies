# ADR-0034: A containers category with one docker unit for the user's own daemon

## Metadata
- Author: Aleksi Ursin / MCX (with Väinämöinen)
- Date: 2026-10-17
- Status: Accepted (foundations; the verbs land in stages, each answering "not built yet" until it does). Amends ADR-0020 (dated amendment there) and adds the `docker` key to the ADR-0023 host table.

## Context
The request of 2026-10-17 asks for tools to manage rootless Docker as on PMSS: the basics, stats, diagnosis, analysis, watchdog checks, recognition of other providers, and launching a number of containers with given settings after checking that the user can run them.

Facts read for this decision (PMSS tree at c8ba1754, Hermes v2026.9.24, this repository):
- PMSS runs one rootless daemon per account and a root cron watchdog starts it every 5 minutes for accounts with Docker enabled (`etc/seedbox/config/root.cron:48`, `scripts/cron/checkRootlessDocker.php`); it skips suspended accounts, accounts with Docker off and accounts under 245 MiB of RAM (`scripts/lib/user/userConfigPolicy.php`). The watchdog does not start a daemon while any process whose command line contains `dockerd-rootless`, or an exact `dockerd`, is alive (`scripts/util/userDocker.php:240-243`).
- The PMSS help page says to wait for the watchdog or ask support when the socket fails, and documents a recovery for a wedged daemon (`docs/docker-help.md`, Troubleshooting). That command and the watchdog's process match disagree (a review of 2026-09-24 found a surviving child whose arguments name `dockerd-rootless`), so the documented step can leave the daemon down.
- All accounts on a PMSS server share one network namespace: a port on 127.0.0.1 is reachable by every account (`docs/docker-help.md`); the firewall drops inbound 8080 and 8112 (`scripts/util/setupNetwork.php:121-122`); the service-port allocator owns 2000-38000 (`scripts/lib/portManager.php:15-16`).
- Limits are set on the user's slice. On cgroup v1, and with a rootless daemon on the cgroupfs driver (which PMSS configures on cgroup v2 hosts, `docs/docker-help.md`), per-container limits are not enforced; containers share the account's limits with its torrent client.
- `Cgroup::memoryCurrent()` reads the tool's own scope, not the slice that holds the limit.
- `Cli::clean()` drops fields by name; `docker inspect` prints env values as `KEY=VALUE` list entries, which pass through it.
- Hermes runs a cron script by its file extension (`.sh`/`.bash` with bash, anything else with Python; the shebang is ignored, `cron/scheduler_script.py`), only from `HERMES_HOME/scripts` by a relative name (`tools/cronjob_job_args.py`); a no_agent job is silent on empty output and alerts on a non-zero exit (`cron/scheduler.py`); `cronjob list` shows no output and outputs rotate after 50 (`cron/jobs.py`). The terminal tool refuses a foreground `docker compose up` (`tools/terminal_tool_guards.py`).

## Options Considered
- **One unit per capability** (status, diagnose, launch...), as some categories split. Three or more skills on every turn for one daemon, two classifiers of the same daemon state.
- **One `docker` unit with thirteen verbs, code split into class files inside it.** One skill, one daemon ladder, one exposure classifier, one reference resolver; builders stay file-disjoint.
- **Wrap compose** (`compose-up`/`compose-down` verbs). Compose runs fine in the terminal; the tool checks a compose file before it is run instead.
- **Auto-heal** (restart exited containers, restart the daemon). Exited containers are the ones the user or a server move stopped (PMSS ADR-0072 keeps source containers stopped after a transfer); restarting the daemon collides with the watchdog.

## Decision
- **One unit**, `tools/containers/docker/` (skill `docker`): stats, list, show, logs, start, stop, restart, remove, images, prune, diagnose, launch-plan, launch. Not added: status, df, inspect (it is `show`), pull, compose verbs, scale, presets, a plan id, `--env K=V`, a podman adapter.
- **Only the user's own daemon** (`Host::dockerSocket`): a unix socket this account owns; for uid 0 also `/var/run/docker.sock` (then root's daemon is the user's own). `tcp://`, root's daemon for any other account and another account's daemon are refused with the reason, never used. The *ARR and media units share the rule.
- **CLI only, bounded:** every call is `timeout <N> docker ...` through `tools/storage/lib/ToolProc.php` (required from there, as the backup units do; moving the storage helpers to `pikkumies/lib` is a separate change), with `DOCKER_HOST` set and `DOCKER_CONTEXT` removed. Every verb probes first (10 s) and stops with the diagnosis instead of fanning out; 60 s per call, 150 s per verb, 30 s for the scheduled check. Only `--format '{{json .}}'` is parsed; missing fields are unknown.
- **The platform owns the PMSS daemon.** The tool never stops, restarts or kills it as a fix. Down: wait for the watchdog, then `systemctl --user start docker.service` (the customer path PMSS kept, its ADR-0066), then support. Wedged: only on the user's yes, the platform's documented recovery as a link (`Host::TABLE`), then a check that no process the watchdog counts is left.
- **Deletes follow ADR-0020:** `remove` and `prune` take `--expect`, are previewed (`list`, `images --prune-plan`), never pass `-f`, and are asked by the guard.
- **Exposure:** every published port is classified `public`, `local-shared` or `none`. A port is published on 127.0.0.1 unless `--public`; on a shared server a launch with a port needs `--accept-shared-exposure`, because 127.0.0.1 is not private there.
- **Provider facts live in `Host::TABLE`** (`docker` key, null where a provider documents none): who runs the daemon, the preferred port window, ports dropped inbound, the recovery page, with `source` and `verified`.
- **Secrets:** `show` prints a fixed list of fields and env names only; command and arguments are never printed; container text sits under `*_text` fields. Env values reach a launch only through an env file the user owns (mode 600), never on a command line.
- **Category gate:** `Generate::unavailableCategories()` leaves `containers` out of skills, the tool index and SOUL.md when no `docker` CLI is on PATH or in `~/bin`, recomputed on every run; never the daemon's health, never a config write.
- **Scheduled check, opt-in:** only when the user asks, a no_agent Hermes job runs `HERMES_HOME/scripts/pikkumies-docker-check.sh` (written by `HermesConfig::write`, one line: `docker.php diagnose --changes`) every 15 minutes. It keeps `local/containers/state.json` (written only from a healthy answer) and leaves `local/notices/docker.txt` while unseen events exist. `HermesConfig::capabilities` prints up to 2 notice lines of at most 120 bytes from `local/notices/*.txt`, counts and plain words only, files owned by the account; a manual `diagnose` marks them seen.
- **No auto-heal and no preset catalog in code.** Examples live in the skill.

## Consequences
- One skill and one set of rules for every host: PMSS, a rootless daemon elsewhere, root on a VPS. A host without Docker loses nothing and pays no SOUL.md bytes.
- The containers DOCTRINE.md costs about 1.1 KB on every turn where the CLI exists; notices at most two lines of 120 B of text. Like tool doctrine, notices count against SOUL.md's 16000-byte total, not the 8000-byte static core (which the longest capability block already nearly fills).
- Launching is two steps (plan, then the printed line) and on a shared server one extra flag; that is the cost of saying who can reach the port.
- The tool cannot see a daemon that hides from its own account; a docker-group daemon owned by root is refused even when it works, by design.
- To be added when the verbs are built and measured on a live account: wall time, CPU and memory of `stats`, `list`, `diagnose`, `diagnose --changes` and `stats --sizes`, with the client and server versions measured.

## References
- ADR-0015, ADR-0016, ADR-0019, ADR-0020, ADR-0023, ADR-0025, ADR-0029
- PMSS: `docs/docker-help.md`, `scripts/util/userDocker.php`, `scripts/cron/checkRootlessDocker.php`, ADR-0026, ADR-0027, ADR-0066, ADR-0072
