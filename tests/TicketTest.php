<?php
/**
 * /ticket (ADR-0089): the draft is exactly what is posted and cannot change unseen, common secret forms are removed from
 * the subject, the message and text files, the account email is asked once and remembered, files are checked and their
 * names cleaned, only a Pulsed Media vsk- key is sent and never printed, a draft goes out once, every answer of the
 * endpoint reads plainly, and the guard asks only for the plain send and refuses it where nobody would be asked. The
 * tool and the hook run in a throwaway tree against a php -S stand-in, so the checkout's local/ and the network are
 * never used.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/ticket/TicketDraft.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/ticket/TicketSend.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/ticket/TicketPrompt.php';

const TICKET_TEST_KEY = 'vsk-test-only-not-a-real-key';

if (!function_exists('ticketTree')) {
    /** A pikkumies tree with lib, the ticket unit and its hook; local/ holds a config whose PM key file holds $key. */
    function ticketTree($key = TICKET_TEST_KEY)
    {
        $tmp = realpath(testTempDir('pk-ticket'));
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/system/ticket', 'hooks/guard/ticket-send'] as $d) {
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

    /** Runs the tree's tool through a wrapper that points it at $endpoint and at $pmss as the PMSS marker. */
    function ticketRun($tree, array $args, $endpoint = 'https://example.invalid/none', $pmss = '/nonexistent-pmss')
    {
        $tool = "$tree/pikkumies/tools/system/ticket/ticket.php";
        file_put_contents("$tree/run.php", '<?php $ticketEndpoint = ' . var_export($endpoint, true) . '; $ticketPmssDir = '
            . var_export($pmss, true) . '; require ' . var_export($tool, true) . ';');
        return testWithEnv(['HOME' => "$tree/home"], function () use ($tree, $args) {
            return runPhp("$tree/run.php", $args);
        });
    }

    /** Runs the tree's hook on a tool call; returns the decoded answer, or null for no opinion. */
    function ticketHook($tree, $tool, array $input, array $env = [])
    {
        list(, $out) = testWithEnv($env + ['HOME' => $tree, 'HERMES_YOLO_MODE' => null, 'PIKKUMIES_DEPTH' => null], function () use ($tree, $tool, $input) {
            return runPhp("$tree/pikkumies/hooks/guard/ticket-send/ticket-send.php", [],
                json_encode(['tool_name' => $tool, 'tool_input' => $input, 'session_id' => 's-1']));
        });
        return trim($out) === '' ? null : json_decode($out, true);
    }

    /** A php -S stand-in for the ticket endpoint: the first path part picks the answer; it keeps the body and the auth. */
    function ticketServer()
    {
        $dir = testTempDir('pk-ticket-srv');
        file_put_contents("$dir/router.php", '<?php
$mode = explode("/", parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH))[1];
file_put_contents(__DIR__ . "/got", file_get_contents("php://input"));
file_put_contents(__DIR__ . "/auth", isset($_SERVER["HTTP_AUTHORIZATION"]) ? $_SERVER["HTTP_AUTHORIZATION"] : "");
$answers = ["ok" => [201, ["ticket" => "482913"]], "nonum" => [201, []], "bad" => [400, ["error" => "subject is empty"]],
    "auth" => [401, ["error" => "not accepted"]], "big" => [413, ["error" => "too large"]], "many" => [429, ["error" => "too many requests, try again later"]],
    "down" => [503, ["error" => "temporarily unavailable"]], "odd" => [502, ["error" => "bad gateway"]]];
list($code, $body) = isset($answers[$mode]) ? $answers[$mode] : [404, ["error" => "no"]];
http_response_code($code);
echo json_encode($body);');
        $port = testFreePort();
        $p = proc_open('exec ' . escapeshellarg(PHP_BINARY) . ' -S 127.0.0.1:' . $port . ' ' . escapeshellarg("$dir/router.php"), [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        usleep(300000);
        return [$p, "http://127.0.0.1:$port", $dir];
    }

    function ticketError(callable $fn)
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
    'ticket: a draft is saved once, read-only, named by its sha256, with secrets removed from subject, message and text files' => function () {
        $dir = testTempDir('pk-tkdraft');
        $log = "$dir/rtorrent error (1)ä.log";
        file_put_contents($log, "started\ncurl -H 'Authorization: Bearer Or4cleLog1' x\npassword=Or4cleLog2\n");
        $rec = TicketDraft::create("Login   fails\nvsk-AbCdEf0123456789xyzQ", "It fails.\npassword=Or4cleMsg1 and TOKEN=Or4cleMsg2", 'jane@example.com', 'high', 'jane', [$log], 1792300000, $dir);
        check(preg_match('/^[0-9a-f]{32}$/', $rec['id']) === 1 && substr(hash_file('sha256', $rec['path']), 0, 32) === $rec['id'], json_encode($rec));
        check((fileperms($rec['path']) & 0777) === 0400, sprintf('mode %o', fileperms($rec['path']) & 0777));
        $raw = file_get_contents($rec['path']);
        list(, $p) = TicketDraft::load($rec['id'], $dir);
        $file = base64_decode($p['attachments'][0]['data']);
        check(strpos($raw . $file, 'Or4cle') === false && strpos($raw, 'AbCdEf0123') === false, 'a secret form was kept: ' . $raw . $file);
        check(strpos($p['subject'], "\n") === false && strpos($p['subject'], 'Login fails') === 0 && $p['priority'] === 'High', json_encode($p));
        check($rec['to'] === 'Pulsed Media support (pulsedmedia.com)' && $rec['email'] === 'jane@example.com' && $rec['service'] === 'jane'
            && $rec['attachments'] === [['name' => 'rtorrent error _1_.log', 'bytes' => strlen($file)]] && strpos($rec['message_start'], 'It fails.') === 0, json_encode($rec));
        $body = json_decode(TicketDraft::body($p), true);
        check(array_keys($body) === ['email', 'subject', 'message', 'priority', 'service', 'attachments'], 'body: ' . json_encode(array_keys($body)));
        chmod($rec['path'], 0600);
        file_put_contents($rec['path'], str_replace('fails', 'works', $raw));
        check(strpos(ticketError(function () use ($rec, $dir) {
            TicketDraft::load($rec['id'], $dir);
        }), 'changed') !== false, 'a changed draft was accepted');
        foreach ([['', 'x'], ['x', ''], [str_repeat('s', 201), 'x'], ['x', str_repeat('m', 20001)], ['x', str_repeat('"', 7000)]] as $bad) {
            check(ticketError(function () use ($bad, $dir) {
                TicketDraft::create($bad[0], $bad[1], 'jane@example.com', 'Medium', '', [], 1, $dir);
            }) !== '', 'bad subject or message accepted: ' . strlen($bad[0]) . '/' . strlen($bad[1]));
        }
        check(ticketError(function () use ($dir) {
            TicketDraft::create('x', 'y', 'jane@example.com', 'Urgent', '', [], 1, $dir);
        }) !== '', 'an unknown priority was accepted');
        testRmTree($dir);
    },
    'ticket: the account email is asked once, remembered 0600, replaced by a new --email; a quoted or odd address is refused' => function () {
        $dir = testTempDir('pk-tkemail');
        check(strpos(ticketError(function () use ($dir) {
            TicketDraft::create('x', 'y', null, 'Medium', '', [], 1, $dir);
        }), 'no account email yet') !== false, 'a draft without any email was made');
        TicketDraft::create('x', 'y', 'jane@example.com', 'Medium', '', [], 1, $dir);
        check(trim(file_get_contents("$dir/email")) === 'jane@example.com' && (fileperms("$dir/email") & 0777) === 0600, 'the email was not remembered 0600');
        $rec = TicketDraft::create('x', 'second', null, 'Medium', '', [], 2, $dir);
        check($rec['email'] === 'jane@example.com', 'the remembered email was not used: ' . json_encode($rec));
        TicketDraft::create('x', 'third', 'joe@example.org', 'Medium', '', [], 3, $dir);
        check(trim(file_get_contents("$dir/email")) === 'joe@example.org', 'a new --email did not replace the remembered one');
        foreach (['"a b? Text: yes"@example.com', 'jane', 'jane@example', "jane@example.com\nx"] as $bad) {
            check(strpos(ticketError(function () use ($bad, $dir) {
                TicketDraft::create('x', 'y', $bad, 'Medium', '', [], 4, $dir);
            }), 'not an email address') !== false, 'a bad email was accepted: ' . json_encode($bad));
        }
        testRmTree($dir);
    },
    'ticket: files are at most FILES_MAX (32), each at most FILE_MAX (8 MiB), of the allowed types and starting as one, this account\'s, never a symlink' => function () {
        $dir = testTempDir('pk-tkfiles');
        $make = function ($name, $data) use ($dir) {
            file_put_contents("$dir/$name", $data);
            return "$dir/$name";
        };
        $ok = $make('a.txt', 'hello');
        $png = $make('shot.PNG', "\x89PNG\r\n\x1a\n" . str_repeat("\x00", 10));
        $rec = TicketDraft::create('x', 'y', 'jane@example.com', 'Medium', '', [$ok, $png], 1, $dir . '/state');
        check(count($rec['attachments']) === 2 && $rec['attachments'][1]['name'] === 'shot.PNG', json_encode($rec['attachments']));
        $refused = [
            'one file over FILES_MAX' => array_fill(0, TicketDraft::FILES_MAX + 1, $ok),
            'too big' => [$make('big.log', str_repeat('x', TicketDraft::FILE_MAX + 1))],
            'a type not allowed' => [$make('notes.md', 'x')],
            'no extension' => [$make('README', 'x')],
            'an image that is not one' => [$make('fake.png', 'password=x')],
            'a pdf that is not one' => [$make('fake.pdf', 'hello')],
            'a binary text file' => [$make('bin.log', "a\0b")],
            'a folder' => [$dir],
            'missing' => ["$dir/nope.txt"],
        ];
        symlink($ok, "$dir/link.txt");
        $refused['a symlink'] = ["$dir/link.txt"];
        foreach ($refused as $what => $files) {
            check(ticketError(function () use ($files, $dir) {
                TicketDraft::create('x', 'y', 'jane@example.com', 'Medium', '', $files, 1, $dir . '/state');
            }) !== '', "$what was attached");
        }
        foreach (['../../etc/x y?.txt' => 'x y_.txt', '.hidden.log' => 'hidden.log', 'ä€ö.pdf' => '_.pdf', str_repeat('n', 150) . '.txt' => str_repeat('n', 96) . '.txt'] as $name => $want) {
            $f = $make('src.' . pathinfo($name, PATHINFO_EXTENSION), pathinfo($name, PATHINFO_EXTENSION) === 'pdf' ? '%PDF-1.4' : 'x');
            $base = substr((string) strrchr('/' . $name, '/'), 1); // not basename(): locale-dependent before PHP 8
            rename($f, "$dir/$base");
            $a = TicketDraft::attachment("$dir/$base");
            check($a['name'] === $want && preg_match('/^[A-Za-z0-9._ -]+$/', $a['name']), json_encode($name) . ' -> ' . json_encode($a['name']));
        }
        testRmTree($dir);
    },
    'ticket: send posts the draft body once with the vsk- key as Bearer, prints the ticket number, and never sends a sent draft' => function () {
        $tree = ticketTree();
        list($proc, $base, $dir) = ticketServer();
        try {
            list($code, $out) = ticketRun($tree, ['draft', '--subject', 'Web UI down', '--text', 'The web UI gives 502.', '--email', 'jane@example.com']);
            $id = cliLines($out)[0]['id'];
            check($code === 0 && is_string($id), $out);
            list($code, $out) = ticketRun($tree, ['send', $id], "$base/ok/remote/ticket.php");
            $l = cliLines($out);
            check($code === 0 && $l[0]['id'] === $id && $l[0]['ticket'] === '482913', $out);
            $p = json_decode(file_get_contents("$tree/pikkumies/local/state/ticket/$id.sent.json"), true);
            check(file_get_contents("$dir/got") === TicketDraft::body($p), 'the server did not get exactly the draft body');
            check(file_get_contents("$dir/auth") === 'Bearer ' . TICKET_TEST_KEY, 'the vsk- key was not the Bearer token');
            check(strpos($out, TICKET_TEST_KEY) === false, 'the key was printed');
            unlink("$dir/got");
            list($code, $out) = ticketRun($tree, ['send', $id], "$base/ok/remote/ticket.php");
            check($code === 1 && strpos($out, 'already sent') !== false && !is_file("$dir/got"), 'a sent draft went out again: ' . $out);
            list(, $out) = ticketRun($tree, ['list']);
            check(cliLines($out)[0]['sent'] === true && cliLines($out)[0]['subject'] === 'Web UI down', $out);
            $a = ticketHook($tree, 'terminal', ['command' => "php $tree/pikkumies/tools/system/ticket/ticket.php send $id"]);
            check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'already sent') !== false, 'a sent draft was asked about again: ' . json_encode($a));
            // A 201 without a number still marks the draft sent: it was opened.
            list(, $out) = ticketRun($tree, ['draft', '--subject', 'Second', '--text', 'another']);
            $id2 = cliLines($out)[0]['id'];
            list($code, $out) = ticketRun($tree, ['send', $id2], "$base/nonum/x");
            check($code === 0 && cliLines($out)[0]['ticket'] === '' && is_file("$tree/pikkumies/local/state/ticket/$id2.sent.json"), $out);
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
            testRmTree($tree);
        }
    },
    'ticket: every answer code reads plainly, says whether trying again helps, keeps the draft, and never prints the key' => function () {
        $tree = ticketTree();
        list($proc, $base, $dir) = ticketServer();
        try {
            list(, $out) = ticketRun($tree, ['draft', '--subject', 'x', '--text', 'y', '--email', 'jane@example.com']);
            $id = cliLines($out)[0]['id'];
            $want = ['bad' => ['malformed: subject is empty', 'will not help'], 'auth' => ['the key or email was not accepted', 'whatever did not match'],
                'big' => ['too large', 'fewer or smaller'], 'many' => ['too many', 'again later'], 'down' => ['unavailable', 'again later'],
                'odd' => ['HTTP 502', 'may or may not have been opened']];
            foreach ($want as $mode => $w) {
                list($code, $out) = ticketRun($tree, ['send', $id], "$base/$mode/x");
                $l = cliLines($out);
                $last = end($l);
                check($code === 1 && strpos($last['error'], $w[0]) !== false && strpos($last['hint'], $w[1]) !== false, "$mode: $out");
                check(strpos($out, TICKET_TEST_KEY) === false && strpos($out, 'not-a-real-key') === false, "$mode printed the key: $out");
                check(is_file("$tree/pikkumies/local/state/ticket/$id.json"), "$mode lost the draft");
            }
            list($code, $out) = ticketRun($tree, ['send', $id], 'http://127.0.0.2:9/plain'); // nobody listens: no answer at all
            check($code === 1 && (strpos($out, 'not https') !== false), 'a plain-http address that is not this machine was used: ' . $out);
            list($code, $out) = ticketRun($tree, ['send', $id], 'http://127.0.0.1:' . testFreePort() . '/x');
            check($code === 1 && strpos($out, 'could not reach') !== false && strpos($out, 'may or may not') !== false, $out);
            $auth = cliLines(ticketRun($tree, ['send', $id], "$base/auth/x")[1]);
            check(end($auth)['error'] === 'the key or email was not accepted', 'the 401 says more than the server did: ' . json_encode($auth));
        } finally {
            proc_terminate($proc);
            proc_close($proc);
            testRmTree($dir);
            testRmTree($tree);
        }
    },
    'ticket: only a Pulsed Media vsk- key is used, never the user\'s own provider key' => function () {
        $tree = ticketTree('sk-own-provider-0123456789abcdef');
        check(strpos(ticketError(function () use ($tree) {
            TicketSend::key(['provider' => ['virsilipasKeyFile' => "$tree/home/.virsilipasKey"]]);
        }), 'Pulsed Media AI key') !== false, 'a non-vsk key was accepted');
        list(, $out) = ticketRun($tree, ['draft', '--subject', 'x', '--text', 'y', '--email', 'jane@example.com']);
        list($code, $out) = ticketRun($tree, ['send', cliLines($out)[0]['id']], 'http://127.0.0.1:9/x');
        check($code === 1 && strpos($out, 'Pulsed Media AI key') !== false && strpos($out, 'sk-own') === false, $out);
        testRmTree($tree);
    },
    'ticket: on a Pulsed Media seedbox the service is this Unix user unless --service or --no-service says otherwise' => function () {
        $tree = ticketTree();
        $pmss = testTempDir('pk-tkpmss');
        $me = function_exists('posix_getpwuid') ? posix_getpwuid(posix_geteuid())['name'] : getenv('USER');
        $run = function ($extra, $marker) use ($tree) {
            list(, $out) = ticketRun($tree, array_merge(['draft', '--subject', 'x', '--text', uniqid('y', true), '--email', 'jane@example.com'], $extra), 'https://example.invalid/x', $marker);
            return cliLines($out)[0]['service'];
        };
        check($run([], $pmss) === $me, 'the seedbox user was not the default service');
        check($run(['--no-service'], $pmss) === '' && $run(['--service', '12345'], $pmss) === '12345', 'the service options were not followed');
        check($run([], '/nonexistent-pmss') === '', 'a service was guessed off a Pulsed Media seedbox');
        testRmTree($pmss);
        testRmTree($tree);
    },
    'ticket-send hook: the plain send is asked with the draft shown, needs the user\'s yes, and is refused under yolo or at depth 2' => function () {
        $tree = ticketTree();
        $tool = "$tree/pikkumies/tools/system/ticket/ticket.php";
        $state = "$tree/pikkumies/local/state/ticket";
        file_put_contents("$tree/rt.log", "line\n");
        $rec = TicketDraft::create('Web UI 502', "It says \"bad gateway\".\nSince Monday.", 'jane@example.com', 'Medium', 'jane', ["$tree/rt.log"], 1792300000, $state);
        $id = $rec['id'];
        foreach (["php $tool send $id", "php ~/pikkumies/tools/system/ticket/ticket.php send $id"] as $cmd) {
            $d = ticketHook($tree, 'terminal', ['command' => $cmd]);
            check(is_array($d) && $d['action'] === 'approve' && $d['rule_key'] === "pikkumies-send:$id", $cmd . ' -> ' . json_encode($d));
        }
        $m = $d['message'];
        check(strpos($m, 'Open a Pulsed Media support ticket at pulsedmedia.com as jane@example.com') === 0 && strpos($m, 'Service jane, priority Medium')
            && strpos($m, 'files: rt.log (5 bytes)') && strpos($m, "~/pikkumies/local/state/ticket/$id.json") && HookUnits::approveShown($m)
            && strpos($m, "\n") === false && strpos($m, 'Text: "Web UI 502 | It says \\"bad gateway\\". Since Monday."') !== false, $m);
        check(AskPolicy::route(['action' => 'approve', 'unit' => 'guard/ticket-send', 'message' => $m]) === 'confirm', 'the ticket send does not need the user\'s own yes');
        $d = ticketHook($tree, 'terminal', ['command' => "php $tool send $id"], ['PIKKUMIES_DEPTH' => '2']);
        check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'It was not sent') !== false && strpos($d['message'], 'run it themselves') === false
            && strpos($d['message'], "php $tool send") === false, 'depth 2: ' . json_encode($d));
        $d = ticketHook($tree, 'terminal', ['command' => "php $tool send $id"], ['HERMES_YOLO_MODE' => '1']);
        check(is_array($d) && $d['action'] === 'approve', 'yolo (the default run mode) blocked the send instead of leaving it to the review: ' . json_encode($d));
        $blocked = [
            ['terminal', ['command' => "php $tool send $id && rm -f x"]],
            ['terminal', ['command' => "php /tmp/other/tools/system/ticket/ticket.php send $id"]],
            ['terminal', ['command' => "php $tree/pikkumies/tools/system/ticket/t*.php send $id"]],
            ['terminal', ['command' => "php $tool \$(echo se)nd $id"]],
            ['terminal', ['command' => "php $tool send " . str_repeat('0', 32)]],
            ['terminal', ['command' => "php -r 'require \"x/TicketSend.php\"; TicketSend::send(\"b\", \"k\");' # send"]],
            ['execute_code', ['code' => "import os; os.system('php $tool send $id')"]],
            ['write_file', ['path' => '/home/fixtureuser/run.sh', 'content' => "php $tool send $id\n"]],
            ['cronjob_manage', ['action' => 'create', 'schedule' => 'every 1h', 'script' => "php $tool send $id"]],
        ];
        foreach ($blocked as $c) {
            $a = ticketHook($tree, $c[0], $c[1]);
            check(is_array($a) && $a['action'] === 'block', 'not blocked: ' . json_encode($c) . ' -> ' . json_encode($a));
        }
        foreach ([['terminal', ['command' => "php $tool draft --subject 'cannot send mail' --text 'x'"]], ['terminal', ['command' => "php $tool list"]],
            ['terminal', ['command' => 'echo my support ticket is open']], ['read_file', ['path' => "$tool"]]] as $c) {
            check(ticketHook($tree, $c[0], $c[1]) === null, 'no opinion expected: ' . json_encode($c));
        }
        chmod("$state/$id.json", 0600);
        file_put_contents("$state/$id.json", str_replace('502', '503', file_get_contents("$state/$id.json")));
        $a = ticketHook($tree, 'terminal', ['command' => "php $tool send $id"]);
        check(is_array($a) && $a['action'] === 'block' && strpos($a['message'], 'changed') !== false, 'a changed draft was asked about: ' . json_encode($a));
        testRmTree($tree);
    },
];
