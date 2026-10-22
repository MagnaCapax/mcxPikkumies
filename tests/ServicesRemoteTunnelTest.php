<?php
/**
 * services remote for transport tunnel (ADR-0107): read verbs answered here from the box's own rTorrent RPC and Bytesized's
 * accounts API through the fake requester (tests/fixtures/rtorrent/httprpc.php), change verbs refused before anything is
 * recorded or sent, per-service error records for what a tunnel does not answer, and a mixed --all where each service takes
 * its own path. ServicesRemote::run is called in-process; the oracle is its records, the request log and the files on disk.
 */
require_once __DIR__ . '/fixtures/rtorrent/httprpc.php';

const SRT_API_KEY = 'Bz-Api/Key+0racle=3';
const SRT_SLOT_LOGIN = 'SlotLogin0racle-99';

function srtFeral(array $clients = null)
{
    return Services::put(['nick' => 'feral1', 'host' => HR_HOST, 'user' => 'xyzjohnny', 'provider' => 'feral', 'transport' => 'tunnel', 'tags' => ['seedbox'],
        'clients' => $clients !== null ? $clients : ['rtorrent' => ['url' => HR_URL, 'user' => 'rutorrent', 'auth' => 'secret:feral1-rpc']]]);
}

/** ServicesRemote::run's records, or the CliError it threw as ['thrown' => message]. */
function srtRun(array $args, array $opt = [])
{
    try {
        return ServicesRemote::run($args, $opt);
    } catch (CliError $e) {
        return ['thrown' => $e->getMessage(), 'hint' => $e->hint];
    }
}

/** Every telemetry file of the torrent-rtorrent source as path => mtime (read only, never written). */
function srtTelemetry()
{
    clearstatcache();
    $out = [];
    foreach ((array) glob(Config::localDir() . '/state/telemetry/torrent-rtorrent/*') as $f) {
        $out[$f] = @filemtime($f);
    }
    return $out;
}

return [
    'services remote tunnel: list, show --trackers, stats and traffic come from the box\'s own RPC as local records with service; stats has no quota and writes no telemetry' => function () {
        $fx = hrFixture();
        try {
            srtFeral();
            ServicesRemote::$requester = hrRequester($fx);
            $r = srtRun(['feral1', 'torrent-clients/rtorrent', 'list', '--state', 'seeding', '--limit', '5']);
            check(!isset($r['thrown']) && count($r['records']) === 1 && $r['records'][0]['service'] === 'feral1' && $r['records'][0]['name'] === 'Seeding One'
                && $r['records'][0]['id'] === str_repeat('a', 40) && !isset($r['records'][0]['native']) && $r['status']['failed'] === [], 'list: ' . json_encode($r));
            $r = srtRun(['feral1', 'torrent-clients/rtorrent', 'show', 'aaaaaaaa', '--trackers']);
            check(!isset($r['thrown']) && count($r['records']) === 2 && $r['records'][0]['seeds'] === 4 && $r['records'][0]['service'] === 'feral1'
                && $r['records'][1]['tracker'] === 'https://tracker.example.org' && $r['records'][1]['torrent'] === str_repeat('a', 40), 'show: ' . json_encode($r));
            check(strpos(json_encode($r), '0123456789abcdef0123') === false, 'a tracker passkey reached a record');
            $before = srtTelemetry();
            $r = srtRun(['feral1', 'torrent-clients/rtorrent', 'stats']);
            $s = isset($r['records'][0]) ? $r['records'][0] : [];
            check(!isset($r['thrown']) && $s['service'] === 'feral1' && $s['version'] === '0.9.8' && $s['torrents'] === 2 && $s['endpoint'] === HR_URL, 'stats: ' . json_encode($r));
            check(!preg_grep('/^(quota_|fs_free|over_quota)/', array_keys($s)), 'a tunnel stats record carries this machine\'s quota: ' . json_encode(array_keys($s)));
            check(srtTelemetry() === $before, 'a tunnel stats run wrote a telemetry series');
            $r = srtRun(['feral1', 'torrent-clients/rtorrent', 'traffic']);
            check(!isset($r['thrown']) && $r['records'][0] === ['service' => 'feral1', 'down_rate' => 10, 'up_rate' => 512, 'down_total' => 3000, 'up_total' => 4500,
                'down_limit' => 0, 'up_limit' => 0], 'traffic: ' . json_encode($r));
            $r = srtRun(['feral1', 'torrent-clients/rtorrent', 'list', '--limit', '0', '--fields', 'name,state']);
            check(!isset($r['thrown']) && count($r['records']) === 2 && $r['records'][1] === ['service' => 'feral1', 'name' => 'Paused Two', 'state' => 'paused'],
                '--limit 0 is no limit and --fields keeps service plus the named fields: ' . json_encode($r));
            check(array_values(array_unique(array_column(hrLog($fx), 'url'))) === [HR_URL] && strpos(json_encode($r), HR_SECRET) === false, 'requests went to the RPC URL only; no secret in records');
        } finally {
            hrDone($fx);
        }
    },
    'services remote tunnel: every changing verb is refused before an intent is recorded or a request made; show --files, other units and a missing RPC entry are one error record each' => function () {
        $fx = hrFixture();
        try {
            srtFeral();
            ServicesRemote::$requester = hrRequester($fx);
            foreach (['add', 'pause', 'resume', 'recheck', 'repath', 'remove'] as $verb) {
                $r = srtRun(['feral1', 'torrent-clients/rtorrent', $verb, str_repeat('a', 40)], ['intent' => "please $verb that torrent on feral1"]);
                check(isset($r['thrown']) && strpos($r['thrown'], 'no management API') !== false && strpos($r['hint'], 'never turn it into an ssh command') !== false, "$verb: " . json_encode($r));
            }
            check(!file_exists($fx['dir'] . '/local/state/intents.jsonl'), 'an intent was recorded for a refused tunnel change');
            foreach ([[['feral1', 'torrent-clients/rtorrent', 'show', 'aaaaaaaa', '--files'], '/show --files is not available/'],
                [['feral1', 'torrent-clients/qbittorrent', 'list'], '/not available for feral1 \(transport tunnel\)/'],
                [['feral1', 'system/system-status', 'health'], '/not available for feral1/'],
                [['feral1', 'torrent-clients/rtorrent', 'list', '--dir', 'x'], '/option --dir is not available/'],
                [['feral1', 'system/system-status', 'account'], '/no customer account API/']] as $c) {
                $r = srtRun($c[0]);
                check(!isset($r['thrown']) && $r['records'][0]['ok'] === false && preg_match($c[1], $r['records'][0]['error']) === 1 && $r['status']['failed'] === ['feral1'],
                    json_encode($c[0]) . ': ' . json_encode($r));
            }
            srtFeral(['rtorrent' => ['socket' => '~/private/rtorrent/.socket', 'data' => null]]); // the Feral preset: no RPC address
            $r = srtRun(['feral1', 'torrent-clients/rtorrent', 'stats']);
            check(!isset($r['thrown']) && $r['records'][0]['ok'] === false && strpos($r['records'][0]['error'], 'not configured') !== false
                && strpos($r['records'][0]['hint'], 'services edit feral1 --clients') !== false, 'not configured: ' . json_encode($r));
            check(hrLog($fx) === [], 'a refused tunnel call made a request: ' . json_encode(hrLog($fx)));
        } finally {
            hrDone($fx);
        }
    },
    'services remote tunnel: --all mixes transports, each service on its own path: the tunnel answers, shell and an unset-up pikkumies box give their own errors' => function () {
        $fx = hrFixture();
        try {
            srtFeral();
            Services::put(['nick' => 'gpu', 'host' => 'gpu.example.com', 'user' => 'xyzjohnny', 'transport' => 'shell', 'tags' => ['seedbox']]);
            Services::put(['nick' => 'hdd1', 'host' => 'box.example.com', 'user' => 'xyzjohnny', 'tags' => ['seedbox']]);
            ServicesRemote::$requester = hrRequester($fx);
            $r = srtRun(['torrent-clients/rtorrent', 'traffic'], ['all' => true]);
            check(!isset($r['thrown']) && $r['status']['services'] === ['feral1', 'gpu', 'hdd1'] && $r['status']['failed'] === ['gpu', 'hdd1'], 'status: ' . json_encode($r));
            $by = [];
            foreach ($r['records'] as $rec) {
                $by[$rec['service']] = $rec;
            }
            check($by['feral1']['up_total'] === 4500 && strpos($by['gpu']['error'], 'no Pikkumies serve') !== false && strpos($by['hdd1']['error'], 'not set up') !== false,
                'records: ' . json_encode($r['records']));
            $r = srtRun(['gpu', 'torrent-clients/rtorrent', 'pause', 'aaaaaaaa'], ['intent' => 'pause it on the gpu box']);
            check(isset($r['thrown']) && strpos($r['thrown'], 'no Pikkumies serve') !== false && !file_exists($fx['dir'] . '/local/state/intents.jsonl'),
                'a change verb to a shell service must be refused before its intent is recorded: ' . json_encode($r));
            $r = srtRun(['torrent-clients/rtorrent', 'pause', 'aaaaaaaa'], ['tag' => 'seedbox', 'intent' => 'pause it everywhere now']);
            check(isset($r['thrown']) && strpos($r['thrown'], 'name one service') !== false, 'a change verb must still go to one service only');
        } finally {
            hrDone($fx);
        }
    },
    'services remote tunnel: Bytesized account reads the accounts API with the key from a pinned secret, keeps each service\'s own account and drops login_password' => function () {
        $fx = hrFixture(['bz1-api' => [SRT_API_KEY, 'bytesized-hosting.com', '/api/v1/'], 'bz2-api' => [SRT_API_KEY, 'bz2.example.com']]);
        try {
            foreach (['bz1' => ['xyzjohnny.priapus.bysh.me', 'secret:bz1-api'], 'bz4' => ['xyzjohnny.hermes.bysh.me', 'secret:bz1-api'],
                'bz5' => ['xyzjohnny.zeus.bysh.me', 'secret:bz1-api'], 'bz2' => ['xyzjohnny.priapus.bysh.me', 'secret:bz2-api'], 'bz3' => ['xyzjohnny.priapus.bysh.me', '']] as $nick => $s) {
                Services::put(['nick' => $nick, 'host' => $s[0], 'user' => 'xyzjohnny', 'provider' => 'bytesized', 'transport' => 'tunnel', 'account_auth' => $s[1], 'tags' => ['bz']]);
            }
            $reply = json_encode([['server_name' => 'priapus', 'paid_till' => '2017-02-16T00:00:00+01:00', 'disk_quota' => 1808587, 'bandwidth_quota' => 2808587,
                'login_password' => SRT_SLOT_LOGIN, 'pretty_disk_quota' => '1000 KB / 150 GB', 'total_storage' => 500.0],
                ['server_name' => 'hermes', 'disk_quota' => 42, 'login_password' => SRT_SLOT_LOGIN]]);
            ServicesRemote::$requester = hrRequester($fx, null, ['code' => 200, 'body' => $reply]);
            $r = srtRun(['bz1', 'system/system-status', 'account']);
            check(!isset($r['thrown']) && count($r['records']) === 1 && $r['records'][0]['service'] === 'bz1' && $r['records'][0]['server_name'] === 'priapus'
                && $r['records'][0]['disk_quota'] === 1808587 && !array_key_exists('login_password', $r['records'][0]), 'account: ' . json_encode($r));
            check(strpos(json_encode($r), SRT_SLOT_LOGIN) === false && strpos(json_encode($r), SRT_API_KEY) === false, 'a secret reached the records');
            $log = hrLog($fx);
            check(count($log) === 1 && $log[0]['method'] === 'GET' && $log[0]['url'] === 'https://' . ServicesRemote::BYTESIZED_HOST . ServicesRemote::BYTESIZED_PATH . '?api_key=' . rawurlencode(SRT_API_KEY),
                'request: ' . json_encode($log));
            $r = srtRun(['bz4', 'system/system-status', 'account']);
            check(count($r['records']) === 1 && $r['records'][0]['server_name'] === 'hermes' && $r['records'][0]['disk_quota'] === 42, 'the second account: ' . json_encode($r));
            $r = srtRun(['system/system-status', 'account'], ['tag' => 'bz']);
            $servers = [];
            foreach ($r['records'] as $rec) {
                if (isset($rec['server_name'])) {
                    $servers[] = $rec['service'] . ':' . $rec['server_name'];
                }
            }
            sort($servers);
            check($servers === ['bz1:priapus', 'bz4:hermes'], 'each account must show once, under its own service: ' . json_encode($r['records']));
            $r = srtRun(['bz5', 'system/system-status', 'account']);
            check($r['records'][0]['ok'] === false && strpos($r['records'][0]['error'], 'no account on this service') !== false
                && strpos($r['records'][0]['hint'], 'priapus, hermes') !== false, 'a service whose server has no account: ' . json_encode($r));
            $before = count(hrLog($fx));
            $r = srtRun(['bz2', 'system/system-status', 'account']);
            check($r['records'][0]['ok'] === false && strpos($r['records'][0]['error'], 'pinned to bz2.example.com, not bytesized-hosting.com') !== false
                && strpos($r['records'][0]['hint'], '--host') === false, 'pin mismatch, and no re-pin command in its hint: ' . json_encode($r));
            $r = srtRun(['bz3', 'system/system-status', 'account']);
            check($r['records'][0]['ok'] === false && strpos($r['records'][0]['error'], 'not configured') !== false, 'no account_auth: ' . json_encode($r));
            file_put_contents($fx['dir'] . '/config.php', "<?php\nreturn ['ssh' => ['deny' => ['bytesized-hosting.com']]];\n");
            $r = srtRun(['bz1', 'system/system-status', 'account']);
            check($r['records'][0]['ok'] === false && strpos($r['records'][0]['error'], 'deny list') !== false, 'the API host on ssh.deny: ' . json_encode($r));
            check(count(hrLog($fx)) === $before, 'the mismatched, unconfigured and denied services must not have sent a request');
            unlink($fx['dir'] . '/config.php');
            ServicesRemote::$requester = hrRequester($fx, null, ['code' => 0, 'error' => 'Could not resolve: api_key=' . rawurlencode(SRT_API_KEY)]);
            $r = srtRun(['bz1', 'system/system-status', 'account']);
            check($r['records'][0]['ok'] === false && strpos(json_encode($r), rawurlencode(SRT_API_KEY)) === false && strpos($r['records'][0]['error'], '[REDACTED]') !== false,
                'a provider error carried the key: ' . json_encode($r));
        } finally {
            hrDone($fx);
        }
    },
];
