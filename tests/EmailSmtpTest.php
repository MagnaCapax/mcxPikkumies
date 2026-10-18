<?php
/**
 * The smtp unit (ADR-0053, R15 CWE-441/1427 and E1): logins only at a terminal, `send` writes a draft and sends nothing,
 * `send-draft` re-hashes the draft and its attachments and sends exactly that, addresses and the subject cannot carry
 * CR/LF, and send-self and test reach only the allowlist stored in the login secret.
 */
require_once __DIR__ . '/fixtures/email/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/email/smtp/SmtpDraft.php';

/** An smtp login with From and allowlist, a body file, and the outbox path. */
function emSmtpFixture()
{
    $fx = emFixture();
    emLogin($fx, 'smtp', ['from' => 'me@example.org', 'allowlist' => ['me@example.org', 'other-me@example.net']]);
    mkdir($fx['home'] . '/docs');
    file_put_contents($fx['home'] . '/docs/letter.txt', "Hello,\n.\nsee you\n");
    file_put_contents($fx['home'] . '/docs/photo.jpg', str_repeat("\xff", 3000));
    return $fx + ['body' => $fx['home'] . '/docs/letter.txt', 'outbox' => $fx['state'] . '/email-outbox'];
}

return [
    'email smtp: login only at a terminal, never port 25; the allowlist and From are stored in the secret after a NOOP' => function () {
        $fx = emFixture();
        try {
            list($code, $lines) = emRun($fx, 'smtp', ['login']);
            check($code === 1 && strpos(end($lines)['error'], 'stdin is not one') !== false, 'login without a terminal was not refused');
            list($code, $lines) = emRun($fx, 'smtp', ['login'], [], ['EM_TYPED' => implode("\x1f", ['smtp.example.org', '25', 'me', 'pw'])]);
            check($code === 1 && strpos(end($lines)['error'], '465 or 587') !== false, 'port 25 was accepted');
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['login'], [], ['EM_TYPED' => implode("\x1f", ['smtp.example.org', '587', 'me@example.org', 'pw-secret', '', 'me@example.org,alt@example.net'])]);
            check($code === 0 && $lines[0]['allowlist'] === ['me@example.org', 'alt@example.net'] && $reqs[0]['custom'] === 'NOOP', 'login wrong: ' . json_encode($lines));
            check(strpos(json_encode($lines), 'pw-secret') === false, 'the password was printed');
            $d = MailLogin::load($fx['secrets'], 'smtp');
            check($d['port'] === 587 && $d['from'] === 'me@example.org' && $d['allowlist'][1] === 'alt@example.net', 'secret wrong');
        } finally {
            emDone($fx);
        }
    },
    'email smtp: send writes a draft and sends nothing; send-draft sends exactly the draft, envelope from the checked list' => function () {
        $fx = emSmtpFixture();
        try {
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send', '--to', 'a@example.com,b@example.com', '--subject', 'Päivää', '--body-file', $fx['body'], '--attach', $fx['home'] . '/docs/photo.jpg']);
            check($code === 0 && !$reqs, 'send contacted the server or failed: ' . json_encode($lines));
            $sha = $lines[0]['draft'];
            check(preg_match('/^[0-9a-f]{64}$/', $sha) && hash_file('sha256', $fx['outbox'] . "/$sha.json") === $sha, 'the draft is not named by its sha256');
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send-draft', $sha]);
            check($code === 0 && count($reqs) === 1, 'send-draft failed: ' . json_encode($lines));
            $r = $reqs[0];
            check($r['from'] === 'me@example.org' && $r['rcpt'] === ['a@example.com', 'b@example.com'] && strpos($r['url'], 'smtps://smtp.example.org:465') === 0, 'envelope wrong: ' . json_encode($r));
            check(strpos($r['upload'], "From: me@example.org\r\n") !== false && strpos($r['upload'], 'Subject: =?UTF-8?B?' . base64_encode('Päivää') . '?=') !== false, 'headers wrong: ' . substr($r['upload'], 0, 400));
            check(strpos($r['upload'], 'filename="photo.jpg"') !== false && strpos($r['upload'], "\r\n.\r\n") !== false, 'attachment or body missing');
            check(is_file($fx['outbox'] . "/sent/$sha.json") && !is_file($fx['outbox'] . "/$sha.json"), 'the sent draft was not moved to sent/');
        } finally {
            emDone($fx);
        }
    },
    'email smtp: a draft changed after it was written, or an attachment changed since, is refused and nothing is sent' => function () {
        $fx = emSmtpFixture();
        try {
            list(, $lines) = emRun($fx, 'smtp', ['send', '--to', 'a@example.com', '--subject', 'x', '--body-file', $fx['body'], '--attach', $fx['home'] . '/docs/photo.jpg']);
            $sha = $lines[0]['draft'];
            $file = $fx['outbox'] . "/$sha.json";
            $orig = file_get_contents($file);
            file_put_contents($file, str_replace('a@example.com', 'evil@example.com', $orig));
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send-draft', $sha]);
            check($code === 1 && !$reqs && strpos(end($lines)['error'], 'changed') !== false, 'a changed draft was sent: ' . json_encode($lines));
            file_put_contents($file, $orig);
            file_put_contents($fx['home'] . '/docs/photo.jpg', 'swapped');
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send-draft', $sha]);
            check($code === 1 && !$reqs && strpos(end($lines)['error'], 'attachment changed') !== false, 'a changed attachment was sent: ' . json_encode($lines));
            list($code, , $reqs) = emRun($fx, 'smtp', ['send-draft', '../../secrets/email-smtp']);
            check($code === 1 && !$reqs, 'a path was accepted as a draft id');
        } finally {
            emDone($fx);
        }
    },
    'email smtp: an attachment changed after drafting to other bytes of the same size, or a login now sending as another From, sends nothing' => function () {
        $fx = emSmtpFixture();
        try {
            list(, $lines) = emRun($fx, 'smtp', ['send', '--to', 'a@example.com', '--subject', 'x', '--body-file', $fx['body'], '--attach', $fx['home'] . '/docs/photo.jpg']);
            $sha = $lines[0]['draft'];
            file_put_contents($fx['home'] . '/docs/photo.jpg', str_repeat("\xfe", 3000)); // same 3000 bytes, other content
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send-draft', $sha]);
            check($code === 1 && !$reqs && strpos(end($lines)['error'], 'attachment changed') !== false, 'a same-size changed attachment was sent: ' . json_encode($lines));
            file_put_contents($fx['home'] . '/docs/photo.jpg', str_repeat("\xff", 3000));
            emLogin($fx, 'smtp', ['from' => 'other@example.org', 'allowlist' => ['other@example.org']]);
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send-draft', $sha]);
            check($code === 1 && !$reqs && strpos(end($lines)['error'], 'now sends as other@example.org') !== false, 'a draft from another From was sent: ' . json_encode($lines));
        } finally {
            emDone($fx);
        }
    },
    'email smtp: CR/LF, <> or a display name in an address, and CR/LF in the subject, are refused before any draft is written' => function () {
        $fx = emSmtpFixture();
        try {
            foreach ([["a@example.com>\r\nRCPT TO:<evil@x.example"], ['<a@example.com>'], ['Bob <a@example.com>'], ["a@example.com\nRCPT TO:b@example.com"], ['a@example.com,evil@x.example;b']] as $to) {
                list($code, , $reqs) = emRun($fx, 'smtp', ['send', '--to', $to[0], '--subject', 'x', '--body-file', $fx['body']]);
                check($code === 1 && !$reqs, 'a bad recipient was accepted: ' . json_encode($to[0]));
            }
            list($code) = emRun($fx, 'smtp', ['send', '--to', 'a@example.com', '--subject', "hi\r\nBcc: evil@x.example", '--body-file', $fx['body']]);
            check($code === 1 && !glob($fx['outbox'] . '/*.json'), 'a subject with CR/LF was accepted');
            $refused = false;
            try {
                SmtpDraft::subject("ok\x07bell");
            } catch (CliError $e) {
                $refused = true;
            }
            check($refused, 'a control byte in the subject was accepted');
        } finally {
            emDone($fx);
        }
    },
    'email smtp: send-self and test go only to the first allowlist address from the login secret; --to on send-self is refused' => function () {
        $fx = emSmtpFixture();
        try {
            list($code, $lines, $reqs) = emRun($fx, 'smtp', ['send-self', '--subject', 'report', '--body-file', $fx['body']]);
            check($code === 0 && $reqs[0]['rcpt'] === ['me@example.org'], 'send-self did not go to the allowlist: ' . json_encode($reqs));
            list($code, , $reqs) = emRun($fx, 'smtp', ['send-self', '--to', 'evil@x.example', '--subject', 'r', '--body-file', $fx['body']]);
            check($code === 1 && !$reqs, 'send-self accepted --to');
            list($code, , $reqs) = emRun($fx, 'smtp', ['test']);
            check($code === 0 && $reqs[0]['rcpt'] === ['me@example.org'], 'test did not go to the allowlist');
            emLogin($fx, 'smtp', ['from' => 'me@example.org']);
            list($code, , $reqs) = emRun($fx, 'smtp', ['send-self', '--subject', 'r', '--body-file', $fx['body']]);
            check($code === 1 && !$reqs, 'send-self ran without an allowlist in the secret');
        } finally {
            emDone($fx);
        }
    },
    'email smtp: attachments from ~/.ssh or key-shaped files and a message over 20 MiB encoded are refused' => function () {
        $fx = emSmtpFixture();
        try {
            mkdir($fx['home'] . '/.ssh');
            file_put_contents($fx['home'] . '/.ssh/id_ed25519', 'k');
            list($code) = emRun($fx, 'smtp', ['send', '--to', 'a@example.com', '--subject', 'x', '--body-file', $fx['body'], '--attach', $fx['home'] . '/.ssh/id_ed25519']);
            check($code === 1, 'a key was attached');
            $big = $fx['home'] . '/docs/big.bin';
            $fh = fopen($big, 'w');
            ftruncate($fh, 16 * 1048576);
            fclose($fh);
            list($code, $lines) = emRun($fx, 'smtp', ['send', '--to', 'a@example.com', '--subject', 'x', '--body-file', $fx['body'], '--attach', $big]);
            check($code === 1 && strpos(end($lines)['error'], '20971520') !== false, '16 MiB (about 21 MiB encoded) was accepted: ' . json_encode($lines));
        } finally {
            emDone($fx);
        }
    },
];
