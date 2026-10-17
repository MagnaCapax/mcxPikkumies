<?php
/**
 * A local `php -S` server for the download tests (tests/HttpDiscardTest.php, tests/PerformanceHeavyTest.php): /bytes?n=N
 * streams N bytes in 64 KiB chunks, /trickle sends 64 KiB every 0.2 s for 10 s, /moved is a 302, anything else a 404.
 */
function perfDiscardServer()
{
    $dir = testTempDir('pk-hd');
    file_put_contents("$dir/router.php", '<?php
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
while (ob_get_level()) { ob_end_flush(); }
if ($p === "/bytes") {
    $n = (int) $_GET["n"];
    header("Content-Length: $n");
    $chunk = str_repeat("x", 65536);
    for ($sent = 0; $sent < $n; $sent += strlen($part)) { $part = substr($chunk, 0, min(65536, $n - $sent)); echo $part; flush(); if (connection_aborted()) { exit; } }
    exit;
}
if ($p === "/trickle") {
    $chunk = str_repeat("y", 65536);
    for ($i = 0; $i < 50; $i++) { echo $chunk; flush(); if (connection_aborted()) { exit; } usleep(200000); }
    exit;
}
if ($p === "/moved") { header("Location: /bytes?n=10", true, 302); exit; }
http_response_code(404);
echo "no";
');
    $srv = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr((string) stream_socket_get_name($srv, false), ':'), 1);
    fclose($srv);
    $proc = proc_open('exec ' . escapeshellarg(PHP_BINARY) . " -S 127.0.0.1:$port " . escapeshellarg("$dir/router.php"),
        [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 50; $i++) {
        $s = @fsockopen('127.0.0.1', $port, $e, $es, 0.1);
        if ($s) {
            fclose($s);
            break;
        }
        usleep(100000);
    }
    return [$proc, "http://127.0.0.1:$port", $dir];
}

function perfDiscardServerDone($fx)
{
    list($proc, , $dir) = $fx;
    proc_terminate($proc);
    proc_close($proc);
    testRmTree($dir);
}
