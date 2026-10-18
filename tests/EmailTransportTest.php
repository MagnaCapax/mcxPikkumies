<?php
/**
 * lib/MailTransport.php (ADR-0053, R15 CWE-295/319 and CWE-400): TLS options always forced, only the four TLS mail
 * ports, plain host names only, the byte cap in the write callback, and against plaintext fake servers on 127.0.0.1
 * that no login is ever sent without TLS.
 */
require_once __DIR__ . '/fixtures/email/harness.php';

/** Starts plainserver.php; returns [process, port, log]. */
function emPlainServer($proto, $dir)
{
    $port = testFreePort();
    $log = "$dir/$proto.log";
    $p = proc_open(implode(' ', array_map('escapeshellarg', [PHP_BINARY, __DIR__ . '/fixtures/email/plainserver.php', (string) $port, $log, $proto])), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
    for ($i = 0; $i < 100 && !is_file("$log.ready"); $i++) {
        usleep(50000);
    }
    return [$p, $port, $log];
}

return [
    'email transport: only TLS mail ports with their scheme; 25, 110, 143 and mixed pairs are refused' => function () {
        foreach ([['imaps', 993], ['pop3s', 995], ['smtps', 465], ['smtp', 587]] as $ok) {
            MailTransport::endpoint($ok[0], 'mail.example.org', $ok[1]);
        }
        foreach ([['smtp', 25], ['imaps', 143], ['pop3s', 110], ['imaps', 995], ['smtp', 465], ['imap', 993], ['smtps', 587]] as $bad) {
            $refused = false;
            try {
                MailTransport::endpoint($bad[0], 'mail.example.org', $bad[1]);
            } catch (CliError $e) {
                $refused = true;
            }
            check($refused, "{$bad[0]} on {$bad[1]} was accepted");
        }
    },
    'email transport: host names starting with - or holding /, :, @, .., spaces or a newline are refused' => function () {
        foreach (['-oProxyCommand=x', 'evil.example/x', 'host:25', 'u@host', 'a..b', 'a b', "imap.example.org\n", '.example.org', ''] as $bad) {
            $refused = false;
            try {
                MailTransport::endpoint('imaps', $bad, 993);
            } catch (CliError $e) {
                $refused = true;
            }
            check($refused, 'host ' . json_encode($bad) . ' was accepted');
        }
    },
    'email transport: every request forces certificate and host checks, TLS 1.2 or newer, TLS required and one protocol' => function () {
        foreach ([['imaps', 993, CURLPROTO_IMAPS], ['smtp', 587, CURLPROTO_SMTP], ['pop3s', 995, CURLPROTO_POP3S]] as $c) {
            $t = new MailTransport($c[0], 'mail.example.org', $c[1], 'me', 'pw');
            $o = $t->options(['url' => 'x', 'upload' => 'm', 'from' => 'a@b.example', 'rcpt' => ['c@d.example']], function ($s) {
                return strlen($s);
            });
            check($o[CURLOPT_SSL_VERIFYPEER] === true && $o[CURLOPT_SSL_VERIFYHOST] === 2 && $o[CURLOPT_USE_SSL] === CURLUSESSL_ALL
                && $o[CURLOPT_SSLVERSION] === CURL_SSLVERSION_TLSv1_2 && $o[CURLOPT_PROTOCOLS] === $c[2] && $o[CURLOPT_FOLLOWLOCATION] === false,
                "{$c[0]}: TLS options not forced");
            check(!isset($o[CURLOPT_CAINFO]) && !isset($o[CURLOPT_CAPATH]), 'a CA file must never come from anywhere but the system');
            check($o[CURLOPT_MAIL_RCPT] === ['<c@d.example>'] && $o[CURLOPT_MAIL_FROM] === '<a@b.example>', 'envelope wrong');
        }
    },
    'email transport: the upload callback hands out the message once, in order, by offset (a 20 MiB message in 16 KiB reads is quick)' => function () {
        $t = new MailTransport('smtps', 'mail.example.org', 465, 'me', 'pw');
        $read = $t->options(['url' => 'x', 'upload' => 'abcdefghij', 'from' => 'a@b.example', 'rcpt' => ['c@d.example']], 'strlen')[CURLOPT_READFUNCTION];
        $got = [$read(null, null, 3), $read(null, null, 4), $read(null, null, 100), $read(null, null, 5)];
        check($got === ['abc', 'defg', 'hij', ''], 'pieces: ' . json_encode($got));
        $msg = str_repeat('0123456789abcdef', 1310720); // 20 MiB
        $read = $t->options(['url' => 'x', 'upload' => $msg, 'from' => 'a@b.example', 'rcpt' => ['c@d.example']], 'strlen')[CURLOPT_READFUNCTION];
        $start = microtime(true);
        $n = 0;
        $hash = hash_init('sha256');
        while (($piece = $read(null, null, 16384)) !== '') {
            $n += strlen($piece);
            hash_update($hash, $piece);
        }
        // Copying the rest on every call moved about 13 GB here (several seconds); an offset moves the 20 MiB once.
        check($n === strlen($msg) && hash_final($hash) === hash('sha256', $msg) && microtime(true) - $start < 1.5, 'upload slow or wrong: ' . round(microtime(true) - $start, 2) . ' s');
    },
    'email transport: the byte cap stops in the write callback; a recipient with CR/LF or <> never reaches curl' => function () {
        $t = new MailTransport('imaps', 'mail.example.org', 993, 'me', 'pw');
        MailTransport::$fake = function () {
            return ['body' => str_repeat('x', 100000)];
        };
        try {
            $got = 0;
            $stopped = false;
            try {
                $t->request('/', ['max' => 50000, 'sink' => function ($chunk) use (&$got) {
                    $got += strlen($chunk);
                    return true;
                }]);
            } catch (CliError $e) {
                $stopped = strpos($e->getMessage(), 'larger than 50000') !== false;
            }
            check($stopped && $got <= 50000, "the cap did not stop the transfer (got $got bytes)");
            check(strlen($t->request('/', ['max' => 100000])) === 100000, 'a body at the cap must pass');
            $calls = 0;
            MailTransport::$fake = function () use (&$calls) {
                $calls++;
                return ['body' => ''];
            };
            foreach (["a@b.example>\r\nRCPT TO:<evil@x.example", '<a@b.example>', "a@b.example\n"] as $bad) {
                try {
                    $t->request('/', ['upload' => 'x', 'from' => 'me@example.org', 'rcpt' => [$bad]]);
                } catch (CliError $e) {
                    // refused, as it must be
                }
            }
            check($calls === 0, 'a bad recipient reached the transport');
        } finally {
            MailTransport::$fake = null;
        }
    },
    'email transport: a plaintext SMTP server on 587 gets EHLO and no login; a plaintext server on an imaps port gets no LOGIN' => function () {
        if (!function_exists('curl_init')) {
            skip('no PHP curl here');
        }
        $dir = testTempDir('pk-email-plain');
        try {
            list($p, $port, $log) = emPlainServer('smtp', $dir);
            MailTransport::$extraPorts = [$port => 'smtp'];
            $err = '';
            try {
                (new MailTransport('smtp', '127.0.0.1', $port, 'me@example.org', 'pw-secret'))->request('/', ['custom' => 'NOOP', 'nobody' => true]);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            proc_close($p);
            $wire = (string) @file_get_contents($log);
            check(strpos($err, 'offers no TLS') !== false, 'plaintext SMTP was not refused: ' . $err);
            check(stripos($wire, 'EHLO') !== false && stripos($wire, 'AUTH') === false && strpos($wire, 'pw-secret') === false, 'a login went out in plaintext: ' . $wire);

            list($p, $port, $log) = emPlainServer('imap', $dir);
            MailTransport::$extraPorts = [$port => 'imaps'];
            $err = '';
            try {
                (new MailTransport('imaps', '127.0.0.1', $port, 'me@example.org', 'pw-secret'))->request('/', ['custom' => 'CAPABILITY']);
            } catch (CliError $e) {
                $err = $e->getMessage();
            }
            proc_close($p);
            $wire = (string) @file_get_contents($log);
            check(strpos($err, 'TLS failed') !== false, 'plaintext IMAP was not refused as a TLS failure: ' . $err);
            check(stripos($wire, 'LOGIN') === false && stripos($wire, 'AUTHENTICATE') === false && strpos($wire, 'pw-secret') === false, 'a login went out in plaintext');
        } finally {
            MailTransport::$extraPorts = [];
            testRmTree($dir);
        }
    },
];
