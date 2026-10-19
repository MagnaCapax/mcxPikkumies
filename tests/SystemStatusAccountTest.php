<?php
/**
 * system-status account (ADR-0058): one live record of this account's own figures from PMSS userShowLimits, and the
 * show verb's live apps and labelled server totals. The fixture (tests/fixtures/system-status/userShowLimits.php) has
 * the shape and metric names of PMSS etc/skel/bin/userShowLimits.
 */
require_once __DIR__ . '/fixtures/storage/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/system-status/StatusAccount.php';

if (!function_exists('ssaFixture')) {
    /** The fixture as Detect::userShowLimitsData returns it (names dropped there; the record must not need that). */
    function ssaFixture()
    {
        $d = require __DIR__ . '/fixtures/system-status/userShowLimits.php';
        return ['generated_at' => $d['generated_at'], 'sections' => $d['sections']];
    }

    /** Keys at every depth (a list counts as one leaf), so two records can be compared by shape. */
    function ssaShape(array $a)
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[$k] = is_array($v) && $v !== array_values($v) ? ssaShape($v) : 'leaf';
        }
        return $out;
    }

    function ssaRun(array $fx, $verb)
    {
        return stRun($fx, 'system/system-status/system-status.php', [$verb], ['systemStatusLocalDir' => $fx['local'], 'systemStatusProcDir' => $fx['proc']]);
    }

    /** A fixture whose HOME has bin/userShowLimits (the fixture as JSON), a cached profile of $family and a fake /proc. */
    function ssaSetup($family)
    {
        $fx = stFixture([], ['timeout', 'quota']);
        mkdir($fx['home'] . '/bin', 0700);
        $bin = $fx['home'] . '/bin/userShowLimits';
        file_put_contents($bin, '#!' . PHP_BINARY . "\n<?php file_put_contents(getenv('FAKE_DIR') . '/usl.log', json_encode(array_slice(\$argv, 1)) . \"\\n\", FILE_APPEND);\n"
            . 'echo json_encode(require ' . var_export(__DIR__ . '/fixtures/system-status/userShowLimits.php', true) . "), \"\\n\";\n");
        chmod($bin, 0700);
        mkdir($fx['proc'] . '/4242', 0700, true);
        file_put_contents($fx['proc'] . '/4242/comm', "rtorrent main\n");
        $uid = stUid(); // a cached profile whose fingerprint this machine recomputes identically (Detect::cached)
        $profile = ['detectedAt' => '2026-01-01T00:00:00+00:00', 'os' => ['id' => 'debian', 'version' => '12'], 'user' => 'fixture-acct-name',
            'uid' => $uid, 'pmss' => $family === 'pmss', 'family' => $family, 'stack' => 'none', 'tenancy' => ['shared' => $family === 'pmss'],
            'root' => ['available' => false, 'via' => 'none', 'capEff' => null, 'suPresent' => false],
            'apps' => ['plex'], // frozen at detection; nothing named plex runs
            'resources' => ['cpus' => 64, 'memTotalMb' => 262144, 'disk' => ['quota_used_bytes' => 987654321], 'homeTotalGb' => 90000.0],
            'limits' => [], 'tier' => 'medium', 'schema' => Detect::SCHEMA, 'fingerprint' => Detect::fingerprint($uid)];
        file_put_contents($fx['local'] . '/host.json', json_encode($profile, JSON_UNESCAPED_SLASHES));
        return $fx;
    }
}

return [
    'system-status account: the PMSS fixture gives every figure in at most 1.5 KB, with no account or server name' => function () {
        $r = StatusAccount::record(ssaFixture(), 'pmss', ['rtorrent']);
        $json = json_encode($r, JSON_UNESCAPED_SLASHES);
        check(strlen($json) <= 1536, 'record is ' . strlen($json) . ' bytes: ' . $json);
        foreach (StatusAccount::MAP as $group => $keys) {
            foreach ($keys as $key => $where) {
                check($r[$group][$key] !== null, "$group.$key is null; PMSS writes it as \"{$where[1]}\" in {$where[0]}: " . $json);
            }
        }
        check($r['disk']['left'] === 800.0 * 1073741824 && $r['disk']['used_pct'] === 60.0, 'disk left/used_pct: ' . $json);
        check($r['traffic']['left'] === 4096.0 * 1073741824 && $r['traffic']['used_pct'] === 20.0 && $r['memory']['used_pct'] === 37.5, 'traffic/memory share: ' . $json);
        check($r['unlimited'] === [] && $r['apps'] === ['rtorrent'] && $r['source'] === 'userShowLimits', 'source/apps/unlimited: ' . $json);
        // ADR-0023: neither name reaches the model, not even through the panel address, which stays a template.
        foreach (['fixture-acct-name', 'fixture-srv-name', (string) @gethostname()] as $name) {
            check(strlen($name) < 4 || stripos($json, $name) === false, "a name got into the record: $name: $json");
        }
        check(preg_match_all('#/user-([^/"]*)#', $json, $m) === 1 && $m[1] === ['<you>'] && strpos($json, '<this server>') !== false, 'panel is not a template: ' . $json);
        check(ssaShape($r) === ssaShape(StatusAccount::record(null, 'pmss', [])), 'keys beyond the fixed record: ' . json_encode(array_keys($r)));
        check(strpos($json, 'shared by all accounts') === false && strpos($json, 'load average') === false, 'a server-wide figure got in: ' . $json);
    },
    'system-status account: renaming any metric PMSS writes leaves its figure null, never a guess (the names are the contract)' => function () {
        $full = StatusAccount::record(ssaFixture(), 'pmss', []);
        foreach (StatusAccount::MAP as $group => $keys) {
            foreach ($keys as $key => $where) {
                $d = ssaFixture();
                foreach ($d['sections'][$where[0]]['metrics'] as $i => $metric) {
                    if ($metric['metric'] === $where[1]) {
                        $d['sections'][$where[0]]['metrics'][$i]['metric'] = $where[1] . ' (renamed)';
                    }
                }
                $r = StatusAccount::record($d, 'pmss', []);
                check($r[$group][$key] === null && $full[$group][$key] !== null, "renamed \"{$where[1]}\" but $group.$key still reads " . json_encode($r[$group][$key]));
            }
        }
    },
    'system-status account: off PMSS the record has the same keys, every figure null and no panel' => function () {
        $off = StatusAccount::record(null, 'generic', []);
        check(ssaShape($off) === ssaShape(StatusAccount::record(ssaFixture(), 'pmss', ['x'])), 'shape differs off PMSS: ' . json_encode($off));
        foreach (array_keys(StatusAccount::MAP) as $group) {
            check(array_filter($off[$group], function ($v) {
                return $v !== null;
            }) === [], "$group has a figure off PMSS: " . json_encode($off[$group]));
        }
        check($off['source'] === null && $off['at'] === null && $off['panel'] === null && $off['family'] === 'generic', 'off PMSS: ' . json_encode($off));
        $down = StatusAccount::record(['generated_at' => 1, 'sections' => ['traffic' => ['available' => false, 'metrics' => []]]], 'pmss', []);
        check($down['traffic']['upload_30d'] === null && $down['unlimited'] === [], 'an unavailable section must read as unknown, never unlimited');
    },
    'system-status account: the verb runs ~/bin/userShowLimits --json once on PMSS, reads apps now, and never on another host' => function () {
        $fx = ssaSetup('pmss');
        try {
            list($code, $out) = ssaRun($fx, 'account');
            check($code === 0, "account failed: $out");
            $r = stLines($out)[0];
            check($r['source'] === 'userShowLimits' && $r['disk']['used'] === 1200 * 1073741824 && $r['traffic']['cap_mbit'] === 1000, 'figures: ' . json_encode($r));
            check($r['apps'] === ['rtorrent'], 'apps not read now: ' . json_encode($r['apps']));
            check(stripos($out, 'fixture-acct-name') === false && stripos($out, 'fixture-srv-name') === false, 'a name got out: ' . $out);
            check(file($fx['dir'] . '/usl.log', FILE_IGNORE_NEW_LINES) === ['["--json"]'], 'userShowLimits argv');
        } finally {
            stDone($fx);
        }
        $fx = ssaSetup('generic');
        try {
            list($code, $out) = ssaRun($fx, 'account');
            $r = stLines($out)[0];
            check($code === 0 && $r['source'] === null && $r['disk']['used'] === null && !is_file($fx['dir'] . '/usl.log'), 'ran userShowLimits off PMSS: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'system-status show: apps are read now, the cached disk is gone and the server totals say they are the whole server\'s' => function () {
        $fx = ssaSetup('pmss');
        try {
            list($code, $out) = ssaRun($fx, 'show');
            check($code === 0, "show failed: $out");
            $r = stLines($out)[0];
            check($r['apps'] === ['rtorrent'], 'apps came from the cache: ' . json_encode($r['apps']));
            check(!isset($r['resources']) && strpos($out, '987654321') === false, 'the cached quota snapshot is still in show: ' . $out);
            check($r['server_totals'] === ['scope' => 'whole server, shared by all accounts', 'cpus' => 64, 'memTotalMb' => 262144, 'homeTotalGb' => 90000], 'server totals: ' . json_encode($r['server_totals']));
            check(!isset($r['user']) && stripos($out, 'fixture-acct-name') === false, 'the account name is in show: ' . $out);
            check(isset($r['limits']['disk']), 'live limits missing: ' . $out);
        } finally {
            stDone($fx);
        }
    },
];
