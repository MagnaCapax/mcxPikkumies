<?php
/**
 * Reading never changes the mailbox, even when it fails half way (ADR-0053, imap README): every verb that reads puts
 * \Seen back on the unseen messages it started to read, in `finally`. Also list's `matched` status and --limit 0.
 * Against the scripted fake transport.
 */
require_once __DIR__ . '/fixtures/email/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/email/imap/ImapVerbs.php';

/** emImapRules with the read of $uid failing (a dropped connection), checked before the normal answers. */
function emFailingRead(array $rules, $uid)
{
    array_unshift($rules, ['match' => '#\|imaps://[^|]*/;UID=' . $uid . '(;|$)#', 'errno' => 7, 'error' => 'connection dropped']);
    return $rules;
}

return [
    'email read: a read that fails still sets earlier unseen messages back to unseen (list, show, fetch, archive, antispam scan)' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $m = emMessage('s', 'body');
            $unseen = [5 => [strlen($m), []], 6 => [strlen($m), []]];
            $cases = [
                'list' => [['list'], emFailingRead(emImapRules(['UIDPLUS'], $unseen, [6 => $m]), 5), 'UID STORE 6,5 -FLAGS.SILENT (\\Seen)'],
                'show' => [['show', '6'], emFailingRead(emImapRules(['UIDPLUS'], $unseen), 6), 'UID STORE 6 -FLAGS.SILENT (\\Seen)'],
                'fetch' => [['fetch', '6'], emFailingRead(emImapRules(['UIDPLUS'], $unseen), 6), 'UID STORE 6 -FLAGS.SILENT (\\Seen)'],
                'archive' => [['archive', '--to', $fx['home'] . '/arch'], emFailingRead(emImapRules(['UIDPLUS'], $unseen, [5 => $m]), 6), 'UID STORE 6 -FLAGS.SILENT (\\Seen)'],
            ];
            foreach ($cases as $verb => $c) {
                list($code, $lines, $reqs) = emRun($fx, 'imap', $c[0], $c[1]);
                check($code === 1 && strpos(end($lines)['error'], 'connection dropped') !== false, "$verb did not fail as scripted: " . json_encode($lines));
                check(in_array($c[2], emCustoms($reqs), true), "$verb left unseen mail seen after a failed read: " . json_encode(emCustoms($reqs)));
            }
            // The archive sets each message back right after its own read, not at the end of its chunk of 200.
            $store5 = $read6 = null;
            foreach ($reqs as $i => $r) {
                $store5 = $store5 === null && $r['custom'] === 'UID STORE 5 -FLAGS.SILENT (\\Seen)' ? $i : $store5;
                $read6 = $read6 === null && strpos($r['url'], '/;UID=6') !== false ? $i : $read6;
            }
            check($store5 !== null && $read6 !== null && $store5 < $read6, 'archive restored uid 5 only after reading uid 6: ' . json_encode($reqs));
            list($code, , $reqs) = emRun($fx, 'antispam', ['scan'], emFailingRead(emImapRules(['UIDPLUS'], $unseen, [6 => $m]), 5));
            check($code === 1 && in_array('UID STORE 6,5 -FLAGS.SILENT (\\Seen)', emCustoms($reqs), true), 'antispam scan left unseen mail seen: ' . json_encode(emCustoms($reqs)));
        } finally {
            emDone($fx);
        }
    },
    'email read: when setting \Seen back fails too, the read error is kept and its hint names the uids left marked read' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $m = emMessage('s', 'body');
            $meta = [5 => [strlen($m), ['\\Seen']], 6 => [strlen($m), []]];
            $both = function ($failUid, array $raw) use ($meta) {
                $rules = emFailingRead(emImapRules(['UIDPLUS'], $meta, $raw), $failUid);
                array_unshift($rules, ['match' => '/^UID STORE /', 'errno' => 7, 'error' => 'store failed too']);
                return $rules;
            };
            $cases = ['list' => [['imap', ['list']], $both(5, [6 => $m])], 'show' => [['imap', ['show', '6']], $both(6, [])],
                'fetch' => [['imap', ['fetch', '6']], $both(6, [])], 'archive' => [['imap', ['archive', '--to', $fx['home'] . '/arch2']], $both(6, [5 => $m])],
                'antispam scan' => [['antispam', ['scan']], $both(5, [6 => $m])]];
            foreach ($cases as $verb => $c) {
                list($code, $lines, $reqs) = emRun($fx, $c[0][0], $c[0][1], $c[1]);
                $last = end($lines);
                check($code === 1 && strpos($last['error'], 'connection dropped') !== false && strpos($last['error'], 'store failed') === false, "$verb: the read error was replaced: " . json_encode($last));
                check(strpos($last['hint'], 'unseen flags not restored for uids 6 ') !== false && in_array('UID STORE 6 -FLAGS.SILENT (\\Seen)', emCustoms($reqs), true), "$verb: hint " . json_encode($last));
            }
        } finally {
            emDone($fx);
        }
    },
    'email imap: list and search print `matched` (all matching messages, not only those shown); --limit 0 means as many as one call takes' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            $meta = [3 => [10, ['\\Seen']], 4 => [10, ['\\Seen']], 5 => [10, ['\\Seen']]];
            list($code, $lines) = emRun($fx, 'imap', ['list', '--limit', '2'], emImapRules(['UIDPLUS'], $meta));
            $status = end($lines);
            check($code === 0 && $status['count'] === 2 && $status['matched'] === 3, 'matched missing or wrong: ' . json_encode($status));
            list($code, $lines) = emRun($fx, 'imap', ['search', 'invoice', '--limit', '1'], emImapRules(['UIDPLUS'], $meta));
            check($code === 0 && end($lines)['matched'] === 3, 'search status: ' . json_encode(end($lines)));
            list($code, $lines) = emRun($fx, 'imap', ['list', '--limit', '0'], emImapRules(['UIDPLUS'], $meta));
            check($code === 0 && end($lines)['count'] === 3 && $lines[0]['uid'] === 5, '--limit 0 did not list all: ' . json_encode($lines));
            check(ImapVerbs::limit(['limit' => '0']) === 100 && ImapVerbs::limit(['limit' => '500']) === 100 && ImapVerbs::limit([]) === Cli::LIMIT, 'limit rule');
        } finally {
            emDone($fx);
        }
    },
];
