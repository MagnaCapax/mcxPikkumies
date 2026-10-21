<?php
/**
 * Helpers for tests/RtorrentHttpRpcTest.php and tests/ServicesRemoteTunnelTest.php (ADR-0105): a temp secret store and
 * services list behind the seams (Secrets::$vaultFile, SecretsCache dirs, Services::$localDir/$configFile), and a fake
 * requester in place of Http::request that answers rTorrent XML-RPC from a method => value map and logs every request
 * to a file (so calls made in a forked fan-out worker are seen too). Nothing real is contacted.
 */
$hrRoot = dirname(__DIR__, 3) . '/pikkumies';
require_once "$hrRoot/tools/torrent-clients/lib/Torrents.php";
require_once "$hrRoot/tools/torrent-clients/rtorrent/RtorrentClient.php";
require_once "$hrRoot/tools/torrent-clients/rtorrent/HttpRpc.php";
require_once "$hrRoot/tools/seedbox/services/ServeGate.php";
require_once "$hrRoot/tools/seedbox/services/ServicesKeys.php";
require_once "$hrRoot/tools/seedbox/services/ServicesRemote.php";

const HR_HOST = 'kepler.feralhosting.com';
const HR_URL = 'https://kepler.feralhosting.com/xyzjohnny/rtorrent/rpc';
const HR_SECRET = 'Rpc+Login/0racle=7';
const HR_PHRASE = 'fixture-phrase-for-httprpc';

/** A temp dir with an unlocked store holding $secrets (handle => [value, pinned host or null, pinned path prefix]) and an empty services list. */
function hrFixture(array $secrets = ['feral1-rpc' => [HR_SECRET, HR_HOST]])
{
    if (!extension_loaded('sodium')) {
        skip('this PHP has no sodium extension');
    }
    $dir = testTempDir('pk-httprpc');
    $fx = ['dir' => $dir, 'vault' => "$dir/local/secrets/vault.enc", 'log' => "$dir/requests.jsonl", 'oldHome' => getenv('HOME')];
    foreach (['home' => 0700, 'run' => 0700, 'shm' => 01777, 'local' => 0700] as $k => $mode) {
        @mkdir("$dir/$k", 0700, true);
        chmod("$dir/$k", $mode);
    }
    // The run dir must be outside HOME: the suite's temp dir can be inside the real home.
    putenv('HOME=' . "$dir/home");
    Secrets::$vaultFile = $fx['vault'];
    SecretsCache::$runtimeBase = "$dir/run";
    SecretsCache::$shmDir = "$dir/shm";
    Services::$localDir = "$dir/local";
    Services::$configFile = "$dir/config.php";
    $entries = [];
    foreach ($secrets as $h => $s) {
        $entries[$h] = ['value' => $s[0], 'kind' => 'password', 'added_at' => '2026-10-21T00:00:00Z', 'rotated_at' => null] + ($s[1] !== null ? ['host' => $s[1]] : [])
            + (isset($s[2]) ? ['path' => $s[2]] : []);
    }
    $kdf = Secrets::newKdf();
    $key = Secrets::deriveKey(HR_PHRASE, $kdf);
    Secrets::write(['secrets' => $entries], $key, $kdf);
    SecretsCache::put($fx['vault'], $key, $kdf['salt'], 3600);
    return $fx;
}

function hrDone(array $fx)
{
    SecretsCache::forget($fx['vault']);
    Secrets::$vaultFile = Secrets::$now = SecretsCache::$runtimeBase = SecretsCache::$shmDir = null;
    Services::$localDir = Services::$configFile = null;
    ServicesRemote::$requester = null;
    putenv('HOME=' . $fx['oldHome']);
    testRmTree($fx['dir']);
}

/** A methodResponse carrying $v (encoded by the unit's own encoder). */
function hrReply($v)
{
    return '<?xml version="1.0"?><methodResponse><params><param>' . RtorrentXmlRpc::value($v) . '</param></params></methodResponse>';
}

function hrFault($text)
{
    return '<?xml version="1.0"?><methodResponse><fault><value><struct><member><name>faultCode</name><value><int>-506</int></value></member>'
        . '<member><name>faultString</name><value><string>' . htmlspecialchars($text, ENT_XML1) . '</string></value></member></struct></value></fault></methodResponse>';
}

/** One d.multicall2 row in RtorrentRecords::FIELDS order. */
function hrRow($hash, $name, array $over = [])
{
    $d = ['d.hash=' => $hash, 'd.name=' => $name, 'd.size_bytes=' => 1000, 'd.completed_bytes=' => 1000, 'd.complete=' => 1, 'd.state=' => 1,
        'd.is_open=' => 1, 'd.is_active=' => 1, 'd.hashing=' => 0, 'd.is_hash_checking=' => 0, 'd.ratio=' => 1500, 'd.down.rate=' => 0, 'd.up.rate=' => 512,
        'd.custom=addtime' => '1700000000', 'd.load_date=' => 1700000000, 'd.custom1=' => 'TV', 'd.directory=' => '/media/box/data', 'd.base_path=' => '',
        'd.is_multi_file=' => 0, 'd.message=' => '', 'd.peers_connected=' => 2, 'd.timestamp.finished=' => 1700100000, 'd.is_private=' => 1, 'd.up.total=' => 1500];
    $out = [];
    foreach (RtorrentRecords::FIELDS as $f) {
        $out[] = array_key_exists($f, $over) ? $over[$f] : $d[$f];
    }
    return $out;
}

/** The answers of a small fake rTorrent: two torrents, versions, traffic, trackers; system.startup_time is not defined. */
function hrMethods()
{
    $tracker = ['https://tracker.example.org/0123456789abcdef0123/announce', 0, 1, 3, 0, 1700000500, 0, 10, 2, 1700000500];
    return ['d.multicall2' => [hrRow(str_repeat('A', 40), 'Seeding One'), hrRow(str_repeat('B', 40), 'Paused Two', ['d.state=' => 0, 'd.up.rate=' => 0])],
        'system.time' => 1700200000, 'system.client_version' => '0.9.8', 'system.library_version' => '0.13.8', 'system.pid' => 4242,
        'network.listen.port' => 51413, 'throttle.global_down.rate' => 10, 'throttle.global_up.rate' => 512, 'throttle.global_down.total' => 3000,
        'throttle.global_up.total' => 4500, 'throttle.global_down.max_rate' => 0, 'throttle.global_up.max_rate' => 0,
        'd.creation_date' => 1690000000, 'd.timestamp.started' => 1700000000, 'd.timestamp.finished' => 1700100000, 'd.down.total' => 1000,
        'd.up.total' => 1500, 'd.peers_connected' => 2, 'd.peers_complete' => 4, 'd.size_files' => 1, 'd.is_private' => 1, 'd.priority' => 2,
        't.multicall' => [$tracker]];
}

/**
 * A requester like Http::request. $methods: method => value (XML-RPC answer); $raw: a full Http-shaped answer returned as is
 * (code, body, error) for every request instead. Every request is one JSON line in $fx['log']: method, url, headers, body, opt.
 */
function hrRequester(array $fx, array $methods = null, array $raw = null)
{
    $methods = $methods !== null ? $methods : hrMethods();
    $log = $fx['log'];
    return function ($method, $url, array $o) use ($methods, $raw, $log) {
        $body = isset($o['body']) ? (string) $o['body'] : '';
        file_put_contents($log, json_encode(['method' => $method, 'url' => $url, 'headers' => isset($o['headers']) ? $o['headers'] : [], 'body' => $body,
            'opt' => array_diff_key($o, ['headers' => 1, 'body' => 1])]) . "\n", FILE_APPEND | LOCK_EX);
        if ($raw !== null) {
            return $raw + ['code' => 0, 'body' => '', 'headers' => [], 'error' => ''];
        }
        $name = preg_match('#<methodName>([^<]*)</methodName>#', $body, $m) ? $m[1] : '';
        return ['code' => 200, 'body' => array_key_exists($name, $methods) ? hrReply($methods[$name]) : hrFault("Method '$name' not defined"), 'headers' => [], 'error' => ''];
    };
}

/** The logged requests, oldest first. */
function hrLog(array $fx)
{
    clearstatcache();
    return is_file($fx['log']) ? array_map(function ($l) {
        return json_decode($l, true);
    }, array_values(array_filter(explode("\n", (string) file_get_contents($fx['log'])), 'strlen'))) : [];
}

/** The CliError $fn throws, or null; its message and hint must never hold $secret. */
function hrError(callable $fn, $secret = HR_SECRET)
{
    try {
        $fn();
    } catch (CliError $e) {
        check(strpos($e->getMessage() . ' ' . $e->hint, $secret) === false && strpos($e->getMessage() . ' ' . $e->hint, base64_encode('rutorrent:' . $secret)) === false,
            'a refusal carries the secret: ' . $e->getMessage());
        return $e;
    }
    return null;
}

/** True when $fn throws a CliError whose message matches $re; the message and hint must never hold $secret. */
function hrRefuses(callable $fn, $re, $secret = HR_SECRET)
{
    $e = hrError($fn, $secret);
    return $e !== null && preg_match($re, $e->getMessage()) === 1;
}
