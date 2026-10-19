<?php
/** lib/Detect.php: identity/root/limits/tier probes and the cached() cache (ADR-0023). */
function detectFakeBin($dir, $name, $body)
{
    @mkdir($dir, 0700, true);
    $path = "$dir/$name";
    file_put_contents($path, "#!/bin/sh\n$body\n");
    chmod($path, 0755);
    return $path;
}

function detectFakeUserShowLimits($cpuQuotaPercent, $memLimitBytes)
{
    $json = json_encode(['user' => 'x', 'host' => 'x', 'generated_at' => 'x', 'sections' => [
        'cpu' => ['available' => true, 'metrics' => [
            ['metric' => 'CPU quota enforced', 'value' => $cpuQuotaPercent, 'limit' => null, 'unit' => 'percent', 'percent' => null, 'source' => 'cgroup'],
        ]],
        'memory' => ['available' => true, 'metrics' => [
            ['metric' => 'memory', 'value' => 10485760, 'limit' => $memLimitBytes, 'unit' => 'bytes', 'percent' => null, 'source' => 'cgroup'],
        ]],
    ]]);
    return "echo '" . str_replace("'", "'\\''", $json) . "'";
}

return [
    'detect: profile carries every new ADR-0023 field, correctly typed' => function () {
        $p = Detect::run();
        foreach (['family', 'stack', 'identity', 'tenancy', 'limits', 'tier'] as $k) {
            check(array_key_exists($k, $p), "missing $k");
        }
        check(array_key_exists('suPresent', $p['root']), 'root.suPresent missing');
        check(is_bool($p['root']['suPresent']), 'root.suPresent not a bool');
        check(is_bool($p['tenancy']['shared']), 'tenancy.shared not a bool');
        foreach (['cpuQuota', 'cpuset', 'memMb', 'disk'] as $k) {
            check(array_key_exists('value', $p['limits'][$k]) && array_key_exists('source', $p['limits'][$k]), "limits.$k missing value/source");
        }
        check(in_array($p['tier'], ['small', 'medium', 'large'], true), 'tier not one of small/medium/large');
        check(array_key_exists('hostname', $p['identity']) && array_key_exists('fqdn', $p['identity']) && array_key_exists('rdns', $p['identity']), 'identity shape wrong');
    },
    'detect: suPresent finds su on PATH by existence only, and NEVER executes it (exec seam)' => function () {
        $dir = sys_get_temp_dir() . '/pk-su-' . getmypid();
        $marker = "$dir/su-was-executed";
        @unlink($marker);
        detectFakeBin($dir, 'su', "touch " . escapeshellarg($marker));
        $path = getenv('PATH');
        putenv('PATH=' . $dir);
        try {
            check(Detect::suPresent() === true, 'su on PATH not found');
            check(!file_exists($marker), 'suPresent() executed su: it must only check is_executable()');
        } finally {
            putenv('PATH=' . $path);
            @unlink("$dir/su");
            @rmdir($dir);
        }
    },
    'detect: sharedTenancy is pmss, or more than one human account in /etc/passwd; the count itself never leaves the function' => function () {
        check(Detect::sharedTenancy(true, '/nonexistent-passwd-does-not-matter') === true, 'pmss must always be shared');
        $tmp = sys_get_temp_dir() . '/pk-passwd-' . getmypid();
        $home1 = "$tmp/home1";
        $home2 = "$tmp/home2";
        @mkdir($home1, 0700, true);
        @mkdir($home2, 0700, true);
        file_put_contents("$tmp/one-human", "root:x:0:0:root:/root:/bin/bash\nalice:x:1000:1000:Alice:$home1:/bin/bash\ndaemon:x:999:999:d:/nonexistent:/usr/sbin/nologin\n");
        file_put_contents("$tmp/two-humans", "root:x:0:0:root:/root:/bin/bash\nalice:x:1000:1000:Alice:$home1:/bin/bash\nbob:x:1001:1001:Bob:$home2:/bin/bash\n");
        check(Detect::sharedTenancy(false, "$tmp/one-human") === false, 'exactly one human account must not be shared');
        check(Detect::sharedTenancy(false, "$tmp/two-humans") === true, 'two human accounts must be shared');
        check(Detect::sharedTenancy(false, '/nonexistent-passwd-file') === false, 'an unreadable passwd must default to not-shared, never throw');
        $r = new ReflectionMethod('Detect', 'sharedTenancy');
        check(count($r->getParameters()) === 2, 'sharedTenancy must only take pmss + the passwd path: the account count has no way out of the function');
    },
    'detect: tier is small/large only at both thresholds, medium between, and medium when unknown' => function () {
        check(Detect::tier(null, null) === 'medium', 'unknown cpu and memory must default medium, never guess');
        check(Detect::tier(0.5, 512.0) === 'small', 'below the small threshold on both must be small');
        check(Detect::tier(0.5, null) === 'small', 'below the small cpu threshold alone must be small');
        check(Detect::tier(8.0, 16384.0) === 'large', 'at/above the large threshold on both must be large');
        check(Detect::tier(8.0, 2048.0) === 'medium', 'large cpu but not large memory must stay medium, not large');
        check(Detect::tier(2.0, 4096.0) === 'medium', 'between the thresholds must be medium');
    },
    'detect: identity() attempts rDNS only while family is still generic, and never on a known family' => function () {
        $called = false;
        Detect::$primaryIpOverride = function () use (&$called) {
            $called = true;
            return '203.0.113.5'; // TEST-NET-3, never routed
        };
        try {
            Detect::identity('pmss');
            check(!$called, 'identity() must not even look up the primary IP once family is already known');
            $called = false;
            $id = Detect::identity('generic');
            check($called, 'identity() must attempt rDNS when family is still generic');
            check(array_key_exists('rdns', $id), 'rdns key missing');
        } finally {
            Detect::$primaryIpOverride = null;
        }
    },
    'detect: rDNS fails closed (missing binary or a real timeout) to rdns:null, never an exception' => function () {
        Detect::$primaryIpOverride = function () {
            return '203.0.113.6';
        };
        $dir = sys_get_temp_dir() . '/pk-rdns-' . getmypid();
        $path = getenv('PATH');
        try {
            putenv('PATH=/nonexistent-path-dir-no-getent');
            $id = Detect::identity('generic');
            check($id['rdns'] === null, 'a missing getent binary must leave rdns null, not throw');
            detectFakeBin($dir, 'getent', 'sleep 5'); // longer than the 3s timeout wrapper
            putenv('PATH=' . $dir . ':' . $path);
            $t0 = microtime(true);
            $id = Detect::identity('generic');
            $elapsed = microtime(true) - $t0;
            check($id['rdns'] === null, 'a getent that hangs past the timeout must leave rdns null, not hang the caller');
            check($elapsed < 4.5, "the 3s timeout wrapper did not bound a hanging getent: took {$elapsed}s");
        } finally {
            putenv('PATH=' . $path);
            @unlink("$dir/getent");
            @rmdir($dir);
        }
    },
    'detect: userShowLimits parses the cpu/memory sections, enforces its own 10s timeout and runs by absolute path only' => function () {
        $home = sys_get_temp_dir() . '/pk-usl-' . getmypid();
        @mkdir("$home/bin", 0700, true);
        $bin = "$home/bin/userShowLimits";
        file_put_contents($bin, "#!/bin/sh\n" . detectFakeUserShowLimits(150, 536870912) . "\n");
        chmod($bin, 0755);
        $r = Detect::userShowLimits($home);
        check($r['cpuQuota']['value'] === 1.5 && $r['cpuQuota']['source'] === 'userShowLimits', 'cpuQuota not parsed from "CPU quota enforced" (150% -> 1.5 cores): ' . json_encode($r));
        check($r['memMb']['value'] === 512.0 && $r['memMb']['source'] === 'userShowLimits', 'memMb not parsed from the memory metric\'s limit field (536870912 bytes -> 512 MiB): ' . json_encode($r));
        check(Detect::userShowLimits($home, "$home/bin/does-not-exist") === null, 'a missing binary must return null, not throw');
        file_put_contents("$bin-bad", "#!/bin/sh\necho 'not json'\n");
        chmod("$bin-bad", 0755);
        check(Detect::userShowLimits($home, "$bin-bad") === null, 'unparseable output must return null');
        $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/lib/Detect.php');
        check(strpos($src, "exec('timeout 10 ' . escapeshellarg(\$bin)") !== false, 'userShowLimits must run through `timeout 10` by an escaped absolute path, fixed argv');
    },
    'detect: limits() falls back to the cgroup/host reader when userShowLimits is unavailable, and only reports host totals on a dedicated (non-shared) account' => function () {
        $home = sys_get_temp_dir() . '/pk-lim-' . getmypid();
        @mkdir($home, 0700, true);
        $shared = Detect::limits('generic', $home, true);
        $dedicated = Detect::limits('generic', $home, false);
        check($shared['disk']['value'] !== null, 'disk must always be populated from Quota::fields()');
        if ($shared['cpuQuota']['value'] === null) {
            check($dedicated['cpuQuota']['source'] === null || $dedicated['cpuQuota']['source'] === 'host', 'an undetermined cpuQuota on a dedicated account must fall back to source=host, or stay null if even affinity is unknown');
        }
        check($shared['cpuQuota']['source'] !== 'host', 'a shared account must never report source=host (that would claim the whole machine as the tenant\'s own limit)');
    },
    'detect: leadingLimit() puts the limit closest to its own cap first' => function () {
        $limits = [
            'disk'     => ['value' => ['quota_used_bytes' => 950, 'quota_limit_bytes' => 1000, 'quota_hard_bytes' => 1200, 'quota_free_bytes' => 50, 'over_quota' => false, 'quota_source' => 'quota -s', 'fs_free_bytes' => 999999], 'source' => 'quota -s'],
            'memMb'    => ['value' => 100.0, 'source' => 'cgroupv2'],
            'cpuQuota' => ['value' => 1.0, 'source' => 'cgroupv2'],
            'cpuset'   => ['value' => 4, 'source' => 'cgroupv2'],
        ];
        // 10 MiB current usage against a 100 MiB memMb limit (10%) must lose to disk's 95%.
        $ordered = Detect::leadingLimit($limits, 10 * 1048576);
        check(array_keys($ordered)[0] === 'disk', 'disk at 95% of its cap must lead: got ' . json_encode(array_keys($ordered)));
        $byMem = Detect::leadingLimit($limits, 99 * 1048576); // 99 MiB of 100 MiB = 99%, must now beat disk's 95%
        check(array_keys($byMem)[0] === 'memMb', 'memMb at 99% of its cap must lead over disk at 95%: got ' . json_encode(array_keys($byMem)));
        $empty = Detect::leadingLimit(['disk' => ['value' => null, 'source' => null], 'memMb' => ['value' => null, 'source' => null], 'cpuQuota' => ['value' => null, 'source' => null], 'cpuset' => ['value' => null, 'source' => null]], null);
        check(array_keys($empty) === ['disk', 'memMb', 'cpuQuota', 'cpuset'], 'with no known fraction, the table order must be kept');
    },
    'detect: fingerprint changes with hostname, machine-id, and uid' => function () {
        $tmp = sys_get_temp_dir() . '/pk-fp-' . getmypid();
        @mkdir($tmp, 0700, true);
        file_put_contents("$tmp/machine-id-a", "aaaa\n");
        file_put_contents("$tmp/machine-id-b", "bbbb\n");
        $a = Detect::fingerprint(1000, "$tmp/machine-id-a");
        $b = Detect::fingerprint(1000, "$tmp/machine-id-b");
        $c = Detect::fingerprint(1001, "$tmp/machine-id-a");
        check($a !== $b, 'a different machine-id must change the fingerprint');
        check($a !== $c, 'a different uid must change the fingerprint');
        check(Detect::fingerprint(1000, "$tmp/machine-id-a") === $a, 'the same inputs must be deterministic');
    },
    'detect: cached() reuses a matching cache (no probe), re-probes on a fingerprint or schema mismatch, and never caches a thrown profile' => function () {
        $dir = sys_get_temp_dir() . '/pk-cached-' . getmypid();
        @mkdir($dir, 0700, true);
        $file = "$dir/host.json";
        $log = "$dir/update.log";
        $calls = 0;
        $fakeProfile = function () use (&$calls) {
            $calls++;
            return ['detectedAt' => 'x', 'uid' => 1000, 'family' => 'generic', 'stack' => 'none', 'tier' => 'medium',
                'limits' => ['cpuQuota' => ['value' => null, 'source' => null], 'memMb' => ['value' => null, 'source' => null], 'disk' => ['value' => null, 'source' => null], 'cpuset' => ['value' => null, 'source' => null]]];
        };
        Detect::$runOverride = $fakeProfile;
        try {
            $p1 = Detect::cached(false, $file, $log);
            check($calls === 1, 'first call must probe once');
            $p2 = Detect::cached(false, $file, $log);
            check($calls === 1, 'a matching cache must not probe again: ' . $calls);
            check($p1['family'] === $p2['family'], 'cached read must return the same profile');
            check((fileperms($file) & 0777) === 0600, 'host.json must be written 0600: got ' . substr(sprintf('%o', fileperms($file)), -4));
            check(is_file($log), 'update.log must have one line written on the probing call');
            check(count(file($log)) === 1, 'a cache hit must not append another update.log line');
            $p3 = Detect::cached(true, $file, $log);
            check($calls === 2, '--refresh must force a new probe even on a matching cache');
            // Corrupt the fingerprint by hand: a schema bump or a machine change must invalidate it the same way.
            $data = json_decode(file_get_contents($file), true);
            $data['fingerprint'] = 'stale-fingerprint';
            file_put_contents($file, json_encode($data));
            Detect::cached(false, $file, $log);
            check($calls === 3, 'a fingerprint mismatch must force a re-probe');
            $data = json_decode(file_get_contents($file), true);
            $data['schema'] = 999999;
            file_put_contents($file, json_encode($data));
            Detect::cached(false, $file, $log);
            check($calls === 4, 'a schema mismatch must force a re-probe');
            Detect::$runOverride = function () {
                throw new RuntimeException('probe blew up');
            };
            $before = file_get_contents($file);
            $p = Detect::cached(true, $file, $log);
            check(isset($p['error']), 'a thrown probe must still return an in-memory profile, never crash the caller');
            check(file_get_contents($file) === $before, 'a thrown probe must never overwrite the existing cache file');
        } finally {
            Detect::$runOverride = null;
            @unlink($file);
            @unlink($log);
            @rmdir($dir);
        }
    },
    /**
     * `pikkumies detect [--refresh] [--human]` itself (bin/pikkumies) is deliberately NOT exercised
     * as a real subprocess here: Config::localDir() is fixed to this checkout's own pikkumies/local
     * (not $HOME-relative), which is real, non-gitignored-from-writes, potentially the operator's
     * own dev state (ConcurrencyTest.php follows the same rule: it builds a standalone harness
     * script rather than running bin/pikkumies, for the same reason). Launch::route() already proves
     * 'detect'/'--refresh' routing (tests/LaunchTest.php); CliHuman::render()/the jsonl encoding are
     * exercised directly by CliTest.php/CliHumanKindsTest.php. What is new here — Detect::cached()'s
     * own behaviour — is covered above without going through the CLI entrypoint.
     */
    'detect: applyOverride lets local/config.php\'s host key win over any detected/cached value' => function () {
        $profile = ['family' => 'generic', 'stack' => 'none', 'tier' => 'medium', 'root' => ['available' => false]];
        check(Detect::applyOverride($profile, []) === $profile, 'no host config must leave the profile untouched');
        check(Detect::applyOverride($profile, ['host' => ['family' => 'swizzin']])['family'] === 'swizzin', 'a human override must win over the detected family');
        $r = Detect::applyOverride($profile, ['host' => ['root' => ['available' => true]]]);
        check($r['root']['available'] === true && $r['stack'] === 'none', 'a nested override must merge, not replace the whole profile');
    },
    'detect: a hand-edited host.json claiming root is read back as-is by cached() (it is only a hint), but rootAccess() takes no file input at all, so no cache content can ever change a real privileged check (ADR-0004 addendum)' => function () {
        $dir = sys_get_temp_dir() . '/pk-roothint-' . getmypid() . '-' . mt_rand();
        @mkdir($dir, 0700, true);
        $file = "$dir/host.json";
        $log = "$dir/update.log";
        $uid = function_exists('posix_geteuid') ? posix_geteuid() : getmyuid();
        // Simulate a user (or anything with the account's own access) hand-editing host.json to claim root.
        file_put_contents($file, json_encode([
            'schema' => Detect::SCHEMA, 'fingerprint' => Detect::fingerprint($uid), 'uid' => $uid,
            'family' => 'generic', 'stack' => 'none', 'tier' => 'medium',
            'root' => ['available' => true, 'via' => 'sudo', 'capEff' => null],
            'limits' => ['cpuQuota' => ['value' => null, 'source' => null], 'memMb' => ['value' => null, 'source' => null],
                'disk' => ['value' => null, 'source' => null], 'cpuset' => ['value' => null, 'source' => null]],
        ]));
        chmod($file, 0600);
        try {
            $cached = Detect::cached(false, $file, $log);
            check($cached['root']['available'] === true, 'cached() must return the cache content verbatim: it is a hint, not something it validates');
            // The structural guarantee: rootAccess() is the real check every privileged action relies on, and
            // it takes only a uid. There is no parameter, global or file read through which host.json's
            // claim could reach it, so a false "yes" in the cache buys no privilege.
            $r = new ReflectionMethod('Detect', 'rootAccess');
            check(count($r->getParameters()) === 1 && $r->getParameters()[0]->getName() === 'uid', 'rootAccess() must take only the uid: no cache/profile input can reach the real check');
            $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/lib/Detect.php');
            $start = strpos($src, 'function rootAccess(');
            $next = strpos($src, 'function ', $start + 10);
            $body = $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
            check($start !== false && strpos($body, 'host.json') === false && strpos($body, '$file') === false, 'rootAccess() source must never reference a cache file: ' . $body);
        } finally {
            @unlink($file);
            @unlink($log);
            @rmdir($dir);
        }
    },
];
