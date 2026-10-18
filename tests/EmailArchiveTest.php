<?php
/**
 * imap fetch and archive, pop fetch and delete, end to end through the units against the scripted fake transport
 * (ADR-0053, R15 CWE-22 and CWE-400): attachments land only in a new directory, the archive resumes, starts a new
 * directory when UIDVALIDITY changes, never deletes, and stops before the quota would fall under 10% free.
 */
require_once __DIR__ . '/fixtures/email/harness.php';

/** A `quota -s` line: used and soft limit in MiB. */
function emQuota($usedMiB, $softMiB)
{
    return "Disk quotas for user u (uid 1000):\n Filesystem space quota limit grace files quota limit grace\n /dev/md0 {$usedMiB}M {$softMiB}M " . ($softMiB * 2) . "M 10 1000000 2000000\n";
}

return [
    'email fetch: a hostile attachment name lands as a plain file in a new directory; --to an existing or dot folder is refused' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            mkdir($fx['home'] . '/.ssh');
            $msg = emMessage('Invoice', 'see attached', [["Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=\"../../.ssh/authorized_keys\"\r\nContent-Transfer-Encoding: base64", base64_encode('ssh-ed25519 AAAA attacker')]]);
            $rules = emImapRules(['UIDPLUS'], [5 => [strlen($msg), ['\\Seen']]], [5 => $msg]);
            list($code, $lines) = emRun($fx, 'imap', ['fetch', '5'], $rules);
            check($code === 0, 'fetch failed: ' . json_encode($lines));
            $path = $lines[0]['path'];
            check(basename($path) === 'authorized_keys' && strpos($path, $fx['home'] . '/mail-attachments/INBOX-5-') === 0, 'saved at ' . $path);
            check(!file_exists($fx['home'] . '/.ssh/authorized_keys') && (fileperms($path) & 0777) === 0600, 'the attachment escaped or is not 0600');
            foreach ([$fx['home'] . '/.ssh', $fx['home'] . '/mail-attachments', $fx['home'] . '/.ssh/x'] as $to) {
                list($code, $lines) = emRun($fx, 'imap', ['fetch', '5', '--to', $to], $rules);
                check($code === 1, "--to $to was accepted: " . json_encode($lines));
            }
            check(!file_exists($fx['home'] . '/.ssh/x'), 'a directory was created under ~/.ssh');
        } finally {
            emDone($fx);
        }
    },
    'email archive: incremental and resumable, a changed UIDVALIDITY starts a new directory, and nothing is deleted on the server' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $raw = [1 => emMessage('a', 'one'), 2 => emMessage('b', 'two'), 3 => emMessage('c', 'three')];
            $meta = [1 => [strlen($raw[1]), ['\\Seen']], 2 => [strlen($raw[2]), []]];
            $to = $fx['home'] . '/mail-archive';
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['archive', '--to', $to], emImapRules(['UIDPLUS'], $meta, $raw, 77));
            check($code === 0 && $lines[0]['archived'] === 2 && count(glob("$to/INBOX-77/new/*")) === 2, 'first run wrong: ' . json_encode($lines));
            check(in_array('UID STORE 2 -FLAGS.SILENT (\\Seen)', emCustoms($reqs), true), 'the unseen message was left seen');
            $meta[3] = [strlen($raw[3]), []];
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['archive', '--to', $to], emImapRules(['UIDPLUS'], $meta, $raw, 77));
            check($code === 0 && $lines[0]['archived'] === 1 && count(glob("$to/INBOX-77/new/*")) === 3, 'second run not incremental: ' . json_encode($lines));
            check(count(preg_grep('#/;UID=#', array_column($reqs, 'url'))) === 1, 'already archived messages were fetched again');
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['archive', '--to', $to], emImapRules(['UIDPLUS'], $meta, $raw, 78));
            check($code === 0 && $lines[0]['archived'] === 3 && count(glob("$to/INBOX-78/new/*")) === 3 && count(glob("$to/INBOX-77/new/*")) === 3,
                'a new UIDVALIDITY did not start a new directory: ' . json_encode($lines));
            foreach (emCustoms($reqs) as $c) {
                check(strpos($c, 'Deleted') === false && strpos($c, 'EXPUNGE') === false, "the archive changed the server: $c");
            }
            check(count(glob($fx['dir'] . '/logs/email/*.jsonl')) === 1, 'no run log written');
        } finally {
            emDone($fx);
        }
    },
    'email archive: stops before the quota would fall under 10% free, keeps what it wrote and resumes after' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $body = emMessage('big', str_repeat('z', 600000));
            $raw = [1 => $body, 2 => $body, 3 => $body];
            $meta = [1 => [strlen($body), []], 2 => [strlen($body), []], 3 => [strlen($body), []]];
            $to = $fx['home'] . '/arch';
            // 1000 MiB limit, 898.9 MiB used: room for one message before 10% (100 MiB) would be crossed.
            $quota = emQuota('898.9', 1000);
            list($code, $lines) = emRun($fx, 'imap', ['archive', '--to', $to], emImapRules(['UIDPLUS'], $meta, $raw, 5), ['EM_QUOTA' => $quota]);
            $status = end($lines);
            check($code === 0 && isset($status['stopped']) && strpos($status['stopped'], '10%') !== false, 'no quota stop: ' . json_encode($lines));
            check($lines[0]['archived'] === 1 && count(glob("$to/INBOX-5/new/*")) === 1, 'wrong number written before the stop');
            list($code, $lines) = emRun($fx, 'imap', ['archive', '--to', $to], emImapRules(['UIDPLUS'], $meta, $raw, 5));
            check($code === 0 && $lines[0]['archived'] === 2 && count(glob("$to/INBOX-5/new/*")) === 3, 'did not resume after the stop: ' . json_encode($lines));
            check(Maildir::roomProblem(['quota_free_bytes' => 10 ** 9, 'quota_limit_bytes' => 10 ** 10, 'fs_free_bytes' => null], ['used' => 899, 'soft' => 1000, 'hard' => 1250], 0, 0, 1, 0.10) !== '',
                'the file quota reserve is not checked');
        } finally {
            emDone($fx);
        }
    },
    'email pop: fetch downloads each UIDL once into a Maildir; delete checks every UIDL first and reads the result back' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'pop');
            $m = emMessage('p', 'pop body');
            $rules = [['match' => '/^UIDL\|/', 'body' => "1 aaa111\r\n2 bbb222\r\n"], ['match' => '#^\|pop3s://[^/]+/$#', 'body' => '1 ' . strlen($m) . "\r\n2 " . strlen($m) . "\r\n"],
                ['match' => '#^\|pop3s://[^/]+/[12]$#', 'body' => $m]];
            $to = $fx['home'] . '/popmail';
            list($code, $lines) = emRun($fx, 'pop', ['fetch', '--to', $to], $rules);
            check($code === 0 && $lines[0]['fetched'] === 2 && count(glob("$to/new/*")) === 2, 'pop fetch wrong: ' . json_encode($lines));
            list($code, $lines, $reqs) = emRun($fx, 'pop', ['fetch', '--to', $to], $rules);
            check($code === 0 && $lines[0]['fetched'] === 0 && !preg_grep('#/[12]$#', array_column($reqs, 'url')), 'a fetched message was downloaded again');
            list($code, $lines, $reqs) = emRun($fx, 'pop', ['delete', '2', '--expect', 'aaa111'], $rules);
            check($code === 1 && !in_array('DELE', emCustoms($reqs), true), 'a UIDL mismatch still deleted');
            $after = $rules;
            array_unshift($after, ['match' => '/^UIDL\|/', 'body' => "1 aaa111\r\n2 bbb222\r\n", 'once' => true]);
            $after[1] = ['match' => '/^UIDL\|/', 'body' => "1 aaa111\r\n"];
            list($code, $lines, $reqs) = emRun($fx, 'pop', ['delete', '2', '--expect', 'bbb222'], $after);
            $dele = array_values(array_filter($reqs, function ($r) {
                return $r['custom'] === 'DELE';
            }));
            check($code === 0 && count($dele) === 1 && substr($dele[0]['url'], -2) === '/2' && $dele[0]['nobody'] && end($lines)['verified'] === true, 'pop delete wrong: ' . json_encode($lines));
        } finally {
            emDone($fx);
        }
    },
];
