<?php
/**
 * The guard's email rules (hooks/guard/guard-destructive/GuardMail.php, ADR-0053, R15): send-draft is asked with what
 * the draft holds and rule_key pikkumies-mail:<sha>; a missing or changed draft is blocked; smtp commands with shell
 * expansion are blocked; email logins are blocked; imap and pop deletes and smtp test are asked; reads stay silent.
 */
require_once __DIR__ . '/fixtures/email/harness.php';
require_once dirname(__DIR__) . '/pikkumies/tools/email/smtp/SmtpDraft.php';

/** The guard's decoded answer for a terminal command, with the outbox seam set; null for no answer. */
function emGuard($cmd, $outbox = '')
{
    return testWithEnv(['PIKKUMIES_MAIL_OUTBOX' => $outbox], function () use ($cmd) {
        list($code, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [],
            json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
        check($code === 0, "guard exited $code");
        $d = json_decode($out, true);
        return is_array($d) ? $d : null;
    });
}

return [
    'email guard: send-draft is asked with From, every recipient, Subject, attachment paths and sizes and the first 1 KiB of body' => function () {
        $fx = emFixture();
        try {
            $outbox = $fx['state'] . '/email-outbox';
            $body = 'START' . str_repeat('b', 1100) . 'TAILMARK';
            $spec = ['from' => 'me@example.org', 'to' => ['a@example.com', 'b@example.com'], 'subject' => "Quarterly\xE2\x80\xAEreport", 'body' => $body,
                'attachments' => [['path' => '/home/u/docs/q3.pdf', 'name' => 'q3.pdf', 'bytes' => 12345, 'sha256' => str_repeat('0', 64)]]];
            $sha = SmtpDraft::write($outbox, $spec);
            $S = '/home/u/pikkumies/tools/email/smtp/smtp.php';
            $d = emGuard("php $S send-draft $sha", $outbox);
            check($d !== null && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-mail:$sha", 'send-draft not asked: ' . json_encode($d));
            $m = $d['message'];
            foreach (['From: me@example.org', 'To (2): a@example.com, b@example.com', 'Subject: Quarterlyreport', '/home/u/docs/q3.pdf (12345 bytes)', 'START', "send-draft $sha"] as $want) {
                check(strpos($m, $want) !== false, "the question lacks '$want': $m");
            }
            check(strpos($m, 'TAILMARK') === false && strpos($m, "\xE2\x80\xAE") === false, 'more than 1 KiB of body, or a bidi override, was shown');
            file_put_contents("$outbox/$sha.json", str_replace('a@example.com', 'evil@example.com', (string) file_get_contents("$outbox/$sha.json")));
            $d = emGuard("php $S send-draft $sha", $outbox);
            check($d !== null && $d['action'] === 'block', 'a changed draft was not blocked: ' . json_encode($d));
            $d = emGuard("php $S send-draft " . str_repeat('a', 64), $outbox);
            check($d !== null && $d['action'] === 'block', 'a missing draft was not blocked');
        } finally {
            emDone($fx);
        }
    },
    'email guard: shell expansion, chaining or redirects in an smtp command are blocked; single-quoted text is fine' => function () {
        $S = '/home/u/pikkumies/tools/email/smtp/smtp.php';
        $bad = ['php $S send-draft x', "php $S send-draft \$(cat id)", "php $S send-draft `cat id`", "php ~/pikkumies/tools/email/smtp/smtp.php test",
            "php $S send --to a@example.com --subject hi --body-file /home/u/*.txt", "php $S test; rm -rf ~", "php $S test && echo", "php $S test | tee x",
            "php $S status > /tmp/x", "php $S send-draft x\nphp $S test", "php $S send --subject \"\$HOME\" --to a@example.com --body-file /home/u/a",
            "php $S send --subject 'unbalanced --to a@example.com", "cd /home/u && php smtp.php send-self --subject a --body-file /home/u/a"];
        foreach ($bad as $cmd) {
            $d = emGuard($cmd);
            check($d !== null && $d['action'] === 'block', 'not blocked: ' . json_encode($cmd) . ' -> ' . json_encode($d));
        }
        $d = emGuard("php $S send --to a@example.com --subject 'Is it ready? Yes; *done*' --body-file /home/u/docs/a.txt");
        check($d === null, 'a single-quoted subject was refused: ' . json_encode($d));
        check(emGuard("php $S status") === null && emGuard("php $S send-self --subject report --body-file /home/u/r.txt") === null, 'status or send-self was asked');
        $d = emGuard("php $S test");
        check($d !== null && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-mail:') === 0, 'smtp test not asked');
    },
    'email guard: the agent cannot run any email login; imap and pop deletes are asked as deletes; reads stay silent' => function () {
        foreach (['imap', 'pop', 'smtp', 'antispam'] as $u) {
            $d = emGuard("php /home/u/pikkumies/tools/email/$u/$u.php login");
            check($d !== null && $d['action'] === 'block' && strpos($d['message'], 'login') !== false, "$u login not blocked");
        }
        foreach (['php /home/u/pikkumies/tools/email/imap/imap.php delete 5 6 --expect 1700000000', 'php /home/u/pikkumies/tools/email/imap/imap.php delete 5',
            'php /home/u/pikkumies/tools/email/pop/pop.php delete 3 --expect abc', 'php $P delete 3 --expect abc'] as $cmd) {
            $d = emGuard($cmd);
            check($d !== null && $d['action'] === 'approve' && strpos($d['rule_key'], 'pikkumies-delete:') === 0, "delete not asked: $cmd");
        }
        foreach (['php /home/u/pikkumies/tools/email/imap/imap.php list --unseen', 'php /home/u/pikkumies/tools/email/imap/imap.php move 5 --to Archive',
            'php /home/u/pikkumies/tools/email/antispam/antispam.php apply', 'php /home/u/pikkumies/tools/email/imap/imap.php fetch 5'] as $cmd) {
            check(emGuard($cmd) === null, "asked or blocked: $cmd");
        }
    },
];
