# docker

Manages the user's own Docker daemon and its containers: engine facts and space used, listing with who can reach each published port, a container's settings and logs, start, stop, restart, removing containers and unused images with a preview, diagnosing a daemon that does not answer, a container that keeps failing or a port that does not answer, launching one or more containers after checking that this account can run them, recording a container's settings, giving the account (or the container) the files of a bind folder, putting a container's web page behind the PMSS web server, and dumping a database container to a file. It runs the `docker` CLI as the user, never as root for another account, and never through a shell string. The rules for the agent are in `../DOCTRINE.md`, with their reasons in `../README.md`; the decision record is [ADR-0034](../../../../docs/adr/0034-containers-category.md).

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
| `DockerRecord.php` | show --record: the settings and the line that recreates the container, written to a file |
| `DockerDiagnose.php` | diagnose, the scheduled check, its state file and notice |
| `DockerNetwork.php` | diagnose --network: the rootless network helper and published ports against real listeners |
| `DockerLaunch.php` | launch-plan, launch |
| `DockerOwner.php` | fix-owner: owner changes in a bind folder through a one-shot chown helper, recorded first, with --restore |
| `DockerExpose.php` | expose: a PMSS lighttpd fragment for a container's port, checked with `lighttpd -t` |
| `DockerDump.php` | dump: a database container's databases to a new file through its own dump program |

## The daemon ladder

`diagnose` (and every verb that finds the daemon not answering) names one state, decided from the probe and from what can be read without the daemon: this account's own processes in `/proc`, the socket file, the CLI context the user's own `docker` would use, the storage driver in `~/.config/docker/daemon.json`, (on PMSS) the account's own `Docker enabled` setting from `~/bin/userShowLimits`, and (not on PMSS) the systemd linger file.

| state | when | next step |
|---|---|---|
| healthy | the probe answers | none |
| cli-context-mismatch | the probe answers, but the user's own `docker` would talk to another address | set `DOCKER_HOST` |
| second-daemon | two `dockerd` of this account | tell the user and support; stop neither |
| storage-driver-missing | the probe fails and `daemon.json` names `fuse-overlayfs`, which is not installed | support (PMSS reinstalls it on update) |
| wedged | the probe fails or times out while daemon processes of this account are alive | review first (every container stops; a `--rm` container is deleted), then the provider's documented recovery; then check that no daemon process is left |
| disabled-for-account | down, PMSS, and the account's own `userShowLimits` reports `Docker enabled: false` (read only when the probe fails) | tell the user; support can say why (no self-service switch is claimed) |
| linger-missing | down, not PMSS, and lingering is off for the account | the admin enables lingering |
| down | no daemon processes | PMSS: wait 5 minutes, then `systemctl --user start docker.service`, then support; elsewhere start the user's daemon |

On PMSS the advice carries `source` and `verified` from `Host::TABLE['pmss']['docker']`. The wedged recovery is a link to the PMSS page, not a command printed here: the command on that page (2026-09-22) and what the PMSS watchdog counts as a running daemon (`scripts/util/userDocker.php`, any full command line matching `dockerd-rootless`) disagree, so after a recovery the tool checks for leftover processes instead of trusting the step.

## Exposure

`DockerExposure` classifies each published binding. Every interface (docker's default for `-p 8080:80`) or a non-loopback address is `public`, unless the provider's firewall drops that port from outside (PMSS drops 8080 and 8112, `scripts/util/setupNetwork.php:121-122`), which makes it `local-shared`. Loopback is `local-shared` on a server shared with other accounts (all accounts share one network namespace on PMSS) and `none` on a server this account has to itself. It reports facts only, never whether the app has a login.

## Owners, the web server and dumps

- **fix-owner.** In a rootless container uid 0 is the account and every other uid one of its sub-uids (`/etc/subuid`), so files an app writes as uid 1000 inside belong to sub-uid `first + 999` on the server, which the account cannot change, delete or copy (a copy as the account skips them silently: the DOCTRINE line). The change runs where it can: in a one-shot helper from the container's own image with `--user 0:0` (root there is the account), never pulled, no network. Each entry is recorded with its owner in a manifest before anything changes; a tree is changed with `chown -R` only when every folder was read and nothing in it belongs to anyone else, otherwise path by path. Folders only the container's user can read are opened first (the folder itself, recorded), then read. Every entry is read back.
- **expose.** PMSS keeps `~/.lighttpd/custom.d/*.conf` for the customer's own fragments ([PMSS ADR-0032](https://github.com/MagnaCapax/PMSS/blob/main/docs/adr/0032-system-managed-per-user-lighttpd-config-belongs-in-template.md), included by `template.lighttpd`); nginx sends `/user-<user>/` to the account's lighttpd as it is, behind the panel login, and `/public-<user>/` with that prefix taken off (`template.nginx-user`). The fragment follows PMSS's own (`lib/lighttpd/proxyFragments.php`): a URL condition, `proxy.server` on 127.0.0.1, forwarded headers, WebSocket upgrade and `map-urlpath`. The platform's lighttpd watchdog restarts the server when a fragment is newer than it (`root.cron`: every minute), so this tool never restarts it.
- **dump.** `docker exec <id> sh -c <script>`: the script refers to the container's password variables by name and passes the value to the dump program in its environment (`MYSQL_PWD`, `PGPASSWORD`), so it is on no command line. Not yet run against a live database container: the scripts follow the images' documented variables.

## Per-container limits

On cgroup v1, and with a rootless daemon using the cgroupfs driver (PMSS writes `native.cgroupdriver=cgroupfs` on cgroup v2 hosts), per-container `--memory`/`--cpus` are not enforced and per-container usage is not measured; `stats` then says `per_container: unavailable` and `oom` is `unknown`. The account's own limits are what binds: `Cgroup::usageAtLimit()` reads memory and process use where the limit is set (the user slice), not at the tool's own scope.

## Sources

PMSS tree at c8ba1754, read 2026-10-17: `docs/docker-help.md` (daemon started by the platform, `DOCKER_HOST`, storage drivers, troubleshooting), `scripts/lib/portManager.php:15-16` (allocator 2000-38000), `scripts/util/setupNetwork.php:121-122` (inbound 8080 and 8112 dropped). Hermes v2026.9.24: `cron/scheduler_script.py` (a cron script's interpreter comes from its extension), `tools/cronjob_job_args.py` (scripts relative to `HERMES_HOME/scripts`), `cron/scheduler.py` (no_agent: empty output is silent, a non-zero exit is an alert), `tools/terminal_tool_guards.py` (a foreground `docker compose up` is refused).
