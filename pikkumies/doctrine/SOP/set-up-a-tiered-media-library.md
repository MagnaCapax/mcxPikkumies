# SOP: Set up a tiered media library around a Pulsed Media seedbox

Load when the user asks for any of: "my seedbox is full, use my storage box", "stream Jellyfin from my storage box", "move old media to the storage box", "one library across seedbox and storage box", "mount my storage box", "put my library on my home server / NAS / dedicated server", "Sonarr or Radarr on another machine", "union", "mergerfs", "tiered storage". The values and the reasons are on the Pulsed Media wiki page "Tiered media storage with rclone and mergerfs" (search it first and name it in your answer); this SOP is the order of work, the decisions, the checks and the traps the page does not carry. Where the two differ, check this machine and say which you followed.

It sets up what runs on THIS machine as THIS account. You never ask the user which layout to take: read the facts, apply section 1, do it. What only the user can hold (the storage box password, a purchase, settings inside an app on another machine) is named once in your answer and everything else is done.

## 0. Read first, change nothing

Run each and keep the output in the scratch folder you were given (never the home): `quota -s` (the account's quota is the limit, never `df`: bytes and files both count), `rclone version` (sync and move need 1.66 or newer), `command -v fusermount3` and `ls -l /dev/fuse`, `rclone listremotes`, `free -m` and the plan's memory (the media stack, Jellyfin plus the apps, needs about 900 MB; the installer refuses below 1000 MiB), `crontab -l`, and what already sits in the folders you would use (`ls -A ~/media ~/mnt 2>&1 | head`). A folder that is not empty is never mounted over: pick another name and say so. The cold side is a Pulsed Media storage box when its host ends in `.pulsedmedia.com`; anything else is an outside remote.

## 1. Decide the layout from the facts

| What the user wants and has | Layout |
|---|---|
| Watch or keep files that live on a storage box, no media apps writing there | **S**: one cached mount, section 3 |
| A full or small seedbox plus a storage box, new downloads must stay fast | **T**: hot folder plus cold mount merged into one folder, sections 3 to 5 |
| The library, Jellyfin or the *arr apps live on another machine (home server, NAS, Minidedi, another seedbox) | **P**: pull copies there, section 6; never play from a mount of the seedbox |
| No storage box and nothing else to tier to | Do not invent a cold side: a storage box is the user's own purchase, say so once, and still do the hot-side work they asked for |

Facts that hold in every layout:
- Downloads, seeding, imports and hardlinks stay on the seedbox's local disk. Never point a torrent client or an indexer-driven app at a mount for downloading.
- No `--allow-other`: shared Pulsed Media servers do not enable it, and the apps run as this account, so they see the mount without it.
- Moving data between a seedbox and a storage box in the same datacenter is not counted and not throttled; whether the box is in the seedbox's datacenter cannot be read from the account, so say that the rule applies only if it is. Anything leaving for another network counts against the monthly upload (layout P included). The 3-2-1 offsite copy is a separate backup job, not this one.
- Pulsed Media does not install or support Plex on a shared seedbox (the wiki page "Plex" says dedicated server only); Jellyfin is the media server, installed by the installer ("Install Media Stack"). For Plex, put the library on a dedicated server: layout P.
- PMSS tools walk and copy the home: an update, an account move and a termination can hang, fail or copy the remote's view as real files when a live mount sits inside the home (known PMSS issues, some fixed). Before a move or when you are told of one, unmount everything (`~/tier/watchdog.sh pause`, section 4); an account arrives on the new server without its crontab, so rerun the cron block there.

## 2. The cold remote

The password is passed only as a prefix, once, and never repeated, written down, saved or put in a file (the same rule as the backup tool); it is briefly in rclone's own arguments, which other accounts cannot see on Pulsed Media servers. Say once afterwards that it already went to the model provider and the way to scrub it is to change the box password.

<!-- script:remote -->
```sh
H=STORAGE.pulsedmedia.com; P=22; USR=BOXUSER
mkdir -p ~/tier ~/.ssh && ssh-keyscan -p "$P" "$H" > ~/tier/hostkey.tmp 2>/dev/null; [ -s ~/tier/hostkey.tmp ] || { echo "no host key from $H:$P"; exit 1; }
cat ~/tier/hostkey.tmp >> ~/.ssh/known_hosts; ssh-keygen -lf ~/tier/hostkey.tmp     # show these fingerprints to the user
BOX_PW='the-password' sh -c 'rclone config create box sftp host "$0" port "$1" user "$2" pass "$BOX_PW" known_hosts_file ~/.ssh/known_hosts --obscure' "$H" "$P" "$USR"
rclone lsd box: --max-depth 1
```

If the user already has a key for the box, use `key_file ~/.ssh/KEY` and no password. Never read or print the rclone config (`rclone config show`, `cat rclone.conf`).

## 3. The cached mount (layout S, and the cold side of T)

<!-- script:mount -->
```sh
mkdir -p ~/mnt/cold ~/.cache/rclone-cold
rclone mount box:media ~/mnt/cold --daemon --vfs-cache-mode full --cache-dir ~/.cache/rclone-cold \
  --vfs-cache-max-size 50G --vfs-cache-min-free-space 10G --vfs-cache-max-age 24h \
  --vfs-read-ahead 1G --buffer-size 64M --dir-cache-time 1h
```

Size the numbers to the quota from section 0: the cache lives in the home, so it is in the account's quota (not measured on a test host without quota; check `quota -s` after the first stream); `--vfs-cache-max-size` is a quarter of the quota at most and at least twice the biggest title, `--vfs-cache-min-free-space` never below 10G. Check it worked: `mountpoint -q ~/mnt/cold && ls ~/mnt/cold | head` and `grep ' fuse.rclone ' /proc/self/mountinfo`. A mount copies slowly (one stream: 33 to 40 MB/s over a WAN and as little as 5 to 10 MB/s when the box's disks are busy, reported by users in 2026); to move a folder in bulk run `rclone copy box:media/Dir ~/media-local/Dir -P --transfers 4`, never `cp` through the mount. New files on the box show up after `--dir-cache-time`; `rclone rc` is not used (it needs an open control port).

## 4. The hot folder, the merge, the mover, the watchdog (layout T)

Hot folder `~/media-local`, cold mount `~/mnt/cold`, merged folder `~/media` (all three under the home so they share the account's quota and ownership). Everything that lands goes to the hot folder first. Pick the merge by one test:

1. **mergerfs** when the user runs Sonarr or Radarr with hardlink imports: only mergerfs passes a hardlink through to the local branch. It is not installed: download the static `static-linux_amd64` tarball of the latest release from the mergerfs GitHub releases page into the scratch folder, check its sha256 against the digest the release lists, extract it, and copy only `usr/local/bin/mergerfs` into `~/bin`. Never copy `mergerfs-fusermount` from the tarball: as a non-setuid copy it makes the mount fail, while without it mergerfs falls through to the system `fusermount3` (tested on Debian 11 with release 2.42.0). Mount: `"$HOME/bin/mergerfs" -o category.create=ff,cache.files=partial,dropcacheonclose=true,minfreespace=5G "$HOME/media-local:$HOME/mnt/cold" "$HOME/media"` (a `~` after the colon is not expanded by the shell; `minfreespace` is the free space of the hot folder's filesystem below which new files go to the next branch, so keep it under what `df ~` shows). `category.create=ff` with the local branch first is what sends new files to the hot folder; the default `pfrd` does not.
2. **rclone union** when mergerfs does not mount after that fix, or the user has no hardlink imports. It cannot hardlink: imports copy, so tell the user to switch off "Use Hardlinks instead of Copy" in Sonarr and Radarr and that each import costs the file twice until the torrent is removed. `rclone config create media union upstreams "$HOME/media-local box:media" create_policy ff action_policy epall` (never the default `epmfs`, which writes to the biggest branch: the box), then `rclone mount media: "$HOME/media" --daemon --vfs-cache-mode full --dir-cache-time 1h`.

With mergerfs the torrent folder (`~/media-local/torrents`, reached as `~/media/torrents`) and the library are both inside the merged folder, which is what lets an import hardlink. With the rclone union the torrent client saves to `~/media-local/torrents` directly (a plain local folder, never through the rclone mount) and only the library path is the merged folder; a new file written there is visible at once and reaches the hot folder about 5 seconds later (the VFS write-back delay). Set the apps' folders to those paths; Jellyfin's folder picker cannot list `/home`, type the full path `/home/USER/media/...`.

**Mover** (PHP, `~/tier/mover.php`): ships files older than the age limit with a link count of 1 (not hardlinked to a seed) out of the hot folder, never `torrents/`, only when the account is above the threshold and the cold side answers. It lists, it does not move, unless `--run` is given. A moved file has one copy, on the box, and a box is RAID, not a backup: say so in your answer, and move only when the user asked for moving. The first `--run` goes through the safety check like any move; when it is refused the user's words did not cover it, so report it as skipped. If the user's torrent client saves anywhere else under `~/media-local`, add that folder to `$keep` first.

<!-- script:mover -->
```php
<?php
// php ~/tier/mover.php [--run] [--force]   (no --run: only list what would move; --force: ignore the quota threshold)
$home = getenv('HOME'); $hot = "$home/media-local"; $dest = 'box:media'; $keep = ['torrents'];
$ageDays = 14; $percent = 85; $dir = "$home/tier"; $run = in_array('--run', $argv, true);
function note($m) { global $dir; file_put_contents("$dir/mover.log", gmdate('c') . " $m\n", FILE_APPEND); echo "$m\n"; }
@mkdir($dir, 0700, true);
if (is_file("$dir/mover.log") && filesize("$dir/mover.log") > 5000000) rename("$dir/mover.log", "$dir/mover.log.1");
$lock = fopen("$dir/mover.lock", 'c'); if (!flock($lock, LOCK_EX | LOCK_NB)) { note('another run is active'); exit(0); }
// the soft quota line of `quota -s -w`; when it cannot be read nothing moves, unless --force
if (!in_array('--force', $argv, true)) {
    $q = shell_exec('quota -s -w 2>/dev/null'); $u = ['K' => 1 / 1048576, 'M' => 1 / 1024, 'G' => 1, 'T' => 1024, '' => 1 / 1048576];
    if (!preg_match('~^\s*/\S+\s+([\d.]+)([KMGT]?)\*?\s+([\d.]+)([KMGT]?)~m', (string) $q, $m)) { note('quota unreadable, nothing moved'); exit(2); }
    $used = $m[1] * $u[$m[2]]; $limit = $m[3] * $u[$m[4]];
    if ($limit <= 0 || $used < $limit * $percent / 100) { note(sprintf('%.1f GiB used of %.1f: below %d%%, nothing moved', $used, $limit, $percent)); exit(0); }
}
exec('rclone lsf ' . escapeshellarg($dest) . ' --max-depth 1 >/dev/null 2>&1', $o, $rc); if ($rc !== 0) { note('cold side unreachable, nothing moved'); exit(3); }
$list = []; $cut = time() - $ageDays * 86400;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($hot, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $rel = substr($f->getPathname(), strlen($hot) + 1);
    if (in_array(explode('/', $rel)[0], $keep, true) || !$f->isFile() || $f->isLink()) continue;
    $s = lstat($f->getPathname());
    if ($s && $s['mtime'] < $cut && $s['nlink'] === 1) $list[] = $rel;
}
note(count($list) . ' file(s) older than ' . $ageDays . ' days with one link' . ($run ? '' : ' (dry run, add --run to move)'));
if (!$list) exit(0);
$fl = "$dir/mover.files"; file_put_contents($fl, implode("\n", $list) . "\n"); chmod($fl, 0600);
$cmd = 'rclone move ' . escapeshellarg($hot) . ' ' . escapeshellarg($dest) . ' --files-from ' . escapeshellarg($fl)
     . ' --transfers 4 --log-level INFO --log-file ' . escapeshellarg("$dir/mover.log") . ($run ? '' : ' --dry-run');
passthru($cmd, $rc); exit($rc);
```

Run it once without `--run` and read the list; the first real run is `php ~/tier/mover.php --run --force` after you made a throwaway old file of your own to move (`touch -d '30 days ago'`), then check the file is gone from `~/media-local`, present on the box (`rclone lsf box:media`), still listed under `~/media` and reads back with the same checksum. Put it on a schedule only when the user asked for automatic moving: a line `30 4 * * * php ~/tier/mover.php --run >> ~/tier/cron.log 2>&1`. A user who did not ask for scheduled moves gets the dry-run script and the sentence that says how to run it.

**Watchdog** (`~/tier/watchdog.sh`), so the mounts come back after a crash and after a reboot, and can be paused before a move or an update:

<!-- script:watchdog -->
```sh
#!/bin/sh
# watchdog.sh [pause|resume]: bring the mounts up in order when missing; "pause" unmounts them and keeps them down.
T="$HOME/tier"; mkdir -p "$T"; exec 9>"$T/watchdog.lock"; flock -n 9 || exit 0
down() { fusermount3 -u "$1" 2>/dev/null || fusermount3 -uz "$1" 2>/dev/null; }
case "$1" in pause) touch "$T/paused"; down "$HOME/media"; down "$HOME/mnt/cold"; echo paused; exit 0;; resume) rm -f "$T/paused";; esac
[ -e "$T/paused" ] && exit 0
up() { mountpoint -q "$1" || { down "$1"; [ -z "$(ls -A "$1" 2>/dev/null)" ] || { echo "$(date -u +%FT%TZ) $1 not empty, not mounted over" >>"$T/watchdog.log"; return 1; }; shift; "$@" 9>&- >>"$T/watchdog.log" 2>&1; }; }   # 9>&-: the daemon must not keep the lock
up "$HOME/mnt/cold" rclone mount box:media "$HOME/mnt/cold" --daemon --vfs-cache-mode full --cache-dir "$HOME/.cache/rclone-cold" --vfs-cache-max-size 50G --vfs-cache-min-free-space 10G --vfs-cache-max-age 24h --vfs-read-ahead 1G --buffer-size 64M --dir-cache-time 1h # cold
mountpoint -q "$HOME/mnt/cold" || { echo "$(date -u +%FT%TZ) cold mount missing, merge not started" >>"$T/watchdog.log"; exit 1; } # cold
up "$HOME/media" MERGE_COMMAND # merge
```

Replace `MERGE_COMMAND` with the merge command chosen above, all of it on that one line (mergerfs: `"$HOME/bin/mergerfs" -o category.create=ff,cache.files=partial,dropcacheonclose=true,minfreespace=5G "$HOME/media-local:$HOME/mnt/cold" "$HOME/media"`; rclone union: `rclone mount media: "$HOME/media" --daemon --vfs-cache-mode full --dir-cache-time 1h`). The rclone union reads the box itself, so with it delete the two lines ending `# cold`; layout S deletes the line ending `# merge`. Then `chmod 700 ~/tier/watchdog.sh` and install the cron lines from a file, never by piping into `crontab -`:

<!-- script:cron -->
```sh
crontab -l 2>/dev/null | grep -v '# pikkumies-tier' > ~/tier/cron.new
printf '%s\n' '@reboot sh ~/tier/watchdog.sh >> ~/tier/cron.log 2>&1 # pikkumies-tier' '*/5 * * * * sh ~/tier/watchdog.sh >> ~/tier/cron.log 2>&1 # pikkumies-tier' >> ~/tier/cron.new
cp ~/tier/cron.new ~/tier/cron.bak && crontab ~/tier/cron.new
```

Use your own account's crontab only (it keeps the mounts in the global mount namespace; do not mount from `systemctl --user` units, where the mount can exist and be invisible to the apps). After a reboot the media apps are started by Pulsed Media's own boot relaunch or by the user's `jellyfin`, `sonarr`, `radarr` aliases: whether they start before the mount is back is not tested; check `mountpoint -q ~/media` before you say the library is up.

## 5. Check before you say done

Each is a command you ran and read: a new file written to `~/media` is in `~/media-local` and not on the box (`rclone lsf box:media`); a file only on the box reads through `~/media` with the same `sha256sum` as on the box (use one larger than 1 MiB); with mergerfs `stat -c '%h' ` of a seed file and its library copy is 2 after an import; the mover dry run lists only old single-link files; `~/tier/watchdog.sh pause` leaves no `fuse.` line for the account in `/proc/self/mountinfo`, `resume` brings both back; the cron lines are present once (`crontab -l | grep -c pikkumies-tier` is 2). Report what is not tested: a reboot, the boot order of the apps, quota with the real cache in it.

## 6. Layout P: the library lives on another machine

Seedbox = acquire and seed; the other machine pulls finished files and plays them. Copy, do not mount, for playback (the wiki page "Storage Boxes" says an external mount can disconnect under sustained load); run it from the machine that holds the library (Pikkumies runs there too) or from the seedbox to a remote you configured. Every byte leaves the seedbox's network and counts against its upload allowance (same-datacenter Pulsed Media machines excepted).

<!-- script:pull -->
```sh
rclone copy seedbox:data/Completed /srv/media/incoming -P --transfers 4 --min-age 10m
```

`--min-age 10m` skips files still being written; copy into a staging folder and move into the folder the *arr app watches after a transfer ends, never copy straight into the watched folder. The seedbox copy keeps seeding. No hardlink crosses two machines, so the library copy is a real copy.

If Sonarr or Radarr run on the other machine and the torrent client stays on the seedbox: the download client is rTorrent through `https://SEEDBOX/user-NAME/rutorrent/plugins/rpc/rpc.php` on port 443 with the panel login, using the host name, never `127.0.0.1`; a 401 is the login, not the network. Add a Remote Path Mapping in Settings, Download Clients: Host = that host name, Remote Path = the folder as the seedbox reports it (`/home/NAME/...`), Local Path = where the same folder appears on the *arr machine. It is a plain find-and-replace of the path text (Servarr wiki, Sonarr Settings, "Remote Path Mappings"); if the error still shows the old path, delete and add the mapping again. Settings inside an app on the other machine are the user's: write them out exactly, once.

## What did not work
Measured on a throwaway account (rclone 1.69.1, Debian 11) unless a source is named:
- Hardlinks through an rclone mount: `ln` failed with "Function not implemented"; mergerfs passed the hardlink (link count 2).
- The rclone union left on its default `epmfs` policy: new downloads land on the biggest branch, the box (the wiki page; not retested here).
- A mover that parses `quota` without `-s`: raw block counts read as gigabytes (the wiki page; not retested). This one reads `quota -s -w`, fails closed on anything else (tested against fixtures, not against a live quota: the test host has none).
- A daemonised mount started under the watchdog's lock: the daemon keeps the lock open and every later run exits silently (reproduced; `9>&-` is the fix).
- Writing the file list to `/tmp/<fixed name>`: `/tmp` is shared by every account on a Pulsed Media server (Pikkumies' own scratch-folder hook says so; not tested).
- A `minfreespace` above the free space of the hot folder's filesystem: a new file did not land in the hot folder (reproduced with 50G on 20G free).
- Killing the mount or deleting its cache folder: unflushed writes live only there (the wiki page; not tested).
- `--delete-empty-src-dirs` on the mover: left out because an emptied series folder may look like a deleted series to Sonarr (a design reason, not tested).
- `--sftp-chunk-size 255k`, the packet-size flag the wiki page gives for faster mounts: on rclone 1.69.1 a cached mount of a 16 MiB file returned the full size with wrong bytes from byte 65537 on, and `rclone copy` of it failed with "corrupted on transfer: sizes differ", against both rclone's own test server and OpenSSH's sftp-server (uploads were not affected). Never use `--sftp-chunk-size` on a mount or a copy down, and after the first transfer of any file larger than a few MiB compare its checksum (`sha256sum` here, `rclone cat box:path | sha256sum` there).
