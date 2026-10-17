<?php
/**
 * lib/Http.php `discard` option (the performance unit's download test, ADR-0035): the response bytes are counted and
 * none are kept, the byte cap and the time cap end the transfer early and are reported as `stopped`, not as an error,
 * and a real failure still is one. Against a local `php -S` fixture only; no outside network.
 */

require_once __DIR__ . '/fixtures/performance/discard-server.php';

if (!function_exists('hdNeedCurl')) {
    function hdNeedCurl()
    {
        if (!function_exists('curl_init')) {
            skip('curl extension missing');
        }
    }
}

return [
    'http discard: every byte of a whole response is counted, the body is empty, and it ended by itself (not stopped)' => function () {
        hdNeedCurl();
        $fx = perfDiscardServer();
        try {
            $n = 3 * 1048576 + 12345;
            $r = Http::request('GET', $fx[1] . "/bytes?n=$n", ['discard' => true, 'max' => 64 * 1048576, 'timeout' => 20]);
            check($r['code'] === 200 && $r['error'] === '', 'discard download failed: ' . json_encode($r));
            check($r['bytes'] === $n, "counted {$r['bytes']} bytes, the response had $n");
            check($r['body'] === '' && $r['stopped'] === null, 'body kept or wrongly marked stopped: ' . json_encode(['body_len' => strlen($r['body']), 'stopped' => $r['stopped']]));
            check(is_float($r['total_time']) && $r['total_time'] > 0 && $r['start_time'] <= $r['total_time'], 'times: ' . json_encode($r));
        } finally {
            perfDiscardServerDone($fx);
        }
    },
    'http discard: the body is not kept in memory (a 48 MiB download leaves the peak far below 48 MiB, in a fresh process)' => function () {
        hdNeedCurl();
        $fx = perfDiscardServer();
        $script = $fx[2] . '/mem.php';
        try {
            $n = 48 * 1048576;
            file_put_contents($script, '<?php
require ' . var_export(dirname(__DIR__) . '/pikkumies/lib/Http.php', true) . ';
$before = memory_get_peak_usage();
$r = Http::request("GET", ' . var_export($fx[1] . "/bytes?n=$n", true) . ', ["discard" => true, "max" => 64 * 1048576, "timeout" => 30]);
echo json_encode(["bytes" => $r["bytes"], "grew" => memory_get_peak_usage() - $before, "error" => $r["error"]]);
');
            list($code, $out) = runPhp($script, []);
            $got = json_decode($out, true);
            check($code === 0 && is_array($got) && $got['error'] === '' && $got['bytes'] === $n, 'child run: ' . $out);
            check($got['grew'] < 8 * 1048576, 'peak memory grew by ' . $got['grew'] . " bytes for a $n byte download: the body was kept");
        } finally {
            perfDiscardServerDone($fx);
        }
    },
    'http discard: the byte cap stops the transfer near the cap, reported as stopped "bytes" with no error' => function () {
        hdNeedCurl();
        $fx = perfDiscardServer();
        try {
            $cap = 1048576;
            $r = Http::request('GET', $fx[1] . '/bytes?n=' . (64 * 1048576), ['discard' => true, 'max' => $cap, 'timeout' => 20]);
            check($r['stopped'] === 'bytes' && $r['error'] === '' && $r['code'] === 200, 'cap not reported as a clean stop: ' . json_encode($r));
            check($r['bytes'] >= $cap && $r['bytes'] < $cap + 262144, "transfer did not stop at the cap: {$r['bytes']} bytes for a $cap cap");
            check($r['body'] === '', 'body kept');
        } finally {
            perfDiscardServerDone($fx);
        }
    },
    'http discard: the time cap ends a slow transfer after the first bytes, reported as stopped "time" with no error' => function () {
        hdNeedCurl();
        $fx = perfDiscardServer();
        try {
            $t = microtime(true);
            $r = Http::request('GET', $fx[1] . '/trickle', ['discard' => true, 'max' => 64 * 1048576, 'timeout' => 1]);
            $took = microtime(true) - $t;
            check($took < 3.0, "a 1 s time cap took {$took}s");
            check($r['stopped'] === 'time' && $r['error'] === '' && $r['bytes'] > 0 && $r['bytes'] < 50 * 65536, 'time cap not a clean stop: ' . json_encode($r));
        } finally {
            perfDiscardServerDone($fx);
        }
    },
    'http discard: a refused connection is still an error with code 0 and nothing counted, never a "stop"' => function () {
        hdNeedCurl();
        $srv = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr((string) stream_socket_get_name($srv, false), ':'), 1);
        fclose($srv); // nobody listens there now
        $r = Http::request('GET', "http://127.0.0.1:$port/x", ['discard' => true, 'max' => 1048576, 'timeout' => 3]);
        check($r['code'] === 0 && $r['error'] !== '' && $r['bytes'] === 0 && $r['stopped'] === null, 'a failed connection looked like a result: ' . json_encode($r));
    },
    'http discard: an HTTP error answer keeps its real code, and without discard the old shape has no discard fields' => function () {
        hdNeedCurl();
        $fx = perfDiscardServer();
        try {
            $r = Http::request('GET', $fx[1] . '/missing', ['discard' => true, 'max' => 1048576]);
            check($r['code'] === 404 && $r['bytes'] === 2 && $r['body'] === '' && $r['stopped'] === null, '404 under discard: ' . json_encode($r));
            $plain = Http::request('GET', $fx[1] . '/bytes?n=10');
            check($plain['body'] === str_repeat('x', 10) && !array_key_exists('bytes', $plain) && !array_key_exists('stopped', $plain), 'the plain request changed shape: ' . json_encode($plain));
        } finally {
            perfDiscardServerDone($fx);
        }
    },
];
