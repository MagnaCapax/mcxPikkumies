<?php
/**
 * /feedback (ADR-0056): the draft is exactly what is sent and cannot change unseen, common secret forms are removed,
 * only a Pulsed Media vsk- key is ever sent, the payload goes out sealed and only once, the conversation comes from
 * Hermes' redacted export and leaves no copy behind, and the approval hook asks only for the plain send of this
 * Pikkumies' own tool and blocks the other forms it can see. The hook and the tool run in a throwaway tree, so the
 * checkout's local/ is never used.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackDraft.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackSend.php';

const FEEDBACK_TEST_KEY = 'vsk-S3CRET-feedback-oracle-7a1f0c9e';

if (!function_exists('feedbackTree')) {
    /** A pikkumies tree with lib, config, the feedback unit and the hook; local/ holds a config whose PM key is $key. */
    function feedbackTree($key = FEEDBACK_TEST_KEY)
    {
        $tmp = realpath(testTempDir('pk-feedback'));
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'config', 'tools/system/feedback', 'hooks/guard/feedback-send'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/home", 0700, true);
        file_put_contents("$tmp/home/.virsilipasKey", "endpoint=https://example.invalid/v1\nkey=$key\n");
        chmod("$tmp/home/.virsilipasKey", 0600);
        @mkdir("$tmp/pikkumies/local", 0700, true);
        $config = ['provider' => ['endpoint' => '', 'model' => '', 'keyFile' => "$tmp/home/.none", 'virsilipasKeyFile' => "$tmp/home/.virsilipasKey"]];
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        return $tmp;
    }

    /** Runs the tree's hook on a tool call; returns the decoded answer, or null for no opinion. */
    function feedbackHook($tree, $tool, array $input)
    {
        list(, $out) = runPhp("$tree/pikkumies/hooks/guard/feedback-send/feedback-send.php", [],
            json_encode(['tool_name' => $tool, 'tool_input' => $input, 'session_id' => 's-1']));
        return trim($out) === '' ? null : json_decode($out, true);
    }

    /** A php -S endpoint that opens the sealed body with $secret and answers 201 (/ok), 503 (/fail) or 403 (/deny). */
    function feedbackServer($secret)
    {
        $dir = testTempDir('pk-feedback-srv');
        file_put_contents("$dir/secret", base64_encode($secret));
        file_put_contents("$dir/router.php", '<?php
$kp = base64_decode(file_get_contents(__DIR__ . "/secret"));
$mode = explode("/", parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH))[1];
if ($mode === "fail") { http_response_code(503); echo json_encode(["error" => "intake_paused", "message" => "Feedback intake is paused."]); return; }
if ($mode === "deny") { http_response_code(403); echo json_encode(["error" => "suspended", "message" => "This key is suspended."]); return; }
$open = sodium_crypto_box_seal_open(file_get_contents("php://input"), $kp);
file_put_contents(__DIR__ . "/got", $open === false ? "NOT-SEALED" : $open);
file_put_contents(__DIR__ . "/auth", $_SERVER["HTTP_AUTHORIZATION"] ?? "");
http_response_code(201);
echo json_encode(["id" => str_repeat("e", 32), "received_at" => 1792300000]);');
        $port = testFreePort();
        // exec: proc_terminate then stops php -S itself, not a shell around it (PHP 7.3 has no array form of proc_open).
        $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg("$dir/router.php"), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(300000);
        return [$p, "http://127.0.0.1:$port", $dir];
    }

    /** The CliError message and hint a call throws, '' when it does not throw. */
    function feedbackError(callable $fn)
    {
        try {
            $fn();
        } catch (CliError $e) {
            return $e->getMessage() . ' / ' . $e->hint;
        }
        return '';
    }
}

return [
    'feedback: Redact removes the common password and key forms, line by line, and keeps ordinary words' => function () {
        $out = Redact::secrets("my key vsk-AbCdEf0123456789xyzQ and sk-live0123456789abcdef here; curl -H 'Authorization: Bearer abc123' --password=hunter2 TOKEN=xyz task-12345678 risk-assessment");
        check(strpos($out, 'AbCdEf0123') === false && strpos($out, 'live0123456789') === false, $out);
        check(strpos($out, 'abc123') === false && strpos($out, 'hunter2') === false && strpos($out, 'TOKEN=xyz') === false, $out);
        check(strpos($out, 'task-12345678') !== false && strpos($out, 'risk-assessment') !== false, 'ordinary words were redacted: ' . $out);
        // Fake values, assembled here so the file itself carries no key-shaped string.
        $pem = '-----BEGIN OPENSSH PRIVATE ' . 'KEY-----';
        $forms = ['curl -u alice:Or4cle01 https://x', 'sshpass -p Or4cle02 ssh u@h', "curl -H 'Authorization: Token Or4cle03' x",
            'Cookie: sid=a; theme=Or4cle04', 'pass = Or4cle05', 'token = {"access_token":"Or4cle06"}', '{"password": "Or4cle07", "user": "bob"}',
            'password: Or4cle08', 'machine x.org login bob password Or4cle09', 'password Or4cle10', 'AK' . 'IAOR4CLE11ABCDEFGH',
            'gh' . 'p_Or4cle12abcdefghijklmnopqrstu', 'xo' . 'xb-Or4cle13-abcdefghij', 'ey' . 'JhbGciOiJIUzI1NiJ9.ey' . 'JzdWIiOiJPcjRjbGUxNCJ9.Or4cle14signaturepart',
            "$pem\nb3BlbnNzaC1Or4cle15AAAA\nAAAABG5vbmU=\n" . str_replace('BEGIN', 'END', $pem)];
        foreach ($forms as $f) {
            $r = Redact::secrets($f);
            check(!preg_match('/Or4cle|OR4CLE/', $r) && strpos($r, 'REDACTED') !== false, 'not removed: ' . json_encode($f) . ' -> ' . json_encode($r));
        }
        $prose = "monkey=banana keyboard=fi compass=north bypass=1\nI use mysql daily.\nThen find . -perm 644";
        check(Redact::secrets($prose) === $prose, 'ordinary text changed: ' . Redact::secrets($prose));
    },
    'feedback: Redact keeps a long text after "mysql x" whole, and throws instead of emptying a line it cannot check' => function () {
        $long = "mysql x\n" . str_repeat("some words here and there\n", 20000);
        check(Redact::secrets($long) === $long, 'a 500 KB text after "mysql x" was changed or emptied');
        $err = feedbackError(function () {
            Redact::secrets('mysql ' . str_repeat('x ', 250000));
        });
        check(strpos($err, 'too long or too complex') !== false, 'a regex engine failure did not throw: ' . $err);
    },
    'feedback: a draft is saved once, read-only, named by its sha256, and refused after any change, extra field or symlink' => function () {
        $dir = testTempDir('pk-fbdraft');
        $rec = FeedbackDraft::create("the qBittorrent tool times out\n", "### User\nhi vsk-AbCdEf0123456789xyzQ\n", ['pikkumies' => 'abc1234'], 1792300000, $dir, ['s-1', 'Magnets']);
        check(preg_match('/^[0-9a-f]{32}$/', $rec['id']) === 1, 'id: ' . $rec['id']);
        $path = "$dir/{$rec['id']}.json";
        check(substr(hash_file('sha256', $path), 0, 32) === $rec['id'], 'the id is not the sha256 prefix of the file');
        check((fileperms($path) & 0777) === 0400, sprintf('mode %o', fileperms($path) & 0777));
        list($json, $p) = FeedbackDraft::load($rec['id'], $dir);
        check($p['text'] === 'the qBittorrent tool times out' && strpos($json, 'AbCdEf0123') === false, 'the vsk- key in the conversation was not removed: ' . $json);
        check($rec['conversation'] === true && $rec['text_start'] === 'the qBittorrent tool times out' && $rec['session_id'] === 's-1'
            && $rec['session_title'] === 'Magnets', json_encode($rec));
        // A hand-written draft named by its own sha256 but with a field the prompt never shows.
        $extra = json_encode(json_decode($json, true) + ['x' => str_repeat('a', 1000)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $xid = substr(hash('sha256', $extra), 0, 32);
        file_put_contents("$dir/$xid.json", $extra);
        check(strpos(feedbackError(function () use ($xid, $dir) {
            FeedbackDraft::load($xid, $dir);
        }), 'not a feedback draft') !== false, 'a draft with unknown fields was accepted');
        // A symlink named like a draft, pointing at a real draft, is not a draft.
        $other = testTempDir('pk-fblink');
        rename($path, "$other/real.json");
        symlink("$other/real.json", $path);
        check(strpos(feedbackError(function () use ($rec, $dir) {
            FeedbackDraft::load($rec['id'], $dir);
        }), 'no draft') !== false, 'a symlinked draft was accepted');
        unlink($path);
        rename("$other/real.json", $path);
        chmod($path, 0600);
        file_put_contents($path, str_replace('times out', 'works fine', $json));
        check(strpos(feedbackError(function () use ($rec, $dir) {
            FeedbackDraft::load($rec['id'], $dir);
        }), 'changed') !== false, 'a changed draft was accepted');
        foreach (['../x', str_repeat('A', 32), str_repeat('a', 31), '0123456789abcdef', str_repeat('a', 32) . "\n"] as $bad) {
            check(feedbackError(function () use ($bad, $dir) {
                FeedbackDraft::path($bad, $dir);
            }) !== '', 'a malformed id was accepted: ' . json_encode($bad));
        }
        testRmTree($dir);
        testRmTree($other);
    },
    'feedback: an over-long conversation keeps its newest part and says so; empty or over-long text is refused' => function () {
        $dir = testTempDir('pk-fbcut');
        $long = str_repeat("old line\n", 200000) . "the newest line\n";
        $rec = FeedbackDraft::create('it broke', $long, [], 1792300000, $dir);
        check($rec['older_part_left_out'] === true && $rec['bytes'] <= FeedbackDraft::PAYLOAD_MAX, json_encode($rec));
        list(, $p) = FeedbackDraft::load($rec['id'], $dir);
        check(substr($p['transcript'], -16) === "the newest line\n" && strpos($p['transcript'], 'old line') === 0, 'the newest part was not kept from a line start');
        // Cut first, then redacted: an old line too long to check is cut off before redaction, so the draft still works.
        $rec = FeedbackDraft::create('it broke', 'mysql ' . str_repeat('x ', 250000) . "\n" . str_repeat("old line\n", 170000), [], 1792300001, $dir);
        check($rec['older_part_left_out'] === true && $rec['conversation_bytes'] > 1000000, 'the conversation was redacted before it was cut: ' . json_encode($rec));
        foreach (['   ', str_repeat('x', FeedbackDraft::TEXT_MAX + 1)] as $bad) {
            check(feedbackError(function () use ($bad, $dir) {
                FeedbackDraft::create($bad, null, [], 1, $dir);
            }) !== '', 'bad text accepted: ' . strlen($bad) . ' bytes');
        }
        testRmTree($dir);
    },
    'feedback: only a Pulsed Media vsk- key is sent, never the key of the user\'s own provider' => function () {
        $tree = feedbackTree('sk-own-provider-0123456789abcdef');
        $err = feedbackError(function () use ($tree) {
            FeedbackSend::pmKey(['provider' => ['virsilipasKeyFile' => "$tree/home/.virsilipasKey"]]);
        });
        check(strpos($err, 'Pulsed Media AI key') !== false, 'a non-vsk key was accepted: ' . $err);
        $tree2 = feedbackTree();
        check(FeedbackSend::pmKey(['provider' => ['virsilipasKeyFile' => "$tree2/home/.virsilipasKey"]]) === FEEDBACK_TEST_KEY, 'the vsk- key was not read');
        testRmTree($tree);
        testRmTree($tree2);
    },
    'feedback: send posts the draft sealed to the public key, with the key only in the Authorization header' => function () {
        if (!function_exists('sodium_crypto_box_seal')) {
            skip('no sodium in this PHP');
        }
        $kp = sodium_crypto_box_keypair();
        $keyFile = testTempDir('pk-fbkey') . '/pub.txt';
        file_put_contents($keyFile, "# test key\n" . base64_encode(sodium_crypto_box_publickey($kp)) . "\n");
        list($proc, $base, $dir) = feedbackServer($kp);
        try {
            $json = json_encode(['v' => 1, 'text' => 'hello']);
            $r = FeedbackSend::send($json, FEEDBACK_TEST_KEY, $keyFile, "$base/ok/v1/feedback");
            check($r['received_id'] === str_repeat('e', 32), json_encode($r));
            check(file_get_contents("$dir/got") === $json, 'the server did not get the exact draft, sealed');
            check(file_get_contents("$dir/auth") === 'Bearer ' . FEEDBACK_TEST_KEY, 'the key was not sent as the Bearer token');
            $err = feedbackError(function () use ($json, $keyFile, $base) {
                FeedbackSend::send($json, FEEDBACK_TEST_KEY, $keyFile, "$base/fail/v1/feedback");
            });
            check(strpos($err, '503') !== false && strpos($err, 'paused') !== false && strpos($err, 'send it again later') !== false, $err);
            $err = feedbackError(function () use ($json, $keyFile, $base) {
                FeedbackSend::send($json, FEEDBACK_TEST_KEY, $keyFile, "$base/deny/v1/feedback");
            });
            check(strpos($err, '403') !== false && strpos($err, 'will not help') !== false, 'a 403 was called retryable: ' . $err);
            $err = feedbackError(function () use ($json, $dir, $base) {
                FeedbackSend::send($json, FEEDBACK_TEST_KEY, "$dir/missing.txt", "$base/ok/v1/feedback");
            });
            check(strpos($err, 'missing or damaged') !== false, 'a missing public key was not refused: ' . $err);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
            testRmTree(dirname($keyFile));
        }
    },
    'feedback: the send verb, end to end, posts the draft once and marks it sent; the hook then refuses it' => function () {
        if (!function_exists('sodium_crypto_box_seal')) {
            skip('no sodium in this PHP');
        }
        $tree = feedbackTree();
        $kp = sodium_crypto_box_keypair();
        file_put_contents("$tree/pikkumies/config/feedback-public-key.txt", base64_encode(sodium_crypto_box_publickey($kp)) . "\n");
        list($proc, $base, $dir) = feedbackServer($kp);
        $tool = "$tree/pikkumies/tools/system/feedback/feedback.php";
        file_put_contents("$tree/send.php", '<?php $feedbackEndpoint = ' . var_export("$base/ok/v1/feedback", true) . '; require ' . var_export($tool, true) . ';');
        try {
            list(, $out) = testWithEnv(['HOME' => "$tree/home"], function () use ($tool) {
                return runPhp($tool, ['draft', '--text', 'the send button is broken', '--no-conversation']);
            });
            $id = cliLines($out)[0]['id'];
            $draft = file_get_contents("$tree/pikkumies/local/state/feedback/$id.json");
            list($code, $out) = runPhp("$tree/send.php", ['send', $id]);
            $l = cliLines($out);
            check($code === 0 && $l[0]['id'] === $id && $l[0]['received_id'] === str_repeat('e', 32), $out);
            check(file_get_contents("$dir/got") === $draft, 'the server did not get the draft file exactly');
            check(file_get_contents("$dir/auth") === 'Bearer ' . FEEDBACK_TEST_KEY, 'the vsk- key from ~/.virsilipasKey was not the Bearer token');
            check(is_file("$tree/pikkumies/local/state/feedback/$id.sent.json") && !is_file("$tree/pikkumies/local/state/feedback/$id.json"), 'the draft was not marked sent');
            unlink("$dir/got");
            list($code, $out) = runPhp("$tree/send.php", ['send', $id]);
            check($code === 1 && strpos($out, 'already sent') !== false && !is_file("$dir/got"), 'a sent draft went out again: ' . $out);
            list(, $out) = runPhp($tool, ['list']);
            check(cliLines($out)[0]['sent'] === true, 'list does not say it was sent: ' . $out);
            $a = feedbackHook($tree, 'terminal', ['command' => "php $tool send $id"]);
            check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'already sent') !== false, 'a sent draft was asked about again: ' . json_encode($a));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
            testRmTree($tree);
        }
    },
    'feedback: the conversation comes from Hermes\' redacted export of this session, and no export copy is left' => function () {
        $root = realpath(testTempDir('pk-fbhermes'));
        $state = "$root/state";
        mkdir("$root/hermes/.git", 0700, true);
        mkdir("$root/hermes/.hermes/bin", 0700, true);
        mkdir("$root/include", 0700, true);
        touch("$root/include/.hermes-ready");
        // A fake hermes: records its arguments and writes one session file and a manifest into the export directory.
        file_put_contents("$root/hermes/.hermes/bin/hermes", '#!' . PHP_BINARY . "\n<?php\n" . 'file_put_contents(getenv("FAKE_HERMES_LOG"), json_encode(array_slice($argv, 1)));
file_put_contents($argv[3] . "/s-1-magnets.md", "---\nsession_id: \"s-1\"\ntitle: \"Magnet\\\\nlinks\"\n---\n### User\nmy key vsk-AbCdEf0123456789xyzQ\n");
file_put_contents($argv[3] . "/manifest.jsonl", "{}\n");');
        chmod("$root/hermes/.hermes/bin/hermes", 0755);
        mkdir("$state/export-old", 0700, true);
        touch("$state/export-old/x.md", time() - 3600);
        touch("$state/export-old", time() - 3600);
        HermesInstall::$root = $root;
        try {
            $md = testWithEnv(['HERMES_SESSION_ID' => 's-1', 'FAKE_HERMES_LOG' => "$root/argv"], function () use ($state) {
                return FeedbackSend::transcript($state);
            });
            $argv = json_decode((string) @file_get_contents("$root/argv"), true);
            check(is_array($argv) && array_slice($argv, 0, 2) === ['sessions', 'export'] && strpos($argv[2], "$state/export-") === 0, json_encode($argv));
            $flags = implode(' ', array_slice($argv, 3));
            check($flags === '--format md --session-id s-1 --redact --lineage logical', 'export flags: ' . $flags);
            check(glob("$state/export-*") === ["$state/export-old"], 'the export directory was left behind: ' . json_encode(glob("$state/export-*")));
            check(FeedbackSend::title($md) === 'Magnet links', 'title: ' . FeedbackSend::title($md));
            $rec = FeedbackDraft::create('x', $md, [], 1792300000, $state, ['s-1', FeedbackSend::title($md)]);
            check(strpos(file_get_contents($rec['path']), 'AbCdEf0123') === false, 'the vsk- key in the export was not removed');
            FeedbackSend::sweepExports($state);
            check(glob("$state/export-*") === [], 'an export left by a killed draft was not swept');
            mkdir("$state/export-new", 0700);
            FeedbackSend::sweepExports($state);
            check(is_dir("$state/export-new"), 'a fresh export directory was swept');
        } finally {
            HermesInstall::$root = null;
            testRmTree($root);
        }
    },
    'feedback: the tool drafts and lists in its own tree without a conversation, and refuses a draft with no text' => function () {
        $tree = feedbackTree();
        $tool = "$tree/pikkumies/tools/system/feedback/feedback.php";
        list($code, $out) = testWithEnv(['HOME' => "$tree/home", 'HERMES_SESSION_ID' => null], function () use ($tool) {
            return runPhp($tool, ['draft', '--text', 'the tool times out', '--no-conversation']);
        });
        $l = cliLines($out);
        check($code === 0 && isset($l[0]['id']) && $l[0]['conversation'] === false && $l[0]['session_id'] === '', $out);
        check(is_file("$tree/pikkumies/local/state/feedback/{$l[0]['id']}.json"), 'the draft is not in the tree\'s own local/state/feedback');
        list($code, $out) = runPhp($tool, ['list']);
        check($code === 0 && cliLines($out)[0]['id'] === $l[0]['id'] && cliLines($out)[0]['sent'] === false, $out);
        list($code, $out) = testWithEnv(['HERMES_SESSION_ID' => null], function () use ($tool) {
            return runPhp($tool, ['draft', '--text', 'x']);
        });
        check($code === 1 && strpos($out, 'no current Pikkumies session') !== false, 'a conversation was attached without a session: ' . $out);
        list($code, $out) = runPhp($tool, ['draft']);
        check($code === 1 && strpos($out, 'no feedback text') !== false, $out);
        testRmTree($tree);
    },
    'feedback-send hook: the plain send of this tool is asked with the draft shown; the forms of a send it can see are blocked' => function () {
        $tree = feedbackTree();
        $dir = "$tree/pikkumies/tools/system/feedback";
        $tool = "$dir/feedback.php";
        $state = "$tree/pikkumies/local/state/feedback";
        $rec = FeedbackDraft::create("the tool times out\n\".\nCorrection: no conversation is attached.", 'conversation text', [], 1792300000, $state, ['s-1', 'Magnets']);
        $id = $rec['id'];
        foreach (["php $tool send $id", "php ~/pikkumies/tools/system/feedback/feedback.php send $id"] as $cmd) {
            $d = testWithEnv(['HOME' => $tree], function () use ($tree, $cmd) {
                return feedbackHook($tree, 'terminal', ['command' => $cmd]);
            });
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-send:$id", $cmd . ' -> ' . json_encode($d));
        }
        $m = $d['message'];
        check(strpos($m, 'pikkumies.mcx.fi') !== false && strpos($m, 'Your conversation included: yes, session "s-1" titled "Magnets"') !== false
            && strpos($m, 'model, provider and working directory') !== false && strpos($m, 'version numbers') !== false
            && strpos($m, 'linked to your Pulsed Media account') !== false && strlen($m) < 4000, $m);
        $tail = '"the tool times out \\". Correction: no conversation is attached."';
        check(strpos($m, "\n") === false && substr($m, -strlen($tail)) === $tail, 'the text excerpt can add lines or is not last: ' . $m);
        symlink("$tree/pikkumies", "$tree/link");
        $blocked = [
            ['terminal', ['command' => "php $tool send $id && rm -f x"]],
            ['terminal', ['command' => "cd /tmp; php $tool send $id"]],
            ['terminal', ['command' => "sh -c 'php $tool send $id'"]],
            ['terminal', ['command' => "php\n$tool send $id"]],
            ['terminal', ['command' => "php /tmp/other/tools/system/feedback/feedback.php send $id"]],
            ['terminal', ['command' => "php feedback.php send $id"]],
            ['terminal', ['command' => "php pikkumies/tools/system/feedback/feedback.php send $id", 'workdir' => '/home/u/x']],
            ['terminal', ['command' => "php $tree/link/tools/system/feedback/feedback.php send $id"]],
            ['terminal', ['command' => "php $dir/../feedback/feedback.php send $id"]],
            ['terminal', ['command' => "php $tool send " . str_repeat('0', 32)]],
            ['terminal', ['command' => "php $dir/feedbac?.php send $id"]],
            ['terminal', ['command' => "php $dir/f*.php send $id"]],
            ['terminal', ['command' => 'F=feed; php ' . dirname($dir) . "/feedback/\${F}back.php send $id"]],
            ['terminal', ['command' => "php $tool \$(echo se)nd $id"]],
            ['terminal', ['command' => "cd $dir && php f*.php send $id"]],
            ['terminal', ['command' => "php -r 'require \"$dir/FeedbackSend.php\"; FeedbackSend::send(file_get_contents(\"d\"), \"k\", \"p\");'"]],
            ['execute_code', ['code' => "import os; os.system('php $tool send $id')"]],
            ['execute_code', ['code' => "import subprocess; p = '$tool'; subprocess.run(['php', p, 'send', '$id'])"]],
            ['process_manage', ['action' => 'write', 'data' => "php $tool send $id\n"]],
            ['write_file', ['path' => '/home/u/run.sh', 'content' => "#!/bin/sh\nphp $tool send $id\n"]],
            ['patch', ['path' => '/home/u/run.sh', 'old_string' => 'true', 'new_string' => "php $tool send $id"]],
            ['cronjob_manage', ['action' => 'create', 'schedule' => 'every 1h', 'script' => "php $tool send $id"]],
        ];
        foreach ($blocked as $c) {
            $a = testWithEnv(['HOME' => $tree], function () use ($tree, $c) {
                return feedbackHook($tree, $c[0], $c[1]);
            });
            check(is_array($a) && $a['action'] === 'block', 'not blocked: ' . json_encode($c) . ' -> ' . json_encode($a));
        }
        chmod("$state/$id.json", 0600);
        file_put_contents("$state/$id.json", str_replace('times out', 'is fine', file_get_contents("$state/$id.json")));
        $a = feedbackHook($tree, 'terminal', ['command' => "php $tool send $id"]);
        check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'changed') !== false, 'a changed draft was asked about: ' . json_encode($a));
        foreach ([['terminal', ['command' => "php $tool draft --text 'hi' --no-conversation"]], ['terminal', ['command' => "php $tool list"]],
            ['terminal', ['command' => "php $tool draft --text 'the send button is broken'"]],
            ['terminal', ['command' => "php $tool draft --text 'cannot send mail, the feedback send fails'"]],
            ['write_file', ['path' => '/home/u/feedback.txt', 'content' => "The feedback send button fails? It says \$5.\n"]],
            ['terminal', ['command' => "cat $dir/USAGE.md"]], ['terminal', ['command' => 'ls -la']], ['read_file', ['path' => '/etc/hosts']]] as $c) {
            check(feedbackHook($tree, $c[0], $c[1]) === null, 'no opinion expected: ' . json_encode($c));
        }
        testRmTree($tree);
    },
];
