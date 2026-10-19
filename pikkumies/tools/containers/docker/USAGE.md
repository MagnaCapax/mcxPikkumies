# docker

The user's own Docker daemon and its containers. Only a daemon this account owns, through its unix socket: a `unix://` `DOCKER_HOST`, else `/run/user/<uid>/docker.sock`, and for root also `/var/run/docker.sock`. `tcp://`, root's daemon (for any other account) and another account's daemon are refused with the reason. Every verb probes the daemon first (10 s) and stops with the diagnosis when it does not answer; every docker call has a time limit; a whole verb stops within 150 s.

| verb | arguments | changes | what it does |
|---|---|---|---|
| stats | `[--sizes]` | no | The engine: `engine`, `server_version`, `client_version`, `rootless`, `cgroup_version`, `cgroup_driver`, `limits_enforced` (per-container limits work here: not on cgroup v1, not with a cgroupfs rootless daemon), `storage_driver`, `compose` (`plugin`, `standalone` or `none`), the provider's facts (`provider`, `source`, `verified`), space used per kind (`docker system df`; `--sizes` walks every container and is slow: after a timeout, do not repeat it), and per-container CPU and memory only when `limits_enforced`, else `per_container: unavailable`. Without a usable daemon it still answers: `docker: cli-missing`, `socket-missing`, `refused` (with `reason`: `tcp`, `root-daemon`, `other-account`) and a hint. |
| list | `[--all] [--label k=v]` | no | Running containers (`--all` adds stopped ones): `id`, `name`, `image`, `state`, `status_text`, `restart_policy`, `health`, `log_bytes` (`null` for a non-file log driver), and per published port `container_port`, `host_ip`, `host_port`, `exposure` (`public`: the internet; `local-shared`: every account on this server; `none`: only this account). |
| show | `<container> [--record <dir>]` | no (`--record` writes one file) | One container's settings as a fixed list of fields: image, state, ports with exposure, mounts, restart policy, log settings, labels, `env_names` (the names only; values are never printed), user, networks. Its command and arguments are never printed (they can hold passwords). Text the container sets is under `*_text` fields. `--record <dir>` also writes them to `<dir>/<name>.json` (mode 600, folder 700, inside the home) with `recreate`, the `launch-plan` line that brings the container back, and `recreate_gaps`, what that line cannot carry (named volumes, its own command, env set without an env file...). A record with other settings already there is kept as `<name>.json.<its time>`, never overwritten. |
| logs | `<container> [--lines N] [--since T]` | no | The last N log lines (default 20, at most 500), one record each: `stream` (`out` or `err`), `log_text`. `--since` takes docker's form (`10m`, `2h`, an RFC 3339 time). A container that does not exist is an error, not an empty log. |
| start | `<container>...` | yes | Starts stopped containers and reads each back: `state`, `started_at`, `exit_code`, `verified`. |
| stop | `<container>... [--expect 1]` | yes | Stops containers, waiting up to 30 s each before docker kills them, and reads each back. A container started with `--rm` is deleted by docker when it stops: stopping one needs `--expect 1` (one approval per container). |
| restart | `<container>...` | yes | Restarts containers (30 s stop wait) and reads each back. |
| remove | `<container>... --expect <n>` | deletes | Deletes stopped containers and what was written inside them (not their mounted folders or named volumes). `--expect` is how many; a running container is refused (stop it first); never forced. Prints the env-file each one used, which stays. |
| images | `[--prune-plan [--all]]` | no | Images: `id`, `repository`, `tag`, `size`, `created_at`, `in_use`. `--prune-plan`: what `prune` would remove (dangling images; `--all` every image no container uses), each with `rebuildable` (can be pulled again), and the status `expect` to pass to `prune`. |
| prune | `--expect <n> [--all]` | deletes | Removes the images `images --prune-plan` listed, one by one, never forced. The list is computed again first: a different count is refused (show the user the new plan). An image that became in use is skipped and reported. Without `--expect` it refuses and calls nothing. |
| diagnose | `[<container>] [--compose <file>] [--network] [--changes]` | no | No argument: the daemon's state (`healthy`, `down`, `wedged`, `second-daemon`, `cli-context-mismatch`, `storage-driver-missing`, `linger-missing`, `disabled-for-account`) with `next_step`, `effect`, `source`, `verified`; then every container with a problem, exposure, and inventory changes, most severe first. `<container>`: what its exit code means (137 and 143: stopped by a signal, often on purpose), restart count, `oom` (`unknown` unless limits are enforced), image architecture against the host's, files it cannot chown, the cause of "address already in use", and the last log lines (`log_tail_text`). `--compose <file>`: checks a compose file before `docker compose up` (the file's settings as a fixed list of fields, values from `.env` never shown) and lists what launch would refuse. `--network`: the rootless network stack read from this account's own processes (`rootless_net`, `port_driver`, `mtu`, `host_loopback`, whether the network helper such as slirp4netns is alive: without it containers have no network), each running container's published ports against what really listens (`port-not-listening`: the port forwarder is gone; `port-taken`: another account holds the port) and the daemon's own networks; nothing is pulled or run. `--changes`: the scheduled form; writes only `local/containers/state.json` and `local/notices/docker.txt`. A manual run only records that the notice was seen. |
| launch-plan | `--image <image> --name <name> [--count K] [--port P[,P]] [--volume SRC:DST[:ro][,...]] [--env-file <file>] [--host-port-base N] [--public] [--accept-shared-exposure]` | no | Checks everything a launch needs and prints `can_run`, `blockers` (each a reason it would refuse), `notes` (free quota, memory and process headroom, whether limits are enforced, who can reach each port, log caps, `mkdir -m 700 -p ...` for missing folders) and the exact `launch` line with concrete names (`web-1`, `web-2`...), host ports and mounts. Changes nothing. Several values for one option are comma-separated (`--port 80,443`); `--port HOST:PORT` pins the host port for a single container, and the printed line uses that form. |
| launch | the `launch-plan` arguments | yes | Runs the line `launch-plan` printed: checks everything again, then `docker run -d` once per container, one after another, reading each back; stops at the first failure and says what was started. Past 150 s it stops with `remaining` and the line that continues. No retry, no rollback: remove what it started if the user wants. |
| fix-owner | `<path> --to me\|container [--container <name>]`, or `--restore <manifest>` | yes | Who owns the files of a folder a container mounts (inside the home; never the home itself, `~/.ssh`, Pikkumies or Docker's own data). `--to me`: the files the container's sub-uids own become this account's, so it can change, delete and copy them; `--to container`: what this account owns goes to the user the container runs as (its numeric user, else its PUID/PGID, else root). Every entry that changes is written with its owner to a manifest (mode 600, `local/containers/fix-owner/`) before anything changes; the change runs in a one-shot helper from the container's own image (`run --rm --pull=never --network=none --user 0:0 --entrypoint chown`), then every entry is read back: `changed`, `verified`, `not_verified`, `cause` (who the container runs as, and why the files are not the account's), `manifest`, and `undo`, the `--restore` line that puts every owner back. An image without `chown` is reported, nothing guessed. |
| expose | `<app> --port <host port> [--public] [--keep-path]`, or `<app> --remove` | yes | PMSS: puts a container's web port behind the account's web server at `/user-<user>/<app>/`, inside the panel login, with one fragment `~/.lighttpd/custom.d/pikkumies-<app>.conf` (the panel login is not passed on to the app). `--public`: `/public-<user>/<app>/`, no panel login (anyone on the internet; the app's own login must be set; asked). `--keep-path`: the app is configured for that path itself (no path rewrite). Refused: a port this account does not listen on (another account's port is never proxied), a name the platform uses, a fragment already there, a `~/www` folder of that name, a URL another fragment handles, a web server config that does not pass `lighttpd -t` with the fragment (checked on a private copy first). The platform restarts the web server within about two minutes. `--remove` deletes only a fragment this tool wrote and prints the line that writes it again. |
| dump | `<container> [--file <path>]` | yes (new files) | A MariaDB, MySQL or PostgreSQL container's databases to a new file of this account (`~/docker/dumps/<name>-<UTC time>.sql`, mode 600), by the database's own dump program inside the running container (`docker exec`); the script names the container's own password variable and never prints a value. The file goes to `.partial` first and gets its name only when the program finished and wrote its completion line; an existing file is never overwritten. `engine`, `file`, `bytes`, `complete`, `sha256`. Back the file up with the backup tools. |

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
# keep a container's settings, and the line that brings it back, beside its data
php $D show jellyfin --record ~/docker/records
# the account cannot delete files a container wrote: make them its own (the output has the undo line)
php $D fix-owner ~/docker/jellyfin/config --to me
php $D fix-owner --restore ~/pikkumies/local/containers/fix-owner/20261019-101500-1a2b3c4d.json
# an app runs as uid 1000 inside and cannot write a folder the account made
php $D fix-owner ~/docker/app/data --to container
# open a container's web page behind the panel login at /user-<you>/web/, and close it again
php $D expose web --port 38001
php $D expose web --remove
# why a published port does not answer
php $D diagnose --network
# a database container's databases to a new file in ~/docker/dumps
php $D dump db
```

- Containers are named by their name or by at least 4 hex digits of their ID, as `list` prints them. One unknown or ambiguous name fails the whole call and nothing changes.
- `remove` and `prune` ask the user (the delete guard): show `images --prune-plan` or the container list first, then pass the count as `--expect`.
- A port without `--public` is published on 127.0.0.1. On a shared server (PMSS) every account can reach 127.0.0.1, so a launch with a port needs `--accept-shared-exposure` once; tell the user why. `--public` publishes on every interface (the internet) and asks the user.
- Passwords go into an env file the user owns (`chmod 600`), written with the file tool, never as `--env K=V`. Only names are printed.
- Bind mounts must be folders inside the home that exist; never the home itself, `~/.ssh`, `~/pikkumies`, Docker's own folders or a socket. With `--count` above 1 each source needs `{n}` (`~/docker/web-{n}:/data`) or `:ro`.
- Images must already be on this machine: pull the image the user named first (`docker pull <image>`); never one they did not name.
- `fix-owner`, `expose` and `dump` do their job without asking ([ADR-0073](../../../../docs/adr/0073-no-questions-no-deferrals-one-reviewed-exception.md)): an owner change is undone by its manifest, a fragment by `expose <app> --remove`, and a dump only adds a file. `expose --public` opens the app to the internet, so it raises the guard's exposure question, which reaches the user only when their own words did not ask for it.
- Output is JSON Lines; the last line says `ok`, or gives `error` and `hint`. `--human` prints the same for people.
