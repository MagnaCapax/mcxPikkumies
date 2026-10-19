# docker

Manages the user's own Docker daemon and its containers: engine facts and space used, listing with who can reach each published port, a container's settings and logs, start, stop, restart, removing containers and unused images with a preview, diagnosing a daemon that does not answer or a container that keeps failing, and launching one or more containers after checking that this account can run them. It runs the `docker` CLI as the user, never as root for another account, and never through a shell string. The rules for the agent are in `../DOCTRINE.md`, with their reasons in `../README.md`; the decision record is [ADR-0034](../../../../docs/adr/0034-containers-category.md).

## Where the daemon is found

1. **The CLI:** `docker` on PATH, else `~/bin/docker`. Without it the whole `containers` category is left out of the agent's skills and SOUL.md (`Generate::unavailableCategories`), and `stats` says `cli-missing`.
2. **The socket** (`Host::dockerSocket`, shared with the *ARR and media units): a `unix://` `DOCKER_HOST`, else `/run/user/<uid>/docker.sock` (PMSS exports that address in `~/.bashrc`), and for uid 0 also `/var/run/docker.sock`. Only a socket this account owns is used.
3. **Refusals, with the reason:** `DOCKER_HOST` not a unix socket (`tcp`); `/var/run/docker.sock` owned by root for an account that is not root (`root-daemon`: a docker-group daemon is root access, and on a shared server its containers are every account's); a socket owned by another uid (`other-account`). Nothing else is tried.
4. **Every call:** `timeout <N> docker ...` with `DOCKER_HOST=unix://<that socket>` and `DOCKER_CONTEXT` removed, run through `pikkumies/lib/ToolProc.php` (an argv; stdin is /dev/null). Every verb first runs `docker version --format '{{json .}}'` with 10 s; when the daemon does not answer the verb stops with the diagnosis (no verb makes a second call to a dead daemon). Single calls get 60 s, a whole verb 150 s (the agent's terminal gives up at 180 s), the scheduled check 30 s.
5. **Output:** only `--format '{{json .}}'`, read field by field; a field docker does not give is `null` or `unknown`, never a guess.

## Code

| file | what |
|---|---|
| `docker.php` | the verbs: dispatches each to its class; a verb whose class is not there answers "not built yet" |
| `DockerCli.php` | socket, refusals, environment, time limits, the probe, and the daemon ladder |
| `DockerRefs.php` | container names and ID prefixes resolved to full IDs before any change; name and image grammar; the state read back after a change |
| `DockerExposure.php` | who can reach a published port: `public`, `local-shared`, `none` |
| `DockerOps.php` | stats, list, show, logs, start, stop, restart, remove, images, prune |
| `DockerDiagnose.php` | diagnose, the scheduled check, its state file and notice |
| `DockerLaunch.php` | launch-plan, launch |

## The daemon ladder

`diagnose` (and every verb that finds the daemon not answering) names one state, decided from the probe and from what can be read without the daemon: this account's own processes in `/proc`, the socket file, the CLI context the user's own `docker` would use, the storage driver in `~/.config/docker/daemon.json`, and (not on PMSS) the systemd linger file.

| state | when | next step |
|---|---|---|
| healthy | the probe answers | none |
| cli-context-mismatch | the probe answers, but the user's own `docker` would talk to another address | set `DOCKER_HOST` |
| second-daemon | two `dockerd` of this account | tell the user and support; stop neither |
| storage-driver-missing | the probe fails and `daemon.json` names `fuse-overlayfs`, which is not installed | support (PMSS reinstalls it on update) |
| wedged | the probe fails or times out while daemon processes of this account are alive | only on the user's yes, the provider's documented recovery (it stops every container), then check that no daemon process is left |
| linger-missing | down, not PMSS, and lingering is off for the account | the admin enables lingering |
| down | no daemon processes | PMSS: wait 5 minutes, then `systemctl --user start docker.service`, then support; elsewhere start the user's daemon |

On PMSS the advice carries `source` and `verified` from `Host::TABLE['pmss']['docker']`. The wedged recovery is a link to the PMSS page, not a command printed here: the command on that page (2026-09-22) and what the PMSS watchdog counts as a running daemon (`scripts/util/userDocker.php`, any full command line matching `dockerd-rootless`) disagree, so after a recovery the tool checks for leftover processes instead of trusting the step.

## Exposure

`DockerExposure` classifies each published binding. Every interface (docker's default for `-p 8080:80`) or a non-loopback address is `public`, unless the provider's firewall drops that port from outside (PMSS drops 8080 and 8112, `scripts/util/setupNetwork.php:121-122`), which makes it `local-shared`. Loopback is `local-shared` on a server shared with other accounts (all accounts share one network namespace on PMSS) and `none` on a server this account has to itself. It reports facts only, never whether the app has a login.

## Per-container limits

On cgroup v1, and with a rootless daemon using the cgroupfs driver (PMSS writes `native.cgroupdriver=cgroupfs` on cgroup v2 hosts), per-container `--memory`/`--cpus` are not enforced and per-container usage is not measured; `stats` then says `per_container: unavailable` and `oom` is `unknown`. The account's own limits are what binds: `Cgroup::usageAtLimit()` reads memory and process use where the limit is set (the user slice), not at the tool's own scope.

## Sources

PMSS tree at c8ba1754, read 2026-10-17: `docs/docker-help.md` (daemon started by the platform, `DOCKER_HOST`, storage drivers, troubleshooting), `scripts/lib/portManager.php:15-16` (allocator 2000-38000), `scripts/util/setupNetwork.php:121-122` (inbound 8080 and 8112 dropped). Hermes v2026.9.24: `cron/scheduler_script.py` (a cron script's interpreter comes from its extension), `tools/cronjob_job_args.py` (scripts relative to `HERMES_HOME/scripts`), `cron/scheduler.py` (no_agent: empty output is silent, a non-zero exit is an alert), `tools/terminal_tool_guards.py` (a foreground `docker compose up` is refused).
