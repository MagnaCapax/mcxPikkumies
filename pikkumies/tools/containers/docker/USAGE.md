# docker

The user's own Docker daemon and its containers. Only a daemon this account owns, through its unix socket: a `unix://` `DOCKER_HOST`, else `/run/user/<uid>/docker.sock`, and for root also `/var/run/docker.sock`. `tcp://`, root's daemon (for any other account) and another account's daemon are refused with the reason. Every verb probes the daemon first (10 s) and stops with the diagnosis when it does not answer; every docker call has a time limit; a whole verb stops within 150 s.

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | `[--sizes]` | no | The engine: `engine`, `server_version`, `client_version`, `rootless`, `cgroup_version`, `cgroup_driver`, `limits_enforced` (per-container limits work here: not on cgroup v1, not with a cgroupfs rootless daemon), `storage_driver`, `compose` (`plugin`, `standalone` or `none`), the provider's facts (`provider`, `source`, `verified`), space used per kind (`docker system df`; `--sizes` walks every container and is slow: after a timeout, do not repeat it), and per-container CPU and memory only when `limits_enforced`, else `per_container: unavailable`. Without a usable daemon it still answers: `docker: cli-missing`, `socket-missing`, `refused` (with `reason`: `tcp`, `root-daemon`, `other-account`) and a hint. |
| list | `[--all] [--label k=v]` | no | Running containers (`--all` adds stopped ones): `id`, `name`, `image`, `state`, `status_text`, `restart_policy`, `health`, `log_bytes` (`null` for a non-file log driver), and per published port `container_port`, `host_ip`, `host_port`, `exposure` (`public`: the internet; `local-shared`: every account on this server; `none`: only this account). |
| show | `<container>` | no | One container's settings as a fixed list of fields: image, state, ports with exposure, mounts, restart policy, log settings, labels, `env_names` (the names only; values are never printed), user, networks. Its command and arguments are never printed (they can hold passwords). Text the container sets is under `*_text` fields. |
| logs | `<container> [--lines N] [--since T]` | no | The last N log lines (default 50, at most 500), one record each: `stream` (`out` or `err`), `log_text`. `--since` takes docker's form (`10m`, `2h`, an RFC 3339 time). A container that does not exist is an error, not an empty log. |
| start | `<container>...` | yes | Starts stopped containers and reads each back: `state`, `started_at`, `exit_code`, `verified`. |
| stop | `<container>... [--expect 1]` | yes | Stops containers, waiting up to 30 s each before docker kills them, and reads each back. A container started with `--rm` is deleted by docker when it stops: stopping one needs `--expect 1` (one approval per container). |
| restart | `<container>...` | yes | Restarts containers (30 s stop wait) and reads each back. |
| remove | `<container>... --expect <n>` | deletes | Deletes stopped containers and what was written inside them (not their mounted folders or named volumes). `--expect` is how many; a running container is refused (stop it first); never forced. Prints the env-file each one used, which stays. |
| images | `[--prune-plan [--all]]` | no | Images: `id`, `repository`, `tag`, `size`, `created_at`, `in_use`. `--prune-plan`: what `prune` would remove (dangling images; `--all` every image no container uses), each with `rebuildable` (can be pulled again), and the status `expect` to pass to `prune`. |
| prune | `--expect <n> [--all]` | deletes | Removes the images `images --prune-plan` listed, one by one, never forced. The list is computed again first: a different count is refused (show the user the new plan). An image that became in use is skipped and reported. Without `--expect` it refuses and calls nothing. |
| diagnose | `[<container>] [--compose <file>] [--changes]` | no | No argument: the daemon's state (`healthy`, `down`, `wedged`, `second-daemon`, `cli-context-mismatch`, `storage-driver-missing`, `linger-missing`) with `next_step`, `effect`, `needs_user_ok`, `source`, `verified`; then every container with a problem, exposure, and inventory changes, most severe first. `<container>`: what its exit code means (137 and 143: stopped by a signal, often on purpose), restart count, `oom` (`unknown` unless limits are enforced), image architecture against the host's, files it cannot chown, the cause of "address already in use", and the last log lines (`log_tail_text`). `--compose <file>`: checks a compose file before `docker compose up` (the file's settings as a fixed list of fields, values from `.env` never shown) and lists what launch would refuse. `--changes`: the scheduled form; writes only `local/containers/state.json` and `local/notices/docker.txt`. A manual run only records that the notice was seen. |
| launch-plan | `--image <image> --name <name> [--count K] [--port P[,P]] [--volume SRC:DST[:ro][,...]] [--env-file <file>] [--host-port-base N] [--public] [--accept-shared-exposure]` | no | Checks everything a launch needs and prints `can_run`, `blockers` (each a reason it would refuse), `notes` (free quota, memory and process headroom, whether limits are enforced, who can reach each port, log caps, `mkdir -m 700 -p ...` for missing folders) and the exact `launch` line with concrete names (`web-1`, `web-2`...), host ports and mounts. Changes nothing. |
| launch | the `launch-plan` arguments | yes | Runs the line `launch-plan` printed: checks everything again, then `docker run -d` once per container, one after another, reading each back; stops at the first failure and says what was started. Past 150 s it stops with `remaining` and the line that continues. No retry, no rollback: remove what it started if the user wants. |

```sh
D=~/pikkumies/tools/containers/docker/docker.php
php $D stats --human
php $D list --all
php $D list --label com.docker.compose.project=media
php $D show jellyfin
php $D logs jellyfin --lines 100
php $D logs web-1 --since 10m
php $D restart jellyfin
php $D stop web-1 web-2
php $D start web-1 web-2
php $D diagnose
php $D diagnose jellyfin
php $D diagnose --compose ~/docker/media/compose.yaml
# run three nginx containers: plan, show the user, then the printed line
php $D launch-plan --image nginx:alpine --name web --count 3 --port 80
#   then run the exact `php $D launch ...` line it printed (concrete names, host ports and flags)
# grow: two more of web (numbering continues: web-4, web-5)
php $D launch-plan --image nginx:alpine --name web --count 2 --port 80
# shrink: stop, then remove the two newest
php $D stop web-4 web-5
php $D remove web-4 web-5 --expect 2
# a database whose password is in a file the user owns (chmod 600), never on the command line
php $D launch-plan --image mariadb:11 --name db --env-file ~/docker/db/env --volume ~/docker/db/data:/var/lib/mysql
# free space: what would go, then remove exactly that
php $D images --prune-plan
php $D prune --expect 4
```

- Containers are named by their name or by at least 4 hex digits of their ID, as `list` prints them. One unknown or ambiguous name fails the whole call and nothing changes.
- `remove` and `prune` ask the user (the delete guard): show `images --prune-plan` or the container list first, then pass the count as `--expect`.
- A port without `--public` is published on 127.0.0.1. On a shared server (PMSS) every account can reach 127.0.0.1, so a launch with a port needs `--accept-shared-exposure` once; tell the user why. `--public` publishes on every interface (the internet) and asks the user.
- Passwords go into an env file the user owns (`chmod 600`), written with the file tool, never as `--env K=V`. Only names are printed.
- Bind mounts must be folders inside the home that exist; never the home itself, `~/.ssh`, `~/pikkumies`, Docker's own folders or a socket. With `--count` above 1 each source needs `{n}` (`~/docker/web-{n}:/data`) or `:ro`.
- Images must already be on this machine: `docker pull <image>` first (the user's choice of image).
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
