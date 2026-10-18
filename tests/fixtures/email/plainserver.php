<?php
/**
 * A plaintext mail server for one connection on 127.0.0.1 (test code only): php plainserver.php <port> <log> smtp|imap.
 * It offers no TLS. Every line received is logged as JSON; a `ready` file next to the log says it is listening.
 */
list(, $port, $log, $proto) = $argv;
$srv = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if ($srv === false) {
    exit(1);
}
file_put_contents("$log.ready", '1');
$c = @stream_socket_accept($srv, 10);
if ($c === false) {
    exit(0);
}
stream_set_timeout($c, 5);
$out = fopen($log, 'w');
fwrite($c, $proto === 'smtp' ? "220 plain fake\r\n" : "* OK plain fake\r\n");
while (($l = fgets($c)) !== false) {
    fwrite($out, json_encode($l, JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
    if ($proto === 'smtp') {
        if (stripos($l, 'QUIT') === 0) {
            fwrite($c, "221 bye\r\n");
            break;
        }
        fwrite($c, stripos($l, 'EHLO') === 0 ? "250-plain fake\r\n250 AUTH PLAIN LOGIN\r\n" : "250 ok\r\n");
    }
}
