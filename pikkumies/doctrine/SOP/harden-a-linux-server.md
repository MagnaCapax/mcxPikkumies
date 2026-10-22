# SOP: Harden and tune a Linux server

Use when the user asks you to harden, secure or tune a server they administer as root (their own VPS or dedicated server), whatever it is for. It is not for a shared seedbox account: without root the server-admin skill stops at its first step.
This is Pulsed Media's standard. The numbers are starting points that Pulsed Media runs; size each one on this machine (its CPU threads, RAM and services) and say what you chose and why. Safety rules (doctrine/safety.md) and one-change-at-a-time (the server-admin skill) still apply.

## 1. Before you change anything

1. Read what is there: the firewall in use (`iptables -S`, `nft list ruleset`, `apf --status`), `/etc/ssh/sshd_config` and its `sshd_config.d/`, the web server and PHP versions, CPU threads (`nproc`) and RAM (`free -m`).
2. Back up every config file before you edit it.
3. Find the addresses that must never be blocked: the user's own (`echo $SSH_CLIENT`, or ask them for it in your answer), the provider's management and monitoring, and anything the user named. Keep a second SSH session open until the end.
4. Run one firewall manager only. If ufw or firewalld is active and you install APF, say so and remove the other in the same change, never both running.

## 2. SSH

- Keep root login with a password. Never disable it to "harden": that locks people out when a key is lost or a new admin needs in, and some provider and panel tools log in as root with a password. Where it is off, turn it on only when the user asks for it.
- Security comes from the password: at least 12 characters, random and unique to this server; special or non-English characters (such as ö) make it stronger still. A strong password makes brute force take far longer than anyone has: see [why fail2ban is not needed and the brute force math](https://wiki.pulsedmedia.com/wiki/Fail2ban) (sections "The brute force math", "What about key-only SSH?" and "At Pulsed Media").
- No fail2ban, no changed SSH port, no ICMP (ping) blocking. Each adds a failure point and blocks real users and the server's own administrators, for no real security gain (same page).
- Key-only login is the user's choice; never switch to it until they have logged in with a key from every place they need.
- Restrict SSH to listed addresses only when the user does not need it reachable from anywhere.
- After any change: `sshd -t`, reload, and log in from a new session before closing the old one.

## 3. Firewall: APF, and BFD only if wanted

Install and configure per [Installing Advanced Policy Firewall and Brute Force Detection](https://wiki.pulsedmedia.com/wiki/Installing_Advanced_Policy_Firewall_and_Brute_Force_Detection), with these choices:

1. Put the addresses from step 1.3 in `/etc/apf/allow_hosts.rules` first.
2. Open the ports the services on this machine use (`ss -tlnp`); a public web server keeps 80 and 443 open to everyone.
3. Start with `DEVEL_MODE="1"` (the rules clear themselves after 5 minutes), test from a new SSH session and from outside, then set `DEVEL_MODE="0"`.
4. BFD bans addresses after failed logins, so it can block the user, their office or their own other servers, the way fail2ban does. Install it only if the user wants it. Before starting it, put the step 1.3 addresses in `/usr/local/bfd/ignore.hosts`, and keep a threshold well above typing mistakes (the wiki page uses 20).
5. Do not add per-address rate limits or connection caps unless the user is under an attack you can see in the logs.

That page's advice to combine APF with fail2ban does not apply: Pulsed Media runs neither fail2ban nor its equivalents.

## 4. Web stack sizing

PHP workers: at least four times the CPU threads. A busy CPU is less harm than refused requests. Then check that it fits: workers × memory per worker (`ps -o rss` of a busy worker) stays under the RAM you leave for PHP, and the database's `max_connections` is above workers plus cron jobs. Pulsed Media's own billing host runs 128 workers on 16 threads.

- **php-fpm:** `pm = dynamic`, `pm.max_children` as above, `pm.start_servers` about a quarter of it, `pm.max_requests = 500` (recycles workers), `request_terminate_timeout` so a stuck request ends, `rlimit_files = 65535`. Check with `php-fpm<version> -t`, then reload.
- **nginx:** `worker_processes auto` (one per CPU thread; Pulsed Media's seedbox servers use a fixed 32), `worker_connections` 1024 or more, `keepalive_timeout` around 20 s, and a file limit of 65535 (`LimitNOFILE` in the service, `worker_rlimit_nofile` in nginx). Check with `nginx -t`.
- **Apache:** the event MPM with php-fpm, not mod_php. `MaxRequestWorkers` above the PHP workers (Pulsed Media: 400 with `ThreadsPerChild 25`, `ServerLimit 16`), `MaxConnectionsPerChild 10000`, `KeepAliveTimeout 5`, `Timeout 30`, `ulimit -n 65535` in `/etc/apache2/envvars`. Check with `apachectl configtest`.
- **lighttpd:** PHP through FastCGI with `max-procs` × `PHP_FCGI_CHILDREN` giving at least four PHP threads per CPU thread (Pulsed Media's seedbox servers: 6 children per process, `PHP_FCGI_MAX_REQUESTS 5000`). Check with `lighttpd -tt -f <config>`.

The front end (nginx, Apache, lighttpd) must accept more connections than PHP has workers, so requests queue instead of failing.

## 5. Kernel and network

Write settings to `/etc/sysctl.d/99-local.conf` and load them with `sysctl --system`. Pulsed Media's starting points:

- `net.core.default_qdisc = fq` and `net.ipv4.tcp_congestion_control = bbr`
- `net.core.somaxconn` 2000-8192 and `net.ipv4.tcp_max_syn_backlog` 4096-16384, higher on a busy public web server
- `net.ipv4.tcp_syncookies = 1`, `net.ipv4.tcp_tw_reuse = 1`
- `net.core.rmem_max` and `wmem_max` 16 MB (64 MB on 10 Gbit), with `tcp_rmem`/`tcp_wmem` maximums to match
- `fs.file-max` in the millions, and file limits of 65535 for the services
- `vm.swappiness` low (1-10) on a database host

## 6. Logs

Cap every log so a flood of failed logins cannot fill the disk: `maxsize` in logrotate (the wiki page uses 50M, Pulsed Media's seedbox servers use 500M for syslog) and a `SystemMaxUse` for journald. Check with `logrotate -d`.

## 7. Patch, then verify

1. Apply security updates (`apt update && apt upgrade`, or the distribution's equivalent) and turn on automatic security updates if the user agrees.
2. From a new session and from outside the server: SSH logs in, every site and service answers, `apf --status` shows the rules, and the services are active.
3. Tell the user what changed, what you left as it was and why, and where the backups are.
