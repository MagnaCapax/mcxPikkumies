<?php
/**
 * lib/Detect.php: probes DetectTest.php does not already cover (osRelease/userName/runningApps
 * shape+ordering, resources() delegation to Quota, rootAccess shape+contract, primaryIp(), the
 * cached() update.log contract and malformed-cache handling).
 */
if (!function_exists('deFakeBin')) {
    function deFakeBin($dir, $name, $body)
    {
        @mkdir($dir, 0700, true);
        $path = "$dir/$name";
        file_put_contents($path, "#!/bin/sh\n$body\n");
        chmod($path, 0755);
        return $path;
    }
}

/** The source text of one `public static function NAME(...) { ... }` body in Detect.php, up to the next method or EOF. */
if (!function_exists('deMethodSource')) {
    function deMethodSource($name)
    {
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/lib/Detect.php');
        $start = strpos($src, "function $name(");
        if ($start === false) {
            return '';
        }
        $next = strpos($src, 'function ', $start + 10);
        return $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
    }
}

if (!function_exists('deUid')) {
    function deUid()
    {
        return function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
    }
}

return [
    'detect: osRelease() always returns a well-shaped {id, version}, both strings' => function () {
        $o = Detect::osRelease();
        check(is_array($o) && array_key_exists('id', $o) && array_key_exists('version', $o), 'osRelease() shape: ' . json_encode($o));
        check(is_string($o['id']) && is_string($o['version']), 'osRelease() id/version must be strings: ' . json_encode($o));
    },
    'detect: userName() reads posix_getpwuid for a real uid, and falls back to $USER for an unknown one' => function () {
        if (!function_exists('posix_getpwuid')) {
            skip('posix extension unavailable here');
        }
        $uid = deUid();
        $pw = posix_getpwuid($uid);
        check(is_array($pw), 'posix_getpwuid could not resolve the running account; cannot verify');
        check(Detect::userName($uid) === $pw['name'], 'userName() did not read the real account name: got ' . Detect::userName($uid));
        $old = getenv('USER');
        putenv('USER=pk-fallback-user');
        try {
            check(Detect::userName(999999999) === 'pk-fallback-user', 'an unresolvable uid must fall back to $USER, not posix_getpwuid\'s false');
        } finally {
            putenv($old === false ? 'USER' : "USER=$old");
        }
    },
    '[contract] detect: runningApps() checks fileowner() BEFORE reading /comm (privacy: never read another account\'s process name)' => function () {
        $body = deMethodSource('runningApps');
        check($body !== '', 'runningApps() source not found');
        $ownerAt = strpos($body, 'fileowner($dir) !== $uid');
        $commAt = strpos($body, "file_get_contents(\$dir . '/comm')");
        check($ownerAt !== false && $commAt !== false && $ownerAt < $commAt,
            'the owner guard must appear before the /comm read in source, not after');
    },
    'detect: runningApps(uid) for the real process returns a well-formed KNOWN_APPS subset, never duplicated' => function () {
        $apps = Detect::runningApps(deUid());
        check(is_array($apps), 'runningApps() must return an array');
        foreach ($apps as $a) {
            check(array_key_exists($a, Detect::KNOWN_APPS), "runningApps() returned '$a', not a KNOWN_APPS key");
        }
        check(count($apps) === count(array_unique($apps)), 'runningApps() returned the same app twice: ' . json_encode($apps));
    },
    'detect: resources() routes disk/homeTotalGb through Quota::fields()/disk_total_space() (proves delegation, not Quota\'s own correctness)' => function () {
        $home = sys_get_temp_dir() . '/pk-deres-' . getmypid() . '-' . mt_rand();
        @mkdir($home, 0700, true);
        $old = getenv('HOME');
        putenv("HOME=$home");
        try {
            $r = Detect::resources();
            check($r['disk'] === Quota::fields($home), 'resources()[\'disk\'] must be exactly Quota::fields($home): ' . json_encode($r));
            check($r['homeTotalGb'] === round(disk_total_space($home) / 1073741824, 1), 'homeTotalGb must be disk_total_space($home) in GiB: '
                . json_encode($r));
            check(is_int($r['cpus']) && $r['cpus'] >= 0, 'cpus must be a non-negative int: ' . json_encode($r));
            check(is_int($r['memTotalMb']) && $r['memTotalMb'] >= 0, 'memTotalMb must be a non-negative int: ' . json_encode($r));
        } finally {
            putenv($old === false ? 'HOME' : "HOME=$old");
            @rmdir($home);
        }
    },
    'detect: rootAccess() for a non-root uid never throws and is well-shaped regardless of this runner\'s own sudo membership' => function () {
        $r = Detect::rootAccess(424242);
        check(array_key_exists('available', $r) && array_key_exists('via', $r) && array_key_exists('capEff', $r), 'rootAccess() shape: '
            . json_encode($r));
        check(in_array($r['via'], ['none', 'sudo'], true), 'via must be none or sudo: ' . json_encode($r));
        check($r['capEff'] === null, 'capEff must stay null for a non-root uid: ' . json_encode($r));
        check(is_bool($r['available']), 'available must be a bool: ' . json_encode($r));
    },
    '[contract] detect: rootAccess() requires uidMapIsIdentity() AND capEff!==null AND capsAreFull() together (never an OR; a stripped-root container must not be misread as real root)' => function () {
        $body = deMethodSource('rootAccess');
        check($body !== '', 'rootAccess() source not found');
        check(strpos($body, 'self::uidMapIsIdentity() && $capEff !== null && self::capsAreFull($capEff)') !== false,
            'the three real-root conditions must be combined with && in source, not ||: ' . $body);
    },
    'detect: primaryIp() parses `src` from a real-shaped `ip route get` line, and is null on a nonzero exit or a missing src token' => function () {
        $dir = sys_get_temp_dir() . '/pk-deip-' . getmypid() . '-' . mt_rand();
        $old = getenv('PATH');
        try {
            deFakeBin($dir, 'ip', "echo '1.1.1.1 via 10.0.0.1 dev eth0 src 203.0.113.9 uid 1000'\necho '    cache'");
            putenv("PATH=$dir");
            check(Detect::primaryIp() === '203.0.113.9', 'a real-shaped route line must yield its src address');
            // prints a real-looking line too: isolates the exit-code check from "no output at all"
            deFakeBin($dir, 'ip', "echo '1.1.1.1 via 10.0.0.1 dev eth0 src 203.0.113.9 uid 1000'\nexit 2");
            check(Detect::primaryIp() === null, 'a nonzero `ip route get` exit must yield null even with output, not the parsed (possibly stale) address');
            deFakeBin($dir, 'ip', "echo '1.1.1.1 via 10.0.0.1 dev eth0 uid 1000'"); // no "src" token at all
            check(Detect::primaryIp() === null, 'a route line with no src token must yield null, not a wrong field misread as the address');
        } finally {
            putenv($old === false ? 'PATH' : "PATH=$old");
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    "detect: cached()'s update.log line carries exactly ts/event/family/stack/tier/sources{cpuQuota,memMb,disk}/ms, never the full profile (ADR-0016)" => function () {
        $dir = sys_get_temp_dir() . '/pk-delog-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        $file = "$dir/host.json";
        $log = "$dir/update.log";
        Detect::$runOverride = function () {
            return ['detectedAt' => 'x', 'uid' => 4242, 'family' => 'pmss', 'stack' => 'pmss', 'tier' => 'small',
                'identity' => ['hostname' => 'secret-host', 'fqdn' => 'secret-host.example', 'rdns' => null],
                'limits' => ['cpuQuota' => ['value' => 1.0, 'source' => 'cgroupv2'], 'memMb' => ['value' => 512.0, 'source' => 'cgroupv2'],
                    'disk' => ['value' => ['quota_used_bytes' => 1], 'source' => 'quota -s'], 'cpuset' => ['value' => 2, 'source' => 'cgroupv2']]];
        };
        try {
            Detect::cached(false, $file, $log);
            $lines = file($log, FILE_IGNORE_NEW_LINES);
            check(count($lines) === 1, 'exactly one update.log line must be written on the probing call: ' . json_encode($lines));
            $line = json_decode($lines[0], true);
            check(is_array($line), 'update.log line is not valid JSON: ' . $lines[0]);
            $wantKeys = ['ts', 'event', 'family', 'stack', 'tier', 'sources', 'ms'];
            sort($wantKeys);
            $gotKeys = array_keys($line);
            sort($gotKeys);
            check($gotKeys === $wantKeys, 'update.log key set drifted: got ' . json_encode($gotKeys) . ' want ' . json_encode($wantKeys));
            $wantSourceKeys = ['cpuQuota', 'memMb', 'disk'];
            sort($wantSourceKeys);
            $gotSourceKeys = array_keys($line['sources']);
            sort($gotSourceKeys);
            check($gotSourceKeys === $wantSourceKeys, 'update.log sources key set drifted: ' . json_encode($gotSourceKeys));
            check($line['family'] === 'pmss' && $line['stack'] === 'pmss' && $line['tier'] === 'small'
                && $line['sources']['cpuQuota'] === 'cgroupv2' && $line['sources']['memMb'] === 'cgroupv2' && $line['sources']['disk'] === 'quota -s',
                'update.log values wrong: ' . json_encode($line));
            check(!array_key_exists('uid', $line) && !array_key_exists('identity', $line),
                'update.log must never carry uid/identity (ADR-0016/0023 privacy): ' . json_encode($line));
            check(strpos(json_encode($line), 'secret-host') === false, 'update.log leaked the hostname: ' . json_encode($line));
        } finally {
            Detect::$runOverride = null;
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
    'detect: cached() treats truncated, non-JSON, array-shaped-wrong and key-missing host.json as a cache miss, re-probing every time, never throwing' => function () {
        $dir = sys_get_temp_dir() . '/pk-debad-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        $file = "$dir/host.json";
        $log = "$dir/update.log";
        $calls = 0;
        Detect::$runOverride = function () use (&$calls) {
            $calls++;
            return ['detectedAt' => 'x', 'uid' => 1000, 'family' => 'generic', 'stack' => 'none', 'tier' => 'medium',
                'limits' => ['cpuQuota' => ['value' => null, 'source' => null], 'memMb' => ['value' => null, 'source' => null],
                    'disk' => ['value' => null, 'source' => null], 'cpuset' => ['value' => null, 'source' => null]]];
        };
        try {
            $n = 0;
            foreach (['{"schema":1,"fingerprint":"x","uid":1000,"family":' /* truncated: unterminated JSON */,
                'not json at all {{{', '["a","b","c"]' /* a list, not the expected keyed shape */,
                '{"schema":1}' /* fingerprint/uid missing entirely */] as $bad) {
                file_put_contents($file, $bad);
                $n++;
                $p = Detect::cached(false, $file, $log);
                check($calls === $n, "bad cache content #$n did not force a re-probe (calls=$calls): " . var_export($bad, true));
                check(is_array($p) && $p['family'] === 'generic', "bad cache content #$n must still return a usable in-memory profile: " . json_encode($p));
            }
        } finally {
            Detect::$runOverride = null;
            exec('rm -rf ' . escapeshellarg($dir));
        }
    },
];
