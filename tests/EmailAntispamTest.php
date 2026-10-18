<?php
/**
 * The antispam unit (ADR-0053): rules kept in local/state, the score from the first Authentication-Results header and the
 * provider's spam flag, and `apply` moving spam to Junk without ever deleting (MOVE, or COPY with an exact UID EXPUNGE).
 */
require_once __DIR__ . '/fixtures/email/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/email/antispam/AntispamRules.php';

/** A header block for scoring. */
function emSpamHead($from, $subject, $ar = '', $flag = '')
{
    return "From: $from\r\nSubject: $subject\r\n" . ($ar !== '' ? "Authentication-Results: $ar\r\n" : '') . ($flag !== '' ? "X-Spam-Flag: $flag\r\n" : '')
        . "Authentication-Results: forged.example; spf=pass; dkim=pass; dmarc=pass\r\n\r\n";
}

return [
    'email antispam: score from the first Authentication-Results and the spam flag, plus 5 per matching user rule' => function () {
        $rules = ['from' => ['bad@x.example'], 'domain' => ['promo.example'], 'subject_word' => ['winner']];
        list($h) = MailParse::split(emSpamHead('Shop <deals@mail.promo.example>', 'Hello', 'mx.example.org; spf=softfail; dkim=fail; dmarc=fail'));
        $r = AntispamRules::score($h, $rules);
        check($r['spf'] === 'softfail' && $r['dmarc'] === 'fail' && $r['rules'] === ['domain:promo.example'] && $r['score'] === 9 && $r['spam'], 'score wrong: ' . json_encode($r));
        list($h) = MailParse::split(emSpamHead('friend@ok.example', 'lunch', 'mx.example.org; spf=pass; dkim=pass; dmarc=pass'));
        $r = AntispamRules::score($h, $rules);
        check($r['score'] === 0 && !$r['spam'] && $r['provider_spam'] === null, 'clean mail scored: ' . json_encode($r));
        list($h) = MailParse::split(emSpamHead('x@y.example', 'You are a WINNER', '', 'YES'));
        $r = AntispamRules::score($h, $rules);
        check($r['provider_spam'] === true && $r['rules'] === ['subject-word:winner'] && $r['score'] === 8, 'flag or subject rule missed: ' . json_encode($r));
        list($h) = MailParse::split("From: a@notpromo.example\r\nSubject: s\r\n\r\n");
        check(AntispamRules::score($h, $rules)['rules'] === [], 'a look-alike domain matched');
    },
    'email antispam: rules are added and removed in local/state; a bad rule is refused' => function () {
        $fx = emFixture();
        try {
            list($code, $lines) = emRun($fx, 'antispam', ['rule-add', '--domain', 'Promo.Example']);
            check($code === 0 && end($lines)['verified'] === true, 'rule-add failed: ' . json_encode($lines));
            list(, $lines) = emRun($fx, 'antispam', ['rules']);
            check($lines[0] === ['kind' => 'domain', 'value' => 'promo.example'], 'rules wrong: ' . json_encode($lines));
            check(emRun($fx, 'antispam', ['rule-add', '--from', "a@b.example\nx"])[0] === 1 && emRun($fx, 'antispam', ['rule-add', '--domain', 'a', '--from', 'b@c.example'])[0] === 1, 'bad rules accepted');
            list($code) = emRun($fx, 'antispam', ['rule-remove', '--domain', 'promo.example']);
            check($code === 0 && AntispamRules::load($fx['state'] . '/email-rules.json')['domain'] === [], 'rule not removed');
        } finally {
            emDone($fx);
        }
    },
    'email antispam: apply moves only what scan calls spam, to Junk, and never deletes; COPY falls back to an exact UID EXPUNGE' => function () {
        $fx = emFixture();
        try {
            emLogin($fx, 'imap');
            emRun($fx, 'antispam', ['rule-add', '--domain', 'promo.example']);
            $raw = [7 => emSpamHead('deals@promo.example', 'buy'), 8 => emSpamHead('friend@ok.example', 'hi')];
            $base = emImapRules(['MOVE'], [7 => [100, []], 8 => [100, ['\\Seen']]], $raw);
            $base[] = ['match' => '/^LIST /', 'body' => "* LIST (\\HasNoChildren) \".\" \"INBOX\"\r\n* LIST (\\HasNoChildren \\Junk) \".\" \"Junk\"\r\n"];
            list($code, $lines, $reqs) = emRun($fx, 'antispam', ['apply'], $base);
            $c = emCustoms($reqs);
            check($code === 0 && in_array('UID MOVE 7 "Junk"', $c, true) && count($lines) === 2 && $lines[0]['uid'] === 7, 'apply wrong: ' . json_encode([$lines, $c]));
            check(in_array('UID STORE 7 -FLAGS.SILENT (\\Seen)', $c, true), 'scan left an unseen message seen');
            check(!preg_grep('/Deleted|EXPUNGE|MOVE 8|COPY 8/', $c), 'apply did more than move uid 7: ' . json_encode($c));
            $base[0] = ['match' => '/^CAPABILITY\|/', 'body' => "* CAPABILITY IMAP4rev1 UIDPLUS\r\n"];
            list($code, , $reqs) = emRun($fx, 'antispam', ['apply'], $base);
            $c = emCustoms($reqs);
            check($code === 0 && in_array('UID COPY 7 "Junk"', $c, true) && in_array('UID EXPUNGE 7', $c, true) && !preg_grep('/^EXPUNGE/', $c), 'COPY fallback wrong: ' . json_encode($c));
            list($code, $lines) = emRun($fx, 'antispam', ['apply', '--to', 'Spam'], $base);
            check($code === 1 && strpos(end($lines)['error'], 'no folder Spam') !== false, 'a missing junk folder was not refused');
        } finally {
            emDone($fx);
        }
    },
];
