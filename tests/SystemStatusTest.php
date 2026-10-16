<?php
/**
 * tools/system/system-status: a read-only snapshot (ADR-0023) plus log search over this account's
 * own files. The $systemStatusLocalDir seam (system-status.php, mirroring storage.php:27) lets
 * show/logs run against a temp local/ instead of the checkout's real pikkumies/local/ (catalog
 * #700), so these two verbs are exercised as real subprocesses for the first time.
 */
require_once __DIR__ . '/fixtures/storage/harness.php';

if (!function_exists('ssRun')) {
    function ssRun(array $fx, array $args, array $seams = [])
    {
        return stRun($fx, 'system/system-status/system-status.php', $args, ['systemStatusLocalDir' => $fx['local']] + $seams);
    }
}

/** A cached local/host.json whose fingerprint THIS machine will recompute identically when it reads it back. */
if (!function_exists('ssCacheWrite')) {
    function ssCacheWrite(array $fx, array $profile)
    {
        $uid = stUid();
        $profile += ['schema' => Detect::SCHEMA, 'uid' => $uid, 'fingerprint' => Detect::fingerprint($uid)];
        file_put_contents($fx['local'] . '/host.json', json_encode($profile, JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('ssBaseProfile')) {
    function ssBaseProfile()
    {
        return [
            'detectedAt' => '2026-01-01T00:00:00+00:00',
            'os'         => ['id' => 'debian', 'version' => '12'],
            'user'       => 'x',
            'pmss'       => false,
            'family'     => 'generic',
            'stack'      => 'none',
            'identity'   => ['hostname' => 'should-be-stripped', 'fqdn' => 'should-be-stripped.example', 'rdns' => null],
            'tenancy'    => ['shared' => false],
            'root'       => ['available' => false, 'via' => 'none', 'capEff' => null, 'suPresent' => false],
            'apps'       => [],
            'resources'  => ['cpus' => 1, 'memTotalMb' => 1024, 'disk' => [], 'homeTotalGb' => null],
            'limits'     => [
                'cpuQuota' => ['value' => null, 'source' => null],
                'cpuset'   => ['value' => null, 'source' => null],
                'memMb'    => ['value' => null, 'source' => null],
                'disk'     => ['value' => null, 'source' => null],
            ],
            'tier' => 'medium',
        ];
    }
}

return [
    'system-status --help: prints the real usage naming both verbs and --grep/--human, exits 0, never touches local/' => function () {
        $fx = stFixture([], ['timeout', 'quota']);
        try {
            list($code, $out) = ssRun($fx, ['--help']);
            check($code === 0, "exit $code: $out");
            foreach (['show', 'logs', '--grep', '--human'] as $needle) {
                check(strpos($out, $needle) !== false, "usage text missing '$needle': $out");
            }
            check(!is_file($fx['local'] . '/host.json'), '--help must never probe/cache (Detect::cached() must stay untouched)');
        } finally {
            stDone($fx);
        }
    },
    'system-status: an unknown verb and no verb at all both exit 2 naming the problem, and never reach Detect::cached()' => function () {
        $fx = stFixture([], ['timeout', 'quota']);
        try {
            list($code, $out) = ssRun($fx, []);
            $l = stLines($out);
            check($code === 2 && end($l)['error'] === 'no verb given', "no verb: $code: $out");
            list($code, $out) = ssRun($fx, ['bogus']);
            $l = stLines($out);
            check($code === 2 && end($l)['error'] === 'unknown verb: bogus', "bad verb: $code: $out");
            check(!is_file($fx['local'] . '/host.json'), 'a usage error must never run Detect::cached() (no probe/cache written)');
        } finally {
            stDone($fx);
        }
    },
    'system-status show: strips identity and fingerprint (ADR-0023) from the cached profile before returning it' => function () {
        $fx = stFixture([], ['timeout', 'quota']);
        try {
            ssCacheWrite($fx, ssBaseProfile());
            list($code, $out) = ssRun($fx, ['show']);
            check($code === 0, "show failed: $out");
            $record = stLines($out)[0];
            check(!array_key_exists('identity', $record) && !array_key_exists('fingerprint', $record),
                'identity/fingerprint leaked into the model-facing record: ' . json_encode($record));
            check($record['family'] === 'generic' && $record['stack'] === 'none',
                'show did not return the cached profile at all: ' . json_encode($record));
        } finally {
            stDone($fx);
        }
    },
    'system-status show: Detect::limits()+Cgroup::memoryCurrent()+Detect::leadingLimit() compose so a grown LIVE usage '
    . 'moves the leading limit, proving show reads live, never the limits a stale snapshot happened to store (system-status.php:21-22)' => function () {
        $base = sys_get_temp_dir() . '/pk-sslim-' . getmypid() . '-' . mt_rand();
        @mkdir("$base/x", 0700, true);
        file_put_contents("$base/memory.current", "104857600\n");   // 100 MiB used
        file_put_contents("$base/x/memory.current", "968884224\n"); // 924 MiB used, same 1 GiB ceiling below
        $self = "$base/self-cgroup";
        // disk sits at a fixed 50%; memMb's limit is a fixed 1 GiB; only the live usage moves.
        $limits = ['disk' => ['value' => ['quota_used_bytes' => 500, 'quota_limit_bytes' => 1000, 'quota_hard_bytes' => 1200,
            'quota_free_bytes' => 500, 'over_quota' => false, 'quota_source' => 'quota -s', 'fs_free_bytes' => 999], 'source' => 'quota -s'],
            'memMb' => ['value' => 1024.0, 'source' => 'cgroupv2'], 'cpuQuota' => ['value' => null, 'source' => null],
            'cpuset' => ['value' => null, 'source' => null]];
        try {
            file_put_contents($self, "0::/\n");
            $low = Cgroup::memoryCurrent($base, $self);
            file_put_contents($self, "0::/x\n");
            $high = Cgroup::memoryCurrent($base, $self);
            check($low === 104857600.0 && $high === 968884224.0, 'Cgroup::memoryCurrent must read the LEAF cgroup only: '
                . var_export([$low, $high], true));
            $leadingLow = Detect::leadingLimit($limits, $low);
            $leadingHigh = Detect::leadingLimit($limits, $high);
            check(array_keys($leadingLow)[0] === 'disk', 'at ~10% memory used, the fixed 50% disk fraction must lead: got '
                . json_encode(array_keys($leadingLow)));
            check(array_keys($leadingHigh)[0] === 'memMb', 'at ~90% memory used, memMb must overtake the fixed 50% disk fraction '
                . 'once the live cgroup read grows: got ' . json_encode(array_keys($leadingHigh)));
        } finally {
            exec('rm -rf ' . escapeshellarg($base));
        }
    },
    'system-status logs: a symlink inside HOME pointing outside HOME is never read even when it matches a known log glob (ADR-0023)' => function () {
        $fx = stFixture([], ['timeout', 'quota']);
        try {
            $profile = ssBaseProfile();
            $profile['family'] = 'pmss';
            $profile['stack'] = 'pmss';
            ssCacheWrite($fx, $profile);
            mkdir($fx['home'] . '/.lighttpd', 0700, true);
            file_put_contents($fx['dir'] . '/outside-secret.log', "private data outside this account's home\n");
            symlink($fx['dir'] . '/outside-secret.log', $fx['home'] . '/.lighttpd/access.log');
            list($code, $out) = ssRun($fx, ['logs']);
            check($code === 0, "logs failed: $out");
            $l = stLines($out);
            $records = array_slice($l, 0, -1);
            // The ONLY glob match that exists is the escaping symlink: a correct guard must leave zero records,
            // not merely a record whose 'path' happens not to say ".lighttpd/access.log" (a realpath outside HOME
            // still substr()s into *some* string, so checking for that one literal name would miss the leak).
            check($records === [], 'a symlink escaping HOME was read as this account\'s own log: ' . json_encode($l));
            $src = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/system/system-status/system-status.php');
            check(strpos($src, "fileowner(\$real) !== \$uid") !== false,
                'the owned-by-caller guard is missing from source (cannot be exercised here without root to forge another uid)');
        } finally {
            stDone($fx);
        }
    },
    'system-status logs --grep: reads only the last 1 MiB of each file, and the 50-line match cap is shared across every file, not per file' => function () {
        $fx = stFixture([], ['timeout', 'quota']);
        try {
            $profile = ssBaseProfile();
            $profile['family'] = 'pmss';
            $profile['stack'] = 'pmss';
            ssCacheWrite($fx, $profile);
            mkdir($fx['home'] . '/.lighttpd', 0700, true);
            $pad = str_repeat('x', 1200000); // pushes the file just over 1 MiB with no needle inside the padding
            file_put_contents($fx['home'] . '/.lighttpd/access.log', "NEEDLE-EARLY line\n" . $pad . "\nNEEDLE-LATE line\n");
            file_put_contents($fx['home'] . '/.lighttpd/error.log', str_repeat("NEEDLE cap line\n", 30));
            file_put_contents($fx['home'] . '/rTorrentLog', str_repeat("NEEDLE cap line\n", 30));
            list($code, $out) = ssRun($fx, ['logs', '--grep', 'NEEDLE']);
            check($code === 0, "logs --grep failed: $out");
            $l = stLines($out);
            $byPath = [];
            foreach (array_slice($l, 0, -1) as $rec) {
                $byPath[$rec['path']] = $rec;
            }
            check(isset($byPath['.lighttpd/access.log']), 'access.log record missing: ' . json_encode($l));
            $accessMatches = $byPath['.lighttpd/access.log']['matches'];
            check(!in_array('NEEDLE-EARLY line', $accessMatches, true), 'a match before the last 1 MiB window was read: '
                . json_encode($accessMatches));
            check(in_array('NEEDLE-LATE line', $accessMatches, true), 'a match inside the last 1 MiB window was missed: '
                . json_encode($accessMatches));
            $total = 0;
            foreach ($byPath as $rec) {
                $total += count($rec['matches']);
            }
            check($total === 50, "the 50-line cap must be shared across every file (1 + 30 + 30 = 61 available), not per file: got $total: "
                . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
];
