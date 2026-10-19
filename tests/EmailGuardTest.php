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
    // A short draft: the happy path, asked with From, every recipient, Subject, attachment path and size, and the
    // body, all of it shown because it fits HookUnits::APPROVE_SHOWN whole.
    'email guard: send-draft is asked with From, every recipient, Subject, attachment paths and sizes and the body, when it fits' => function () {
        $fx = emFixture();
        try {
            $outbox = $fx['state'] . '/email-outbox';
            $spec = ['from' => 'me@example.org', 'to' => ['a@example.com'], 'subject' => "Hi\xE2\x80\xAEthere", 'body' => 'short body',
                'attachments' => [['path' => '/home/u/docs/q3.pdf', 'name' => 'q3.pdf', 'bytes' => 12345, 'sha256' => str_repeat('0', 64)]]];
            $sha = SmtpDraft::write($outbox, $spec);
            $S = '/home/u/pikkumies/tools/email/smtp/smtp.php';
            $d = emGuard("php $S send-draft $sha", $outbox);
            check($d !== null && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-mail:$sha", 'send-draft not asked: ' . json_encode($d));
            $m = $d['message'];
            foreach (['From: me@example.org', 'To (1): a@example.com', 'Subject: Hithere', '/home/u/docs/q3.pdf (12345 bytes)', 'short body', "send-draft $sha"] as $want) {
                check(strpos($m, $want) !== false, "the question lacks '$want': $m");
            }
            check(strpos($m, "\xE2\x80\xAE") === false, 'a bidi override was shown');
            file_put_contents("$outbox/$sha.json", str_replace('a@example.com', 'evil@example.com', (string) file_get_contents("$outbox/$sha.json")));
            $d = emGuard("php $S send-draft $sha", $outbox);
            check($d !== null && $d['action'] === 'block', 'a changed draft was not blocked: ' . json_encode($d));
            $d = emGuard("php $S send-draft " . str_repeat('a', 64), $outbox);
            check($d !== null && $d['action'] === 'block', 'a missing draft was not blocked');
        } finally {
            emDone($fx);
        }
    },
    // ADR-0053 calls the body excerpt "the first 1 KiB of the body", but a fixed 1 KiB excerpt plus From, every
    // recipient, Subject and the attachments overflows HookUnits::APPROVE_SHOWN for almost any real draft (ADR-0020
    // 2026-10-19 amendment): the excerpt shrinks to whatever is left once everything else is shown in full, instead
    // of refusing the whole send. The excerpt is cut short of TAILMARK; the label states the real cut and the body's
    // true total size, so the user knows they saw only part of it.
    'email guard: a long draft approves with a shortened excerpt, stating the real excerpt length and the body total' => function () {
        $fx = emFixture();
        try {
            $outbox = $fx['state'] . '/email-outbox';
            $body = 'START' . str_repeat('b', 1100) . 'TAILMARK';
            $spec = ['from' => 'me@example.org', 'to' => ['a@example.com', 'b@example.com'], 'subject' => 'Report', 'body' => $body,
                'attachments' => [['path' => '/home/u/docs/q3.pdf', 'name' => 'q3.pdf', 'bytes' => 12345, 'sha256' => str_repeat('0', 64)]]];
            $sha = SmtpDraft::write($outbox, $spec);
            $S = '/home/u/pikkumies/tools/email/smtp/smtp.php';
            $d = emGuard("php $S send-draft $sha", $outbox);
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-mail:$sha", 'a long draft was refused instead of shortened: ' . json_encode($d));
            $m = $d['message'];
            check(strlen($m) <= HookUnits::APPROVE_SHOWN, 'the shortened message is still over the cap: ' . strlen($m) . ': ' . $m);
            foreach (['From: me@example.org', 'To (2): a@example.com, b@example.com', 'Subject: Report', '/home/u/docs/q3.pdf (12345 bytes)', 'Body (' . strlen($body) . ' bytes)', "send-draft $sha"] as $want) {
                check(strpos($m, $want) !== false, "the question lacks '$want': $m");
            }
            check(strpos($m, 'TAILMARK') === false, 'more than the cut-down excerpt was shown: ' . $m);
            if (preg_match('/first (\d+) characters:\n(.*)\nphp /s', $m, $cap)) {
                check((int) $cap[1] === HookUnits::charCount($cap[2]), "the stated excerpt length {$cap[1]} does not match what is shown ({$cap[2]}): $m");
                check($cap[1] > 0, 'the excerpt was cut to nothing even though the fixed part alone fits: ' . $m);
            } else {
                check(false, 'no excerpt label found in an approved long draft: ' . $m);
            }
        } finally {
            emDone($fx);
        }
    },
    // Even with no body at all (so there is nothing to shrink), From, every recipient, Subject, the attachments and
    // the command (never shortened) can alone overflow HookUnits::APPROVE_SHOWN: this is the one case send-draft
    // still refuses outright. Subject is the only field free to pad arbitrarily, so padding it alone shifts the
    // message by exactly as many characters, giving an exact boundary on the part no excerpt can rescue.
    'email guard: From/To/Subject/Attachments and the command alone are asked right up to HookUnits::APPROVE_SHOWN characters, one more is blocked (no body can save it)' => function () {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        $fx = emFixture();
        try {
            $outbox = $fx['state'] . '/email-outbox';
            $specFor = function ($pad) {
                return ['from' => 'a@example.net', 'to' => ['c@example.net'], 'subject' => str_repeat('x', $pad), 'body' => ''];
            };
            $S = '/home/u/pikkumies/tools/email/smtp/smtp.php';
            // A short subject is shown with a "Body (0 bytes), first 0 characters:" excerpt label; past some length
            // that label no longer fits and the shorter floor "Body: 0 bytes, not shown." stands, so growth is not
            // 1:1 until then. Find the first padding past that drop, so every character counted from there on is
            // pure Subject growth.
            $base = null;
            for ($basePad = 0; $basePad <= 350; $basePad++) {
                $base = emGuard('php ' . $S . ' send-draft ' . SmtpDraft::write($outbox, $specFor($basePad)), $outbox);
                if (is_array($base) && $base['action'] === 'approve' && strpos($base['message'], 'Body (') === false) {
                    break;
                }
            }
            check(is_array($base) && $base['action'] === 'approve' && strpos($base['message'], 'Body (') === false, 'no send-draft case past the label-drop point: ' . json_encode($base));
            check(strpos($base['message'], 'Body: 0 bytes, not shown.') !== false, 'the floor does not state the body size: ' . json_encode($base));
            $need350 = $basePad + (HookUnits::APPROVE_SHOWN - strlen($base['message']));
            check($need350 >= $basePad, 'the base send-draft message is already over the cap; shorten it');
            $sha350 = SmtpDraft::write($outbox, $specFor($need350));
            $d = emGuard("php $S send-draft $sha350", $outbox);
            check(is_array($d) && $d['action'] === 'approve' && strlen($d['message']) === HookUnits::APPROVE_SHOWN, '350 characters: expected approve: ' . json_encode($d));
            $sha351 = SmtpDraft::write($outbox, $specFor($need350 + 1));
            $d = emGuard("php $S send-draft $sha351", $outbox);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'too long to show the user in full') !== false, '351 characters: expected block: ' . json_encode($d));
        } finally {
            emDone($fx);
        }
    },
    // Padding only an extra flag before the fixed "test" word shifts the message by exactly as many characters.
    'email guard: smtp test is asked right up to HookUnits::APPROVE_SHOWN characters, one more is blocked' => function () {
        check(HookUnits::APPROVE_SHOWN === 350, 'the shown-in-full cap moved; re-measure the 24x80 approval panel before changing it');
        $cmdFor = function ($pad) {
            return 'php /home/u/pikkumies/tools/email/smtp/smtp.php --pad=' . str_repeat('a', $pad) . ' test';
        };
        $base = emGuard($cmdFor(0));
        check(is_array($base) && $base['action'] === 'approve', 'base smtp test case not approved: ' . json_encode($base));
        $need350 = HookUnits::APPROVE_SHOWN - strlen($base['message']);
        check($need350 >= 0, 'the base smtp test message is already over the cap; shorten it');
        $d = emGuard($cmdFor($need350));
        check(is_array($d) && $d['action'] === 'approve' && strlen($d['message']) === HookUnits::APPROVE_SHOWN, '350 characters: expected approve: ' . json_encode($d));
        $d = emGuard($cmdFor($need350 + 1));
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'too long to show the user in full') !== false, '351 characters: expected block: ' . json_encode($d));
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
