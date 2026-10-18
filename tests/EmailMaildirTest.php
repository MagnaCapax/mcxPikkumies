<?php
/**
 * Writing mail into Maildirs (ADR-0053): imap archive names a folder's directory from its whole path, pop fetch counts
 * only new messages and keeps its UIDL state as large as the mailbox, both take --to by one rule, Maildir never
 * replaces a file and is never blocked by a leftover in tmp/, and the run log is pruned by sessions.retentionDays.
 */
require_once __DIR__ . '/fixtures/email/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/email/imap/ImapArchive.php';

/** Fake POP answers: UIDL and LIST for $uidls (n => uidl), and every message is $m. */
function emPopRules(array $uidls, $m)
{
    $u = $l = '';
    foreach ($uidls as $n => $uidl) {
        $u .= "$n $uidl\r\n";
        $l .= "$n " . strlen($m) . "\r\n";
    }
    return [['match' => '/^UIDL\|/', 'body' => $u], ['match' => '#^\|pop3s://[^/]+/$#', 'body' => $l], ['match' => '#^\|pop3s://[^/]+/\d+$#', 'body' => $m]];
}

return [
    'email archive: Work/2023 and Old/2023 get their own directories; an archive under the old name starts again, the old directory kept' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $m = emMessage('a', 'one');
            $rules = emImapRules(['UIDPLUS'], [1 => [strlen($m), ['\\Seen']]], [1 => $m], 77);
            $rules[] = ['match' => '/^LIST /', 'body' => "* LIST (\\HasNoChildren) \"/\" \"Work/2023\"\r\n* LIST (\\HasNoChildren) \"/\" \"Old/2023\"\r\n"];
            $to = $fx['home'] . '/arch';
            list($code, $lines) = emRun($fx, 'imap', ['archive', '--all', '--to', $to], $rules);
            check($code === 0 && $lines[0]['archived'] === 1 && $lines[1]['archived'] === 1 && $lines[0]['dir'] !== $lines[1]['dir'], 'one folder was skipped: ' . json_encode($lines));
            check(count(glob("$to/Work.2023-*-77/new/*")) === 1 && count(glob("$to/Old.2023-*-77/new/*")) === 1, 'directories: ' . json_encode(glob("$to/*")));
            check(ImapArchive::dirName('INBOX') === 'INBOX' && ImapArchive::dirName('Work.2023') === 'Work.2023' && ImapArchive::dirName('Work/2023') !== 'Work.2023', 'dir names');
            // An archive made by the older naming (last path part only) for Work/2023.
            $real = realpath($to);
            $old = "$real/2023-77";
            Maildir::ensure($old);
            file_put_contents("$old/new/U1.V77.pikkumies", 'kept');
            $state = $fx['state'] . '/email-archive/' . sha1("imap.example.org\0me@example.org\0Work/2023\0$real") . '.json';
            MailUnit::writeState($state, ['uidvalidity' => 77, 'last_uid' => 1, 'dir' => $old]);
            exec('rm -rf ' . escapeshellarg($real) . '/Work.2023-*');
            list($code, $lines) = emRun($fx, 'imap', ['archive', '--folder', 'Work/2023', '--to', $to], $rules);
            check($code === 0 && $lines[0]['archived'] === 1 && $lines[0]['previous_dir'] === $old, 'old-name archive did not start again: ' . json_encode($lines));
            check(file_get_contents("$old/new/U1.V77.pikkumies") === 'kept', 'the old directory was changed');
        } finally {
            emDone($fx);
        }
    },
    'email pop: fetched counts only new messages, --limit 0 takes all, the UIDL state keeps only what the server still has' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'pop');
            $m = emMessage('p', 'pop body');
            $to = $fx['home'] . '/popmail';
            $rules = emPopRules([1 => 'aaa', 2 => 'bbb', 3 => 'ccc'], $m);
            Maildir::ensure($to);
            file_put_contents("$to/new/P" . substr(sha1('aaa'), 0, 20) . '.pikkumies', $m); // there already; the state was lost
            $state = $fx['state'] . '/email-pop/' . sha1("pop.example.org\0me@example.org") . '.json';
            MailUnit::writeState($state, ['uidl' => ['gone-from-server']]);
            list($code, $lines, $reqs) = emRun($fx, 'pop', ['fetch', '--to', $to, '--limit', '0'], $rules);
            check($code === 0 && $lines[0]['fetched'] === 2 && $lines[0]['left'] === 0 && count(glob("$to/new/*")) === 3, 'fetch counts: ' . json_encode($lines));
            check(!preg_grep('#/1$#', array_column($reqs, 'url')), 'a message already in the Maildir was downloaded again');
            $kept = MailUnit::readState($state)['uidl'];
            sort($kept);
            check($kept === ['aaa', 'bbb', 'ccc'], 'state: ' . json_encode($kept));
            mkdir($fx['home'] . '/x');
            list($code, $lines) = emRun($fx, 'pop', ['fetch', '--to', $fx['home'] . '/x/../popmail'], $rules);
            check($code === 1 && strpos(end($lines)['error'], '. or ..') !== false, 'pop accepted a .. path: ' . json_encode($lines));
        } finally {
            emDone($fx);
        }
    },
    'email maildir: a leftover in tmp/ never blocks a message (an old one is removed); a file that appears in new/ is never replaced' => function () {
        $fx = emFixture();
        try {
            $d = Maildir::ensure($fx['home'] . '/mail');
            file_put_contents("$d/tmp/U1.V9.pikkumies", 'killed run');
            touch("$d/tmp/U1.V9.pikkumies", time() - Maildir::STALE - 60);
            file_put_contents("$d/tmp/U2.V9.pikkumies.1.ab", 'a run in progress');
            $n = Maildir::deliver($d, 'U1.V9.pikkumies', function ($sink) {
                return $sink('hello') ? 5 : 0;
            });
            check($n === 5 && file_get_contents("$d/new/U1.V9.pikkumies") === 'hello' && !file_exists("$d/tmp/U1.V9.pikkumies"), 'a stale tmp file blocked or stayed');
            $n = Maildir::deliver($d, 'U2.V9.pikkumies', function ($sink) use ($d) {
                file_put_contents("$d/new/U2.V9.pikkumies", 'first'); // another run placed it meanwhile
                return $sink('second') ? 6 : 0;
            });
            check($n === 0 && file_get_contents("$d/new/U2.V9.pikkumies") === 'first', 'a message in new/ was replaced');
            check(file_exists("$d/tmp/U2.V9.pikkumies.1.ab") && count(glob("$d/tmp/*")) === 1, 'a fresh tmp file of another run was removed, or ours left behind');
            file_put_contents("$d/tmp/U3.V9.pikkumies", 'left by an older version a minute ago');
            $n = Maildir::deliver($d, 'U3.V9.pikkumies', function ($sink) {
                return $sink('third') ? 5 : 0;
            });
            check($n === 5 && file_get_contents("$d/new/U3.V9.pikkumies") === 'third' && file_exists("$d/tmp/U3.V9.pikkumies"), 'a fresh leftover blocked the message or was removed');
        } finally {
            emDone($fx);
        }
    },
    'email log: a new day prunes run logs older than sessions.retentionDays; 0 keeps them all' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'pop');
            $logs = $fx['dir'] . '/logs/email';
            mkdir($logs, 0700, true);
            $recent = gmdate('Ymd', time() - 86400);
            file_put_contents("$logs/20200101.jsonl", "{}\n");
            file_put_contents("$logs/$recent.jsonl", "{}\n");
            $today = "$logs/" . gmdate('Ymd') . '.jsonl';
            foreach ([0, 3] as $days) {
                @unlink($today); // each run starts a new day's file, the moment pruning happens
                file_put_contents($fx['dir'] . '/overlay.php', "<?php return ['sessions' => ['retentionDays' => $days]];\n");
                chmod($fx['dir'] . '/overlay.php', 0600);
                list($code) = emRun($fx, 'pop', ['fetch', '--to', $fx['home'] . '/popmail'], emPopRules([], ''), ['PIKKUMIES_CONFIG' => $fx['dir'] . '/overlay.php']);
                if ($days === 0) {
                    check($code === 0 && file_exists("$logs/20200101.jsonl") && file_exists($today), 'retentionDays 0 must keep every log: ' . json_encode(glob("$logs/*")));
                }
            }
            check($code === 0 && !file_exists("$logs/20200101.jsonl") && file_exists("$logs/$recent.jsonl") && file_exists("$logs/" . gmdate('Ymd') . '.jsonl'), 'logs: ' . json_encode(glob("$logs/*")));
        } finally {
            emDone($fx);
        }
    },
];
