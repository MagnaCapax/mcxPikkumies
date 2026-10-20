<?php
/**
 * The exit-time session rating (ADR-0097, ADR-0056 amendment 2026-10-20): shown by bin/pikkumies after Hermes exits,
 * only for an eligible run on a terminal with a key and sodium; claimed before it is shown; a number sends exactly the
 * declared fields, rounded; 0, Enter and silence send nothing and snooze 30 days; n turns feedback.ask off; a failed send
 * is said once, never retried, and still snoozes; the local copy only with perfLogging on. The agent cannot run it.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackSurvey.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackSend.php';
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackTty.php';

if (!function_exists('feedbackSurveyRun')) {
    /**
     * One survey in $local with the answer $answer (false: silence). Returns [result, what was written, sends, records,
     * the timeout read() was given].
     */
    function feedbackSurveyRun($local, $run, $answer, array $config, $now, array $over = [], array $env = [])
    {
        $st = ['written' => '', 'sends' => [], 'records' => [], 'timeout' => null];
        $io = $over + ['ready' => true, 'model' => 'family/model-x1', 'read' => function ($t) use ($answer, &$st) {
            $st['timeout'] = $t;
            return $answer;
        }, 'write' => function ($t) use (&$st) {
            $st['written'] .= $t;
        }, 'send' => function ($json) use (&$st) {
            $st['sends'][] = $json;
            return ['received_id' => 'r1', 'received_at' => 1];
        }, 'versions' => function () {
            return ['pikkumies' => 'abc1234', 'hermes' => '0.22.2', 'pmss' => ''];
        }, 'record' => function (array $f) use (&$st) {
            $st['records'][] = $f;
        }];
        $r = testWithEnv($env + ['PIKKUMIES_DEPTH' => '1', 'HERMES_YOLO_MODE' => null], function () use ($config, $run, $now, $io, $local) {
            return FeedbackSurvey::run($config, $run, $now, $io, $local);
        });
        return [$r, $st['written'], $st['sends'], $st['records'], $st['timeout']];
    }

    /** A fresh local dir with an eligible run: 46 minutes, 612,345 tokens. */
    function feedbackSurveyLocal($run, $now, $minutes = 46, $tokens = 612345)
    {
        $local = testTempDir('pk-fbsurvey');
        SessionCounters::resetRun($local, $run, $now - $minutes * 60);
        SessionCounters::updateRun($local, $run, ['tokens' => $tokens]);
        return $local;
    }

    function feedbackSurveyNext($local)
    {
        clearstatcache();
        return @filemtime("$local/state/feedback-ask/next");
    }
}

$cfg = ['feedback' => ['ask' => true], 'privacy' => ['perfLogging' => true]];
$run = 'd1d2d3d4e5f60718';
$now = 1792500000;
return [
    'feedback survey: 1, 2 and 3 send exactly the declared fields, rounded; the answered snooze is 14 days' => function () use ($cfg, $run, $now) {
        foreach (['1' => 'Thanks. To tell us what went wrong, type /feedback in your next session.', " 2\n" => 'Thanks, sent.', '3' => 'Thanks, sent.'] as $answer => $thanks) {
            $local = feedbackSurveyLocal($run, $now);
            list($r, $written, $sends, $records, $timeout) = feedbackSurveyRun($local, $run, $answer, $cfg, $now);
            check($r === 'sent' && count($sends) === 1, json_encode($answer) . ': ' . json_encode([$r, $sends]));
            $p = json_decode($sends[0], true);
            check($p === ['v' => 1, 'kind' => 'rating', 'rating' => (int) trim($answer), 'minutes' => 45, 'tokens_k' => 600, 'model' => 'model-x1',
                'versions' => ['pikkumies' => 'abc1234', 'hermes' => '0.22.2', 'pmss' => '']], 'payload: ' . $sends[0]);
            check(strpos($written, FeedbackSurvey::LINE) !== false && strpos($written, FeedbackSurvey::DISCLOSURE) !== false && strpos($written, $thanks) !== false, 'shown: ' . $written);
            check($timeout === 20, 'the read must wait 20 seconds: ' . json_encode($timeout));
            check(feedbackSurveyNext($local) === $now + 14 * 86400, 'next: ' . json_encode(feedbackSurveyNext($local)));
            check($records === [['rating' => (int) trim($answer), 'minutes' => 45, 'tokens_k' => 600]], 'local copy: ' . json_encode($records));
            check(feedbackSurveyRun($local, $run, '3', $cfg, $now + 20 * 86400)[0] === '', 'asked twice in one run');
            testRmTree($local);
        }
        check(FeedbackSurvey::payload(2, ['since' => 1000, 'tokens' => 2549999], 1000 + 92 * 60, 'm', [])['minutes'] === 90
            && FeedbackSurvey::payload(2, ['since' => 1000, 'tokens' => 2549999], 1000 + 93 * 60, 'm', [])['tokens_k'] === 2500, 'rounding to 5 minutes and 100k tokens');
        check(strpos(FeedbackSurvey::DISCLOSURE, 'linked to your Pulsed Media account') !== false, 'the line must say the rating is linked to the account');
        check(strpos(FeedbackSurvey::DISCLOSURE, 'No conversation') !== false && strpos(FeedbackSurvey::LINE, '0 dismiss') !== false && strpos(FeedbackSurvey::LINE, 'n never ask') !== false, 'wording');
    },
    'feedback survey: 0, Enter and 20 seconds of silence send nothing and snooze 30 days' => function () use ($cfg, $run, $now) {
        foreach (['0', "\n", '', false, 'great', '4'] as $answer) {
            $local = feedbackSurveyLocal($run, $now);
            list($r, $written, $sends, $records) = feedbackSurveyRun($local, $run, $answer, $cfg, $now);
            check($r === 'dismissed' && $sends === [] && $records === [], json_encode($answer) . ': ' . json_encode([$r, $sends, $records]));
            check(feedbackSurveyNext($local) === $now + 30 * 86400, json_encode($answer) . ' next: ' . json_encode(feedbackSurveyNext($local)));
            check(SessionCounters::readRun($local, $run)['asked'] === 1, 'the run was not marked asked');
            testRmTree($local);
        }
    },
    'feedback survey: n turns feedback.ask off and sends nothing' => function () use ($cfg, $run, $now) {
        $local = feedbackSurveyLocal($run, $now);
        list($r, $written, $sends) = feedbackSurveyRun($local, $run, "N\n", $cfg, $now);
        check($r === 'never' && $sends === [] && strpos($written, 'Feedback questions are off.') !== false, json_encode([$r, $written, $sends]));
        $saved = require "$local/config.php";
        check($saved['feedback']['ask'] === false, 'feedback.ask was not saved off: ' . json_encode($saved));
        testRmTree($local);
    },
    'feedback survey: never shown without a key or sodium (ready), at depth 2, with feedback.ask off, below the thresholds or without a run; shown under yolo' => function () use ($cfg, $run, $now) {
        $cases = [
            'not ready' => [$cfg, ['ready' => false], [], 46, 612345, $run],
            'depth 2' => [$cfg, [], ['PIKKUMIES_DEPTH' => '2'], 46, 612345, $run],
            'off' => [['feedback' => ['ask' => false]] + $cfg, [], [], 46, 612345, $run],
            '44 minutes' => [$cfg, [], [], 44, 612345, $run],
            '499,999 tokens' => [$cfg, [], [], 46, 499999, $run],
            'no run' => [$cfg, [], [], 46, 612345, ''],
        ];
        foreach ($cases as $why => $c) {
            $local = feedbackSurveyLocal($run, $now, $c[3], $c[4]);
            $read = false;
            list($r, $written, $sends) = feedbackSurveyRun($local, $c[5], '3', $c[0], $now, $c[1] + ['read' => function () use (&$read) {
                $read = true;
                return '3';
            }], $c[2]);
            check($r === '' && $written === '' && $sends === [] && !$read && feedbackSurveyNext($local) === false, "$why: " . json_encode([$r, $written, $sends]));
            testRmTree($local);
        }
        $local = feedbackSurveyLocal($run, $now);
        list($r, , $sends) = feedbackSurveyRun($local, $run, '3', $cfg, $now, [], ['HERMES_YOLO_MODE' => '1']);
        check($r === 'sent' && count($sends) === 1, 'under --yolo (the default run mode) at depth 1 the rating must be shown: ' . json_encode([$r, $sends]));
        testRmTree($local);
    },
    'feedback survey: a failed send says why once, is not retried, and still snoozes' => function () use ($cfg, $run, $now) {
        $local = feedbackSurveyLocal($run, $now);
        $calls = 0;
        list($r, $written, , $records) = feedbackSurveyRun($local, $run, '2', $cfg, $now, ['send' => function () use (&$calls) {
            $calls++;
            throw new CliError('could not reach Pulsed Media (timeout)', 'later');
        }]);
        check($r === 'failed' && $calls === 1 && strpos($written, 'Not sent: could not reach Pulsed Media (timeout). Nothing is retried.') !== false, json_encode([$r, $calls, $written]));
        check(feedbackSurveyNext($local) === $now + 14 * 86400 && count($records) === 1, 'a failed send must still snooze and keep the local copy');
        testRmTree($local);
    },
    'feedback survey: the local copy only with perfLogging on' => function () use ($cfg, $run, $now) {
        $local = feedbackSurveyLocal($run, $now);
        list($r, , $sends, $records) = feedbackSurveyRun($local, $run, '3', ['privacy' => ['perfLogging' => false]] + $cfg, $now);
        check($r === 'sent' && count($sends) === 1 && $records === [], 'recorded with perfLogging off: ' . json_encode($records));
        testRmTree($local);
    },
    'feedback survey: the claim comes before the line; a budget that cannot be written shows nothing' => function () use ($cfg, $run, $now) {
        $local = feedbackSurveyLocal($run, $now);
        $seen = null;
        feedbackSurveyRun($local, $run, '0', $cfg, $now, ['write' => function () use (&$seen, $local, $run) {
            if ($seen === null) {
                $seen = [feedbackSurveyNext($local), SessionCounters::readRun($local, $run)['asked']];
            }
        }]);
        check($seen === [$now + 30 * 86400, 1], 'not claimed before the line was shown: ' . json_encode($seen));
        testRmTree($local);
        $local = feedbackSurveyLocal($run, $now);
        file_put_contents("$local/state/feedback-ask", 'a file');
        list($r, $written) = feedbackSurveyRun($local, $run, '3', $cfg, $now);
        check($r === '' && $written === '', 'shown although the ask could not be claimed');
        testRmTree($local);
    },
    'feedback survey: the launcher asks only after Hermes exits in the run case, and outside a terminal it is silent and quick' => function () use ($run) {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $pass = strpos($bin, "@passthru(escapeshellarg(hermesBin()) . ' ' . implode(' ', \$args), \$code);");
        $call = strpos($bin, 'FeedbackSurvey::atExit($passArgs);');
        $exit = strpos($bin, 'exit($code);', (int) $call);
        $tick = strpos($bin, "case 'cron-tick':");
        check($pass !== false && $call > $pass && $exit > $call && $exit < $tick && substr_count($bin, 'FeedbackSurvey::') === 1, 'atExit must sit between the session passthru and its exit, once');
        $tmp = testTempDir('pk-fbexit');
        file_put_contents("$tmp/x.php", '<?php foreach (glob(' . var_export(dirname(__DIR__) . '/pikkumies/lib', true) . ' . "/*.php") as $l) { require_once $l; }'
            . ' require_once ' . var_export(dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackSurvey.php', true) . ';'
            . ' $t = microtime(true); FeedbackSurvey::atExit(["chat"]); FeedbackSurvey::atExit([]); echo "done ", round(microtime(true) - $t);');
        list($code, $out) = testWithEnv(['PIKKUMIES_RUN' => $run], function () use ($tmp) {
            return runPhp("$tmp/x.php", [], "3\n");
        });
        check($code === 0 && $out === 'done 0', 'off a terminal atExit must print nothing and return at once: ' . json_encode([$code, $out]));
        testRmTree($tmp);
    },
    'feedback survey: the agent cannot run it: the guard blocks FeedbackSurvey calls, and no USAGE verb exposes it' => function () {
        $guard = dirname(__DIR__) . '/pikkumies/hooks/guard/feedback-send/feedback-send.php';
        $lib = dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackSurvey.php';
        foreach ([['terminal', ['command' => "php -r 'require \"$lib\"; FeedbackSurvey::atExit([]);'"]],
            ['execute_code', ['code' => "require '$lib';\ncall_user_func(['FeedbackSurvey', 'run'], [], '', 0, []);"]],
            ['write_file', ['content' => "<?php FeedbackSurvey :: atExit(['chat']);"]]] as $c) {
            list(, $out) = runPhp($guard, [], json_encode(['tool_name' => $c[0], 'tool_input' => $c[1]]));
            $d = json_decode(trim($out), true);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'session rating') !== false, $c[0] . ': ' . $out);
        }
        list(, $out) = runPhp($guard, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => "cat $lib"]]));
        check(trim($out) === '', 'reading the file must stay allowed: ' . $out);
        $usage = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/tools/system/feedback/USAGE.md');
        preg_match_all('/^\| `?([a-z-]+)`? \|/m', $usage, $verbs);
        check(!array_intersect($verbs[1], ['rating', 'rate', 'survey']) && stripos(implode(' ', $verbs[1]), 'survey') === false, 'a USAGE verb exposes the rating: ' . json_encode($verbs[1]));
        $send = new ReflectionMethod('FeedbackSend', 'send');
        $params = $send->getParameters();
        check(count($params) === 5 && $params[4]->getName() === 'timeout' && $params[4]->getDefaultValue() === 60, 'FeedbackSend::send must take a timeout, 60 by default');
    },
    'feedback survey: the model goes as a name, never a path; version strings are capped at 64 printable characters' => function () {
        foreach (['family/model-x1' => 'model-x1', 'model-a:7b' => 'model-a:7b', 'name@v2' => 'name@v2', '/home/fixtureuser/models/acme.gguf' => 'other',
            '~/models/x.gguf' => 'other', './x.gguf' => 'other', '../x' => 'other', 'models/../x' => 'other', 'a/home/fixtureuser' => 'other',
            '/srv/x.gguf' => 'other', 'C:\models\x.gguf' => 'other', "x\ny" => 'other', '' => 'other', 'x y' => 'other', '..' => 'other',
            str_repeat('a', 129) => 'other', 'u/srv-alice/x' => 'other'] as $in => $want) {
            check(FeedbackSurvey::modelName($in, '/srv-alice') === $want, json_encode($in) . ' -> ' . json_encode(FeedbackSurvey::modelName($in, '/srv-alice')));
        }
        $p = FeedbackSurvey::payload(2, ['since' => 0, 'tokens' => 0], 0, 'm', ['pikkumies' => "abc\x1b[2J1234", 'hermes' => str_repeat('9', 5010),
            'pmss' => "1.2\n3", 'Bad Key' => 'x', 'extra' => ['nested']]);
        check($p['versions'] === ['pikkumies' => 'abc[2J1234', 'hermes' => str_repeat('9', 64), 'pmss' => '1.23'], 'versions: ' . json_encode($p['versions']));
    },
    'feedback survey: the terminal is prepared before the line and restored after the read' => function () use ($cfg, $run, $now) {
        $local = feedbackSurveyLocal($run, $now);
        $log = [];
        feedbackSurveyRun($local, $run, '2', $cfg, $now, ['prepare' => function () use (&$log) {
            $log[] = 'prepare';
            return function () use (&$log) {
                $log[] = 'restore';
            };
        }, 'write' => function ($t) use (&$log) {
            $log[] = strpos($t, FeedbackSurvey::LINE) !== false ? 'line' : 'write';
        }, 'read' => function () use (&$log) {
            $log[] = 'read';
            return '2';
        }]);
        check(array_slice($log, 0, 4) === ['prepare', 'line', 'read', 'restore'], 'order: ' . json_encode($log));
        testRmTree($local);
    },
    'feedback tty: the read keeps one deadline, ends on \r or \n, and drain throws away what was typed before' => function () {
        $pair = function ($data, $close) {
            $s = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            fwrite($s[1], $data);
            if ($close) {
                fclose($s[1]);
            }
            return $s;
        };
        foreach ([["2\r", false, '2'], ["3\n", false, '3'], ["\n", false, ''], ["n\r\n", false, 'n'], ['1', false, false], ['', false, false],
            ['', true, false], [str_repeat('1', 80), false, false]] as $c) {
            $s = $pair($c[0], $c[1]);
            $t = microtime(true);
            $got = FeedbackTty::readLine($s[0], 0.5);
            $took = microtime(true) - $t;
            check($got === $c[2] && $took < 1.5, json_encode($c[0]) . ' -> ' . json_encode($got) . sprintf(' in %.2fs', $took));
        }
        $s = $pair("1\n", false);
        FeedbackTty::drain($s[0]);
        check(FeedbackTty::readLine($s[0], 0.3) === false, 'a line typed before the prompt was taken as the answer');
        fwrite($s[1], "2\r");
        check(FeedbackTty::readLine($s[0], 0.5) === '2', 'the answer typed after the drain was lost');
    },
    'feedback tty: on a real terminal left raw, type-ahead is dropped, a raw Enter answers, silence ends at the deadline, the mode is put back' => function () {
        if (!function_exists('proc_open') || ToolProc::which('stty') === null) {
            skip('no stty here');
        }
        $tmp = testTempDir('pk-fbtty');
        file_put_contents("$tmp/child.php", '<?php require ' . var_export(dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackTty.php', true) . ';'
            . ' if ($argv[1] === "raw") { system("stty raw -echo -icrnl -isig"); } $raw = trim(shell_exec("stty -g")); fwrite(STDERR, "R"); usleep(600000);'
            . ' $restore = FeedbackTty::prepare(); $mode = (string) shell_exec("stty -a"); $canon = preg_match("/(^|\\s)icanon\\b/", $mode) && preg_match("/(^|\\s)echo\\b/", $mode) && preg_match("/(^|\\s)isig\\b/", $mode); fwrite(STDERR, "P"); $t = microtime(true); $a = FeedbackTty::readLine(STDIN, 2); $took = microtime(true) - $t;'
            . ' $restore(); $after = trim(shell_exec("stty -g")); system("stty sane");'
            . ' fwrite(STDERR, json_encode([$a, round($took, 1), $after === $raw, $canon]));');
        $out = [];
        foreach (['answer' => ['raw', "1\r", "2\r"], 'silence' => ['raw', "1\r", ''], 'half-typed' => ['cooked', '1', "2\r"]] as $case => $c) {
            list($mode, $before, $answer) = $c;
            $p = @proc_open([PHP_BINARY, "$tmp/child.php", $mode], [0 => ['pty'], 1 => ['pty'], 2 => ['pipe', 'w']], $pp);
            if (!is_resource($p)) {
                testRmTree($tmp);
                skip('this PHP cannot open a pty');
            }
            $err = '';
            $t = microtime(true);
            stream_set_blocking($pp[2], false);
            while (strpos($err, 'R') === false && microtime(true) - $t < 5) {
                $err .= (string) stream_get_contents($pp[2]);
                usleep(20000);
            }
            fwrite($pp[0], $before); // typed before the line is shown: must not answer it, not even as half a line
            while (strpos($err, 'P') === false && microtime(true) - $t < 5) {
                $err .= (string) stream_get_contents($pp[2]);
                usleep(20000);
            }
            usleep(200000);
            if ($answer !== '') {
                fwrite($pp[0], $answer);
            }
            while (proc_get_status($p)['running'] && microtime(true) - $t < 10) {
                $err .= (string) stream_get_contents($pp[2]);
                usleep(50000);
            }
            $err .= (string) stream_get_contents($pp[2]);
            if (proc_get_status($p)['running']) {
                proc_terminate($p, 9);
            }
            proc_close($p);
            $out[$case] = json_decode((string) substr($err, (int) strpos($err, 'P') + 1), true);
        }
        testRmTree($tmp);
        check(is_array($out['answer']) && $out['answer'][0] === '2' && $out['answer'][1] < 1.5 && $out['answer'][2] === true, 'raw answer: ' . json_encode($out['answer']));
        check(is_array($out['half-typed']) && $out['half-typed'][0] === '2' && $out['half-typed'][2] === true, 'a half-typed line before the prompt: ' . json_encode($out['half-typed']));
        check($out['answer'][3] === true && $out['half-typed'][3] === true, 'the read must run in canonical mode with echo and signals: ' . json_encode($out));
        check(is_array($out['silence']) && $out['silence'][0] === false && $out['silence'][1] >= 1.9 && $out['silence'][1] < 3 && $out['silence'][2] === true, 'silence: ' . json_encode($out['silence']));
    },
];
