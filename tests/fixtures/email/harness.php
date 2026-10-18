<?php
/**
 * Helpers for the email tests: a temporary home with secrets and state directories, stored logins, and a runner for
 * tests/fixtures/email/wrapper.php that returns the JSON Lines and every request the unit made to the fake transport.
 */
require_once dirname(__DIR__, 3) . '/pikkumies/tools/email/lib/MailUnit.php';

const EM_QUOTA_ROOMY = "Disk quotas for user u (uid 1000):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n      /dev/md0      1G   1000G   1250G            100  15000000  18750000\n";

function emFixture()
{
    $dir = testTempDir('pk-email');
    foreach (['home', 'secrets', 'state'] as $d) {
        mkdir("$dir/$d", 0700);
    }
    return ['dir' => $dir, 'home' => realpath("$dir/home"), 'secrets' => "$dir/secrets", 'state' => "$dir/state", 'log' => "$dir/requests.jsonl"];
}

function emDone(array $fx)
{
    testRmTree($fx['dir']);
}

/** Stores a login as the unit's login verb would. */
function emLogin(array $fx, $unit, array $extra = [])
{
    $port = ['imap' => 993, 'pop' => 995, 'smtp' => 465][$unit];
    SecretFile::write($fx['secrets'] . "/email-$unit", ['host' => "$unit.example.org", 'port' => $port, 'user' => 'me@example.org', 'password' => 'pw-secret'] + $extra, 'test login');
}

/** Runs a unit: [exit code, decoded lines, requests made, stderr]. $env may set EM_QUOTA or EM_TYPED. */
function emRun(array $fx, $unit, array $args, array $rules = [], array $env = [])
{
    @unlink($fx['log']);
    file_put_contents($fx['dir'] . '/rules.json', json_encode($rules));
    $env += ['HOME' => $fx['home'], 'PATH' => (string) getenv('PATH'), 'EM_UNIT' => $unit, 'EM_SECRET_DIR' => $fx['secrets'], 'EM_STATE_DIR' => $fx['state'],
        'EM_RULES' => $fx['dir'] . '/rules.json', 'EM_LOG' => $fx['log'], 'EM_QUOTA' => EM_QUOTA_ROOMY];
    $env = array_filter($env, function ($v) {
        return $v !== null;
    });
    // Quoted by hand: escapeshellarg() drops non-ASCII bytes under a C locale on PHP 7.3, which would change a subject.
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/wrapper.php') . implode('', array_map(function ($a) {
        return " '" . str_replace("'", "'\\''", $a) . "'";
    }, $args));
    $p = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    clearstatcache();
    $reqs = is_file($fx['log']) ? array_map(function ($l) {
        return json_decode($l, true);
    }, array_filter(explode("\n", (string) file_get_contents($fx['log'])), 'strlen')) : [];
    return [$code, cliLines($out), array_values($reqs), $err];
}

/** The custom commands of a request list, in order. */
function emCustoms(array $reqs)
{
    return array_values(array_filter(array_column($reqs, 'custom'), 'is_string'));
}

/** Fake answers for an IMAP server with $caps, a folder with $uids (uid => [size, flags]) and messages (uid => raw). */
function emImapRules(array $caps, array $meta = [], array $raw = [], $uidvalidity = 1700000000)
{
    $rules = [['match' => '/^CAPABILITY\|/', 'body' => '* CAPABILITY IMAP4rev1 ' . implode(' ', $caps) . "\r\n"],
        ['match' => '/^STATUS /', 'body' => "* STATUS \"INBOX\" (MESSAGES " . count($meta) . " UNSEEN 0 UIDVALIDITY $uidvalidity UIDNEXT 999)\r\n"],
        ['match' => '/^UID SEARCH /', 'body' => '* SEARCH ' . implode(' ', array_keys($meta)) . "\r\n"]];
    $lines = '';
    foreach ($meta as $uid => $m) {
        $lines .= "* $uid FETCH (UID $uid FLAGS (" . implode(' ', $m[1]) . ") RFC822.SIZE {$m[0]} INTERNALDATE \"17-Oct-2026 10:00:00 +0000\")\r\n";
    }
    $rules[] = ['match' => '/^UID FETCH .*RFC822\.SIZE/', 'body' => $lines];
    foreach ($raw as $uid => $body) {
        $rules[] = ['match' => '#\|imaps://[^|]*/;UID=' . $uid . '(;|$)#', 'body' => $body];
    }
    return $rules;
}

/** A small message; $parts: extra MIME parts as [headers, body] after a text/plain part. */
function emMessage($subject, $text, array $parts = [])
{
    $head = "From: Sender <sender@example.net>\r\nTo: me@example.org\r\nSubject: $subject\r\nDate: Sat, 17 Oct 2026 10:00:00 +0000\r\nMessage-ID: <m1@example.net>\r\n";
    if (!$parts) {
        return $head . "Content-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n";
    }
    $out = $head . "MIME-Version: 1.0\r\nContent-Type: multipart/mixed; boundary=\"BB\"\r\n\r\npreamble\r\n--BB\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n$text\r\n";
    foreach ($parts as $p) {
        $out .= "--BB\r\n{$p[0]}\r\n\r\n{$p[1]}\r\n";
    }
    return $out . "--BB--\r\n";
}
