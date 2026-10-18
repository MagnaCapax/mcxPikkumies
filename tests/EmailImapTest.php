<?php
/**
 * The imap unit and lib/MailImap.php (ADR-0053, R15 CWE-93 and E2): every argument checked before libcurl URL-decodes
 * the command, folders encoded in the URL, reading leaves unseen mail unseen, and deletes are exact (UID EXPUNGE of the
 * given uids, refused without UIDPLUS, never a plain EXPUNGE). Against the scripted fake transport.
 */
require_once __DIR__ . '/fixtures/email/harness.php';

return [
    'email imap: a uid is a plain number; ranges, *, lists, signs, overflow and control bytes are refused' => function () {
        foreach (['1', '42', '4294967295'] as $ok) {
            check(MailImap::uid($ok) === $ok, "uid $ok refused");
        }
        foreach (['0', '01', '1:*', '*', '5,6', '-1', '1 2', '4294967296', "5\n", '5%0A', '', '12345678901'] as $bad) {
            $refused = false;
            try {
                MailImap::uid($bad);
            } catch (CliError $e) {
                $refused = true;
            }
            check($refused, 'uid ' . json_encode($bad) . ' was accepted');
        }
    },
    'email imap: %22, %0A and {5} stay text inside one quoted string after curl URL-decodes the command' => function () {
        $cmd = MailImap::custom('UID SEARCH TEXT ' . MailImap::quote(MailImap::text('a%22 OR ALL %22b', 'search word')));
        check($cmd === 'UID SEARCH TEXT "a%2522 OR ALL %2522b"', 'percent not escaped: ' . $cmd);
        $sent = rawurldecode($cmd); // what libcurl puts on the wire
        check($sent === 'UID SEARCH TEXT "a%22 OR ALL %22b"' && substr_count($sent, '"') === 2, 'the quoted string was broken: ' . $sent);
        $sent = rawurldecode(MailImap::custom('UID SEARCH TEXT ' . MailImap::quote('x%0AA1 DELETE INBOX')));
        check(strpos($sent, "\n") === false && strpos($sent, '%0A') !== false, '%0A became a line break: ' . json_encode($sent));
        check(MailImap::quote('{5}') === '"{5}"' && MailImap::quote('a"b\\c') === '"a\\"b\\\\c"', 'quoting of {5}, " or \\ is wrong');
        foreach (["a\nb", "a\rb", "a\x00b", "a\x7Fb", "caf\xC3\xA9"] as $bad) {
            $refused = false;
            try {
                MailImap::text($bad, 'search word');
            } catch (CliError $e) {
                $refused = true;
            }
            check($refused, 'control or 8-bit text accepted: ' . json_encode($bad));
        }
    },
    'email imap: a ;UID= in a folder name stays in the name; segments are encoded, the uid form is ours' => function () {
        check(MailImap::url('INBOX;UID=5') === '/INBOX%3BUID%3D5', 'folder not encoded: ' . MailImap::url('INBOX;UID=5'));
        check(MailImap::url('My Folder"x') === '/My%20Folder%22x', 'space or quote not encoded');
        check(MailImap::url('Work/2026', 7, 'HEADER', 99) === '/Work/2026;UIDVALIDITY=99/;UID=7;SECTION=HEADER', 'uid url wrong: ' . MailImap::url('Work/2026', 7, 'HEADER', 99));
        $refused = false;
        try {
            MailImap::url('a//b');
        } catch (CliError $e) {
            $refused = true;
        }
        check($refused, 'an empty folder segment was accepted');
    },
    'email imap: delete without UIDPLUS is refused before any STORE; with it, exactly UID STORE then UID EXPUNGE of those uids' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $meta = [5 => [100, ['\\Seen']], 6 => [200, []]];
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['delete', '5', '6', '--expect', '1700000000'], emImapRules(['MOVE'], $meta));
            check($code === 1 && strpos(end($lines)['error'], 'UIDPLUS') !== false, 'no-UIDPLUS delete not refused: ' . json_encode($lines));
            foreach (emCustoms($reqs) as $c) {
                check(strpos($c, 'STORE') === false && strpos($c, 'EXPUNGE') === false, "a change was sent without UIDPLUS: $c");
            }
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['delete', '5', '6', '--expect', '1700000000'], emImapRules(['UIDPLUS'], $meta));
            check($code === 0, 'delete failed: ' . json_encode($lines));
            $c = emCustoms($reqs);
            $i = array_search('UID STORE 5,6 +FLAGS.SILENT (\\Deleted)', $c, true);
            check($i !== false && isset($c[$i + 1]) && $c[$i + 1] === 'UID EXPUNGE 5,6', 'not STORE then UID EXPUNGE: ' . json_encode($c));
            foreach ($c as $cmd) {
                check(!preg_match('/^EXPUNGE\b/', $cmd), 'a plain EXPUNGE was sent');
            }
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['delete', '5', '--expect', '123'], emImapRules(['UIDPLUS'], $meta));
            check($code === 1 && !preg_grep('/STORE|EXPUNGE/', emCustoms($reqs)), 'a wrong --expect still deleted');
            list($code, , $reqs) = emRun($fx, 'imap', ['delete', '1:*', '--expect', '1700000000'], emImapRules(['UIDPLUS'], $meta));
            check($code === 1 && !$reqs, 'a 1:* uid reached the server');
        } finally {
            emDone($fx);
        }
    },
    'email imap: move uses MOVE when offered, else COPY with an exact UID EXPUNGE; the folder argument is quoted and %-escaped' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $meta = [7 => [100, []]];
            list($code, , $reqs) = emRun($fx, 'imap', ['move', '7', '--to', 'Old%22 stuff'], emImapRules(['MOVE'], $meta));
            check($code === 0 && in_array('UID MOVE 7 "Old%2522 stuff"', emCustoms($reqs), true), 'MOVE not sent as expected: ' . json_encode(emCustoms($reqs)));
            list($code, , $reqs) = emRun($fx, 'imap', ['move', '7', '--to', 'Archive'], emImapRules(['UIDPLUS'], $meta));
            $c = emCustoms($reqs);
            check($code === 0 && in_array('UID COPY 7 "Archive"', $c, true) && in_array('UID EXPUNGE 7', $c, true), 'COPY fallback wrong: ' . json_encode($c));
        } finally {
            emDone($fx);
        }
    },
    'email imap: list and show leave unseen mail unseen and print the body only as untrusted_text' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $html = "<html><head><style>p{}</style></head><body><p>Hello <b>you</b></p><script>steal()</script><!-- hidden --></body></html>";
            $msg = emMessage('=?UTF-8?B?SGVsbG8gw6R5?=', '', []);
            $msg = str_replace("Content-Type: text/plain; charset=UTF-8\r\n\r\n\r\n", "Content-Type: text/html; charset=UTF-8\r\n\r\n$html\r\n", $msg);
            $meta = [9 => [strlen($msg), []]];
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['show', '9'], emImapRules(['UIDPLUS'], $meta, [9 => $msg]));
            check($code === 0, 'show failed: ' . json_encode($lines));
            $r = $lines[0];
            check($r['subject'] === 'Hello äy' && $r['untrusted_text'] === 'Hello you', 'parse wrong: ' . json_encode($r));
            check(!isset($r['text']) && !isset($r['body']), 'the body must only appear as untrusted_text');
            check(in_array('UID STORE 9 -FLAGS.SILENT (\\Seen)', emCustoms($reqs), true), 'unseen message not set back to unseen');
            list($code, $lines, $reqs) = emRun($fx, 'imap', ['list'], emImapRules(['UIDPLUS'], [9 => [10, ['\\Seen']]], [9 => $msg]));
            check($code === 0 && $lines[0]['seen'] === true && end($lines)['uidvalidity'] === 1700000000, 'list wrong: ' . json_encode($lines));
            check(!preg_grep('/STORE/', emCustoms($reqs)), 'a seen message was changed by list');
        } finally {
            emDone($fx);
        }
    },
];
