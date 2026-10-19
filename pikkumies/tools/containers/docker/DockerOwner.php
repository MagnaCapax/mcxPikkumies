<?php
/**
 * DockerOwner: `fix-owner` (ADR-0034, amendment of 2026-10-19). A rootless container writes its bind folders as its
 * own user, which on the server is one of this account's sub-uids: the account then cannot change, delete or copy
 * those files (copying them as the account skips them without saying so). `--to me` makes them the account's again;
 * `--to container` hands what the account owns to the user the container runs as (so an app running as uid 1000
 * inside can write a folder the account made).
 *
 * Only a folder (or file) a container mounts, inside the home, never the home itself, ~/.ssh, Pikkumies' own folder
 * or Docker's own data. The change runs inside a one-shot helper from the container's own image (`docker run --rm
 * --pull=never --network=none --user 0:0 --entrypoint chown`): there root is this account and can set any of its
 * sub-uids; the account itself never copies or moves container data. Every entry that will change is recorded with
 * its owner first, in a manifest (mode 600, local/containers/fix-owner/), and `fix-owner --restore <manifest>`
 * puts each owner back. Nothing is asked: an owner change is undone by the manifest (ADR-0073).
 * Seams (DockerCli's, plus): gid, subuid_file, subgid_file.
 */
class DockerOwner
{
    const CHUNK = 400;           // paths per chown call
    const ARGV_BYTES = 65536;    // bytes of paths per chown call
    const SCAN_MAX = 100000;     // entries recorded at most; more is refused before anything changes
    const ROUNDS = 10;           // rounds of opening folders only the container's user could read
    const TARGET = '/pk-target'; // where the helper mounts the folder
    const HELPER_LABEL = 'pikkumies.helper';
    const SHOW_MAX = 20;         // paths listed in a record

    public static function fixOwner(DockerCli $d, array $args, array $opt)
    {
        if (isset($opt['restore'])) {
            if ($args || isset($opt['to']) || isset($opt['container'])) {
                throw new CliError('--restore takes only the manifest; nothing was changed', 'fix-owner --restore <manifest printed by fix-owner>');
            }
            return self::restore($d, $opt['restore']);
        }
        if (count($args) !== 1) {
            throw new CliError('fix-owner takes one path: a folder (or file) a container mounts; nothing was changed', 'e.g. fix-owner ~/docker/app/config --to me');
        }
        $to = isset($opt['to']) && is_string($opt['to']) ? $opt['to'] : '';
        if (!in_array($to, ['me', 'container'], true)) {
            throw new CliError('--to takes me (this account owns the files) or container (the user the container runs as); nothing was changed', 'e.g. fix-owner ~/docker/app/config --to me');
        }
        $d->requireHealthy();
        $info = self::info($d);
        $path = self::path($d, (string) $args[0], $info);
        $inv = DockerOps::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $c = self::mountedBy($inv, $path, isset($opt['container']) ? $opt['container'] : null, $to);
        $map = self::mapping($d, self::rootless($info));
        $runAs = self::runAs($c);
        if ($to === 'me') {
            $want = ['uid' => 0, 'gid' => 0];
        } elseif ($runAs['uid'] === null) {
            throw new CliError('nothing changed: ' . DockerOps::nameOf($c) . ' runs as a user named inside its image, so its number is unknown here', 'use --to me, or start the container with a numeric user (PUID/PGID for LinuxServer.io images)');
        } else {
            $want = ['uid' => $runAs['uid'], 'gid' => $runAs['gid']];
        }
        $host = ['uid' => self::toHost($map['uid'], $want['uid']), 'gid' => $want['gid'] === null ? null : self::toHost($map['gid'], $want['gid'])];
        if ($host['uid'] === null || ($want['gid'] !== null && $host['gid'] === null)) {
            throw new CliError("nothing changed: uid {$want['uid']} inside the container has no id on this server (outside this account's sub-uid range)", 'support can show the range in /etc/subuid');
        }
        $scope = function ($uid, $gid) use ($map, $host, $to) {
            if (self::toContainer($map['uid'], $uid) === null || self::toContainer($map['gid'], $gid) === null) {
                return 'unmapped';
            }
            if ($uid === $host['uid'] && ($host['gid'] === null || $gid === $host['gid'])) {
                return 'done';
            }
            return $to === 'me' || $uid === $map['uid']['self'] ? 'change' : 'other';
        };
        return self::apply($d, $path, $c, $to, $want, $host, $scope, $map, $runAs);
    }

    /** Scan, record, change, read back. */
    private static function apply(DockerCli $d, $path, array $c, $to, array $want, array $host, callable $scope, array $map, array $runAs)
    {
        $deadline = microtime(true) + max(5, $d->remaining() - 40);
        $all = [];
        $done = [];
        $locked = [];
        $count = ['other' => 0, 'unmapped' => 0];
        $queue = [['', false]];
        $manifest = self::manifestPath($d, $path);
        $target = $want['uid'] . ($want['gid'] === null ? '' : ':' . $want['gid']);
        $base = ['version' => 1, 'created_at' => gmdate('Y-m-d\TH:i:s\Z'), 'path' => $path, 'to' => $to, 'container' => DockerOps::nameOf($c),
            'container_id' => (string) $c['Id'], 'image' => (string) $c['Image'], 'target' => $want, 'host_target' => $host];
        $error = null;
        for ($round = 0; $queue; $round++) {
            $open = [];
            foreach ($queue as $q) {
                $r = self::scan($path, $scope, $deadline, self::SCAN_MAX - count($all), $q[0], $q[1]);
                foreach ($r['entries'] as $e) {
                    $all[$e[0]] = $e;
                }
                $count['other'] += $r['other'];
                $count['unmapped'] += $r['unmapped'];
                foreach ($r['locked'] as $l) {
                    if ($to === 'me' && isset($all[$l]) && $round < self::ROUNDS) {
                        $open[] = $l; // the helper makes the folder itself the account's first; then the account can read it
                    } else {
                        $locked[] = $l;
                    }
                }
                if ($r['cut']) {
                    if (!$done) {
                        throw new CliError('nothing changed: more than ' . self::SCAN_MAX . ' entries, or the time ran out, before every entry could be recorded', 'give a smaller folder inside it');
                    }
                    $error = 'the time ran out while opening folders; what changed is in the manifest';
                    break 2;
                }
            }
            if (!$open) {
                break;
            }
            self::writeManifest($manifest, $base, $all, $map);
            $error = self::chown($d, $path, (string) $c['Image'], [$target => $open]);
            foreach ($open as $l) {
                $done[$l] = true;
            }
            if ($error !== null) {
                break;
            }
            $queue = array_map(function ($l) {
                return [$l, true];
            }, $open);
        }
        $rest = array_diff_key($all, $done);
        if (!$all) {
            return [self::summary($path, $c, $to, $runAs, $host, $map, [], $count, $locked, null, 'none', null, $want)];
        }
        self::writeManifest($manifest, $base, $all, $map);
        $method = 'listed';
        if ($rest && $error === null) {
            // chown -R changes exactly the recorded entries when every folder was read and everything else in the tree
            // already has the owner (nothing left to another owner, nothing chown would fail on).
            if (!$locked && $count['other'] === 0 && $count['unmapped'] === 0) {
                $method = 'recursive';
                $error = self::chown($d, $path, (string) $c['Image'], [$target => ['']], true);
            } else {
                $error = self::chown($d, $path, (string) $c['Image'], [$target => array_keys($rest)]);
            }
        }
        return ['records' => [self::summary($path, $c, $to, $runAs, $host, $map, $all, $count, $locked, $manifest, $method, $error, $want)],
            'status' => ['changed' => count($all), 'complete' => $error === null]];
    }

    /** --restore: every recorded owner back, from the manifest alone. */
    private static function restore(DockerCli $d, $file)
    {
        $file = is_string($file) && strncmp($file, '~/', 2) === 0 ? $d->home . substr($file, 1) : $file;
        $st = is_string($file) && $file !== '' && $file[0] === '/' ? @lstat($file) : false;
        if (!$st || ($st['mode'] & 0170000) !== 0100000 || (int) $st['uid'] !== $d->uid || $st['size'] > 67108864) {
            throw new CliError('--restore needs a manifest fix-owner wrote: this account\'s own regular file; nothing was changed', 'the manifest path is in fix-owner\'s output (local/containers/fix-owner/)');
        }
        $m = json_decode((string) file_get_contents($file), true);
        if (!is_array($m) || !isset($m['version'], $m['path'], $m['entries'], $m['image']) || $m['version'] !== 1 || !is_array($m['entries']) || !is_string($m['path'])) {
            throw new CliError("$file is not a fix-owner manifest; nothing was changed", 'use the manifest path fix-owner printed');
        }
        if (realpath($m['path']) !== $m['path']) {
            throw new CliError("nothing changed: {$m['path']} is no longer there (moved or renamed)", 'move it back, then restore');
        }
        $d->requireHealthy();
        self::path($d, $m['path'], self::info($d)); // the same folders as a change: never one outside the home, keys or Docker's data
        $groups = [];
        $want = [];
        foreach ($m['entries'] as $e) {
            if (!is_array($e) || count($e) !== 5 || !is_string($e[0]) || !self::safeRel($e[0]) || !is_int($e[3]) || !is_int($e[4])) {
                throw new CliError("$file has an entry that is not a relative path with owners; nothing was changed", 'use the manifest exactly as fix-owner wrote it');
            }
            $groups[$e[3] . ':' . $e[4]][] = $e[0];
            $want[$e[0]] = [$e[1], $e[2]];
        }
        $inv = DockerOps::inventory($d, ['ps', '--all', '--format', '{{json .}}']);
        $image = (string) $m['image'];
        foreach (self::candidates($inv, $m['path']) as $hit) {
            $image = (string) $hit[0]['Image'];
            break;
        }
        if (!preg_match('/^sha256:[0-9a-f]{64}$/', $image) || DockerOps::call($d, ['image', 'inspect', '--format', '{{json .Id}}', $image], DockerCli::PROBE_SECONDS)['code'] !== 0) {
            throw new CliError('nothing changed: no container mounts this folder now and the image the manifest names is gone', 'start or create a container that mounts the folder, then restore');
        }
        $error = self::chown($d, $m['path'], $image, $groups);
        $bad = [];
        foreach ($want as $rel => $ids) {
            $s = @lstat($m['path'] . ($rel === '' ? '' : '/' . $rel));
            if (!$s || (int) $s['uid'] !== (int) $ids[0] || (int) $s['gid'] !== (int) $ids[1]) {
                $bad[] = $rel === '' ? '.' : $rel;
            }
        }
        return ['records' => [['kind' => 'fix-owner-restore', 'path' => $m['path'], 'manifest' => $file, 'restored' => count($want), 'verified' => count($want) - count($bad),
            'not_verified' => array_slice($bad, 0, self::SHOW_MAX), 'error_text' => $error]], 'status' => ['complete' => $error === null && !$bad]];
    }

    /**
     * Walks $root/$rel without following links (lstat), recording what $scope says to change: ['entries' => [[rel,
     * uid, gid]], 'locked' => folders it could not open, 'other' => entries left as they are, 'unmapped' => entries
     * no container id can name, 'cut' => more than $max entries or past $deadline]. $skipRoot: $rel itself is
     * already recorded. $stat (tests): fn(path) => lstat array or false.
     */
    public static function scan($root, callable $scope, $deadline, $max, $rel = '', $skipRoot = false, $stat = null)
    {
        $stat = $stat !== null ? $stat : function ($p) {
            clearstatcache(true, $p);
            return @lstat($p);
        };
        $out = ['entries' => [], 'locked' => [], 'other' => 0, 'unmapped' => 0, 'cut' => false];
        $stack = [[$rel, $skipRoot]];
        while ($stack) {
            list($r, $skip) = array_pop($stack);
            if (microtime(true) > $deadline || count($out['entries']) > $max) {
                $out['cut'] = true;
                return $out;
            }
            $p = $r === '' ? $root : "$root/$r";
            $st = $stat($p);
            if ($st === false) {
                continue; // gone meanwhile
            }
            if (!$skip) {
                $k = $scope((int) $st['uid'], (int) $st['gid']);
                if ($k === 'change') {
                    $out['entries'][] = [$r, (int) $st['uid'], (int) $st['gid']];
                } elseif ($k === 'other' || $k === 'unmapped') {
                    $out[$k]++;
                }
            }
            if (($st['mode'] & 0170000) !== 0040000) {
                continue;
            }
            $names = @scandir($p);
            if ($names === false) {
                $out['locked'][] = $r;
                continue;
            }
            foreach ($names as $n) {
                if ($n !== '.' && $n !== '..') {
                    $stack[] = [$r === '' ? $n : "$r/$n", false];
                }
            }
        }
        return $out;
    }

    /** One chown helper call per chunk of each group (owner => relative paths); null, or the first error. */
    private static function chown(DockerCli $d, $path, $image, array $groups, $recursive = false)
    {
        foreach ($groups as $owner => $rels) {
            $chunk = [];
            $bytes = 0;
            $rels[] = null; // flushes the last chunk
            foreach ($rels as $rel) {
                if ($rel !== null) {
                    $chunk[] = self::TARGET . ($rel === '' ? '' : '/' . $rel);
                    $bytes += strlen(end($chunk)) + 1;
                }
                if ($chunk && ($rel === null || count($chunk) >= self::CHUNK || $bytes >= self::ARGV_BYTES)) {
                    if ($d->remaining() < 10) {
                        return 'the time ran out before every entry was changed; run the same fix-owner again';
                    }
                    $argv = array_merge(['run', '--rm', '--pull=never', '--network=none', '--user=0:0', '--label=' . self::HELPER_LABEL . '=fix-owner', '--entrypoint=chown',
                        '--mount=type=bind,source=' . $path . ',target=' . self::TARGET, $image], $recursive ? ['-R'] : [], ['-h', (string) $owner], $chunk);
                    $r = DockerOps::call($d, $argv);
                    if ($r['code'] !== 0) {
                        $err = trim((string) preg_split('/\r?\n/', trim((string) $r['err']))[0]);
                        return $r['timed_out'] ? 'docker did not answer in time' : (stripos($err, 'executable file not found') !== false
                            ? 'the container\'s image has no chown program, so the owners cannot be changed with it' : substr($err !== '' ? $err : 'chown failed (exit ' . $r['code'] . ')', 0, Cli::TEXT_MAX));
                    }
                    $chunk = [];
                    $bytes = 0;
                }
            }
        }
        return null;
    }

    /** The record fix-owner prints, after reading every recorded entry back. */
    private static function summary($path, array $c, $to, array $runAs, array $host, array $map, array $all, array $count, array $locked, $manifest, $method, $error, array $want)
    {
        $bad = [];
        foreach ($all as $rel => $e) {
            $s = @lstat($path . ($rel === '' ? '' : '/' . $rel));
            if (!$s || (int) $s['uid'] !== $host['uid'] || ($host['gid'] !== null && (int) $s['gid'] !== $host['gid'])) {
                $bad[] = $rel === '' ? '.' : $rel;
            }
        }
        $name = DockerOps::nameOf($c);
        $who = $runAs['uid'] === null ? "a user named in its image" : "uid {$runAs['uid']}" . ($runAs['gid'] !== null ? ", gid {$runAs['gid']}" : '');
        $from = ['user' => 'its user setting', 'PUID' => 'its PUID/PGID setting', 'default' => 'the image default', 'name' => 'its user setting'][$runAs['from']];
        $cause = $map['uid']['rootless']
            ? "$name runs as $who inside ($from); inside a rootless container uid 0 is this account and every other uid is one of its sub-uids on the server, which this account cannot change or delete directly"
            : "$name runs as $who ($from)";
        return ['kind' => 'fix-owner', 'path' => $path, 'container' => $name, 'to' => $to, 'owner_inside' => $want['uid'] . ($want['gid'] === null ? '' : ':' . $want['gid']),
            'owner_on_server' => $host['uid'] . ($host['gid'] === null ? '' : ':' . $host['gid']), 'cause' => $cause,
            'changed' => count($all), 'verified' => count($all) - count($bad), 'not_verified' => array_slice($bad, 0, self::SHOW_MAX), 'method' => $method,
            'left_other_owner' => $count['other'], 'left_unmapped' => $count['unmapped'], 'unreadable' => array_slice(array_map(function ($l) {
                return $l === '' ? '.' : $l;
            }, $locked), 0, self::SHOW_MAX),
            'manifest' => $manifest, 'undo' => $manifest === null ? null : 'php ' . DockerLaunch::word(__DIR__ . '/docker.php') . ' fix-owner --restore ' . DockerLaunch::word($manifest),
            'error_text' => $error];
    }

    /** The manifest, written (again) before every change: each recorded entry with its owner on the server and inside. */
    private static function writeManifest($file, array $base, array $all, array $map)
    {
        $entries = [];
        foreach ($all as $e) {
            $entries[] = [$e[0], $e[1], $e[2], self::toContainer($map['uid'], $e[1]), self::toContainer($map['gid'], $e[2])];
        }
        DockerDiagnose::writeFile($file, json_encode($base + ['entries' => $entries], JSON_UNESCAPED_SLASHES) . "\n");
    }

    private static function manifestPath(DockerCli $d, $path)
    {
        return rtrim((string) $d->seams['local_dir'], '/') . '/containers/fix-owner/' . gmdate('Ymd-His') . '-' . substr(sha1($path . microtime(true)), 0, 8) . '.json';
    }

    /** The path as a real path, refused where launch would refuse the mount (outside the home, the home itself, keys, Docker's data). */
    private static function path(DockerCli $d, $p, $info)
    {
        $p = strncmp($p, '~/', 2) === 0 ? $d->home . substr($p, 1) : $p;
        $real = $p !== '' ? realpath($p) : false;
        if ($real === false) {
            throw new CliError("nothing changed: no file or folder at $p", 'give the folder a container mounts, as `show <name>` lists it');
        }
        $why = DockerDiagnose::bindRefusal($real, ['home' => DockerDiagnose::home($d), 'pikkumies' => Config::root(),
            'docker_root' => isset($info['DockerRootDir']) && is_string($info['DockerRootDir']) ? $info['DockerRootDir'] : '']);
        if ($why !== null) {
            throw new CliError("nothing changed: $real cannot be changed here: " . $why[1], 'fix-owner works on a folder inside the home that a container mounts');
        }
        return $real;
    }

    /** [[inspect, source], ...]: containers (not this tool's helpers) whose bind mount holds $path. */
    private static function candidates(array $inv, $path)
    {
        $out = [];
        foreach ($inv as $row) {
            $i = $row['inspect'];
            if (!$i || isset($i['Config']['Labels'][self::HELPER_LABEL])) {
                continue;
            }
            foreach (isset($i['Mounts']) && is_array($i['Mounts']) ? $i['Mounts'] : [] as $m) {
                $src = is_array($m) && (!isset($m['Type']) || $m['Type'] === 'bind') && isset($m['Source']) ? realpath((string) $m['Source']) : false;
                if ($src !== false && ($path === $src || DockerDiagnose::inside($path, $src))) {
                    $out[] = [$i, $src];
                    break;
                }
            }
        }
        return $out;
    }

    /** The container whose user decides the owner: the one named, the only one, or (for --to me) the first. */
    private static function mountedBy(array $inv, $path, $named, $to)
    {
        $hits = self::candidates($inv, $path);
        if ($named !== null) {
            $id = DockerRefs::resolve([(string) $named], DockerOps::refList($inv), true)[0];
            foreach ($hits as $h) {
                if ($h[0]['Id'] === $id) {
                    return $h[0];
                }
            }
            throw new CliError("nothing changed: $named does not mount $path", 'name a container that mounts it (`show <name>` lists its mounts)');
        }
        if (!$hits) {
            throw new CliError("nothing changed: no container mounts $path; fix-owner changes only folders a container mounts", '`show <name>` lists a container\'s mounts');
        }
        if ($to === 'container') {
            $users = [];
            foreach ($hits as $h) {
                $users[DockerOps::nameOf($h[0])] = json_encode(self::runAs($h[0]));
            }
            if (count(array_unique($users)) > 1) {
                throw new CliError('nothing changed: ' . implode(', ', array_keys($users)) . ' mount it and run as different users', 'name one with --container <name>');
            }
        }
        return $hits[0][0];
    }

    /**
     * The user a container runs as inside: ['uid' => int|null, 'gid' => int|null, 'from' => user|PUID|default|name].
     * A numeric --user wins; otherwise a numeric PUID (LinuxServer.io images drop to it); otherwise root. The PUID
     * value is read here and only the number is printed (it is an id, not a secret).
     */
    public static function runAs(array $i)
    {
        $user = isset($i['Config']['User']) ? trim((string) $i['Config']['User']) : '';
        if ($user !== '') {
            if (!preg_match('/^(\d+)(?::(\d+))?$/', $user, $m)) {
                return ['uid' => null, 'gid' => null, 'from' => 'name'];
            }
            return ['uid' => (int) $m[1], 'gid' => isset($m[2]) ? (int) $m[2] : null, 'from' => 'user'];
        }
        $env = [];
        foreach (isset($i['Config']['Env']) && is_array($i['Config']['Env']) ? $i['Config']['Env'] : [] as $e) {
            $kv = explode('=', (string) $e, 2);
            if (in_array($kv[0], ['PUID', 'PGID'], true) && isset($kv[1]) && ctype_digit($kv[1])) {
                $env[$kv[0]] = (int) $kv[1];
            }
        }
        if (isset($env['PUID'])) {
            return ['uid' => $env['PUID'], 'gid' => isset($env['PGID']) ? $env['PGID'] : null, 'from' => 'PUID'];
        }
        return ['uid' => 0, 'gid' => 0, 'from' => 'default'];
    }

    /** ['uid' => [self, ranges, rootless], 'gid' => [...]]: how ids inside a container map to ids on the server. */
    private static function mapping(DockerCli $d, $rootless)
    {
        $name = Detect::userName($d->uid);
        $gid = isset($d->seams['gid']) ? (int) $d->seams['gid'] : (function_exists('posix_getegid') ? posix_getegid() : getmygid());
        return [
            'uid' => ['self' => $d->uid, 'rootless' => $rootless, 'ranges' => DockerDiagnose::subuidRanges(isset($d->seams['subuid_file']) ? $d->seams['subuid_file'] : '/etc/subuid', $name, $d->uid)],
            'gid' => ['self' => $gid, 'rootless' => $rootless, 'ranges' => DockerDiagnose::subuidRanges(isset($d->seams['subgid_file']) ? $d->seams['subgid_file'] : '/etc/subgid', $name, $d->uid)],
        ];
    }

    /** Id inside -> id on the server (rootless: 0 is the account, 1.. walk the sub-id ranges in order), or null. */
    public static function toHost(array $m, $id)
    {
        if (!$m['rootless']) {
            return (int) $id;
        }
        if ((int) $id === 0) {
            return (int) $m['self'];
        }
        $off = (int) $id - 1;
        foreach ($m['ranges'] as $r) {
            $size = $r[1] - $r[0] + 1;
            if ($off < $size) {
                return $r[0] + $off;
            }
            $off -= $size;
        }
        return null;
    }

    /** Id on the server -> id inside, or null when no container id names it. */
    public static function toContainer(array $m, $id)
    {
        if (!$m['rootless']) {
            return (int) $id;
        }
        if ((int) $id === (int) $m['self']) {
            return 0;
        }
        $base = 1;
        foreach ($m['ranges'] as $r) {
            if ($id >= $r[0] && $id <= $r[1]) {
                return $base + ($id - $r[0]);
            }
            $base += $r[1] - $r[0] + 1;
        }
        return null;
    }

    private static function info(DockerCli $d)
    {
        $rows = DockerCli::jsonLines(DockerOps::call($d, ['info', '--format', '{{json .}}'])['out']);
        return $rows ? $rows[0] : [];
    }

    /** docker info names a rootless daemon in SecurityOptions. */
    private static function rootless(array $info)
    {
        return (bool) preg_grep('/(^|,)name=rootless(,|$)/', isset($info['SecurityOptions']) && is_array($info['SecurityOptions']) ? $info['SecurityOptions'] : []);
    }

    /** A relative path with no "..", no leading "/" and no NUL. */
    private static function safeRel($rel)
    {
        return $rel === '' || ($rel[0] !== '/' && strpos($rel, "\0") === false && !in_array('..', explode('/', $rel), true));
    }
}
