<?php
/**
 * The opt-in occasional feedback question (ADR-0056, amendment 2026-10-19): off by default; when on, asked only in a
 * person's own terminal session (cli/tui, depth 1 or less, no HERMES_YOLO_MODE) after 4 user messages, for a fixed
 * fifth of sessions and at most once a week; the ask is recorded before it is spoken, so a state directory that cannot
 * be written is silence; the hook loads no network code. The first start's one question is asked once, Enter is no.
 * The real hook runs in a throwaway tree, so the checkout's local/ is never used.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/system/feedback/FeedbackAsk.php';

if (!function_exists('feedbackAskTree')) {
    /** A tree with lib, the feedback unit and the hook; local/config.php holds $config. */
    function feedbackAskTree(array $config = [])
    {
        $tmp = testTempDir('pk-fbask');
        $src = dirname(__DIR__) . '/pikkumies';
        foreach (['lib', 'tools/system/feedback', 'hooks/system/feedback-ask'] as $d) {
            @mkdir("$tmp/pikkumies/$d", 0700, true);
            foreach ((array) glob("$src/$d/*.*") as $f) {
                copy($f, "$tmp/pikkumies/$d/" . basename($f));
            }
        }
        @mkdir("$tmp/pikkumies/local", 0700, true);
        file_put_contents("$tmp/pikkumies/local/config.php", '<?php return ' . var_export($config, true) . ";\n");
        // Prints the files the hook loaded after its answer, so a test can see that no network code came in.
        file_put_contents("$tmp/wrap.php", '<?php register_shutdown_function(function () { echo "\nINCLUDED " . json_encode(get_included_files()); });'
            . ' require ' . var_export("$tmp/pikkumies/hooks/system/feedback-ask/feedback-ask.php", true) . ';');
        return $tmp;
    }

    /** Runs the hook once; returns [context or '', the files it loaded]. */
    function feedbackAskRun($tree, $session, $platform = 'cli', $users = 4, array $env = [])
    {
        $history = [['role' => 'system', 'content' => 's']];
        for ($i = 0; $i < $users; $i++) {
            $history[] = ['role' => 'user', 'content' => "q$i"];
            $history[] = ['role' => 'assistant', 'content' => "a$i"];
        }
        $payload = json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => $session,
            'extra' => ['platform' => $platform, 'conversation_history' => $history, 'user_message' => 'hi']]);
        list(, $out) = testWithEnv($env + ['HERMES_YOLO_MODE' => null, 'PIKKUMIES_DEPTH' => null], function () use ($tree, $payload) {
            return runPhp("$tree/wrap.php", [], $payload);
        });
        $parts = explode("\nINCLUDED ", $out);
        $d = json_decode(trim($parts[0]), true);
        return [is_array($d) && isset($d['context']) ? $d['context'] : (trim($parts[0]) === '' ? '' : 'UNPARSED: ' . $parts[0]),
            isset($parts[1]) ? (array) json_decode($parts[1], true) : []];
    }

    /** The first session id s-N that is (or is not) in the sampled fifth; a sample that is all or nothing fails here. */
    function feedbackAskSession($sampled)
    {
        for ($i = 0; $i < 1000; $i++) {
            if (FeedbackAsk::sampled("s-$i") === $sampled) {
                return "s-$i";
            }
        }
        throw new Exception('no session id among 1000 is ' . ($sampled ? '' : 'not ') . 'sampled: the sample is broken');
    }
}

$on = ['feedback' => ['ask' => true]];
return [
    'feedback ask: off by default, so a fresh install never asks; the setting is asked to turn on and free to turn off' => function () {
        check(Config::defaults()['feedback'] === ['ask' => false], 'feedback.ask must default to off: ' . json_encode(Config::defaults()['feedback']));
        check(ConfigureTiers::rule('feedback.ask') === ['bool', 'approve', 'free'], 'tier rule: ' . json_encode(ConfigureTiers::rule('feedback.ask')));
        check(ConfigureTiers::classify('feedback.ask', false, true) === 'approve' && ConfigureTiers::classify('feedback.ask', true, false) === 'free', 'on must be asked, off free');
        check(strpos(ConfigureTiers::why('feedback.ask', true), 'the question itself sends nothing') !== false, 'the asked change must say the question sends nothing');
        $tree = feedbackAskTree([]);
        check(feedbackAskRun($tree, feedbackAskSession(true))[0] === '', 'asked with the setting unset');
        check(!file_exists("$tree/pikkumies/local/state/feedback-ask/asked"), 'a marker was written while off');
        testRmTree($tree);
    },
    'feedback ask: on, a sampled terminal session after 4 user messages gets the one sentence, then nothing for 7 days' => function () use ($on) {
        $tree = feedbackAskTree($on);
        list($ctx, $files) = feedbackAskRun($tree, feedbackAskSession(true));
        check($ctx === FeedbackAsk::SENTENCE, 'context: ' . $ctx);
        check($files && !preg_grep('#/(Http|Provider|Llm\w*)\.php$#', $files), 'the hook loaded network code: ' . json_encode($files));
        check(is_file("$tree/pikkumies/local/state/feedback-ask/asked"), 'the ask was not recorded');
        check(feedbackAskRun($tree, feedbackAskSession(true))[0] === '', 'asked twice in the same week');
        touch("$tree/pikkumies/local/state/feedback-ask/asked", time() - 6 * 86400);
        check(feedbackAskRun($tree, feedbackAskSession(true))[0] === '', 'asked again after 6 days');
        touch("$tree/pikkumies/local/state/feedback-ask/asked", time() - 8 * 86400);
        check(feedbackAskRun($tree, feedbackAskSession(true))[0] === FeedbackAsk::SENTENCE, 'not asked again after 8 days');
        testRmTree($tree);
    },
    'feedback ask: never in cron, gateways, delegated agents, agents started by agents, under yolo, before 4 messages or outside the sample' => function () use ($on) {
        $tree = feedbackAskTree($on);
        $sid = feedbackAskSession(true);
        foreach (['cron', 'telegram', 'subagent', ''] as $p) {
            check(feedbackAskRun($tree, $sid, $p)[0] === '', "asked on platform '$p'");
        }
        check(feedbackAskRun($tree, $sid, 'cli', 4, ['PIKKUMIES_DEPTH' => '2'])[0] === '', 'asked at depth 2');
        foreach (['1', 'true', 'YES', ' on '] as $yolo) {
            check(feedbackAskRun($tree, $sid, 'cli', 4, ['HERMES_YOLO_MODE' => $yolo])[0] === '', "asked with HERMES_YOLO_MODE=$yolo");
        }
        check(feedbackAskRun($tree, $sid, 'cli', 3)[0] === '', 'asked after 3 user messages');
        check(feedbackAskRun($tree, feedbackAskSession(false))[0] === '', 'an unsampled session was asked');
        check(feedbackAskRun($tree, 'bad id!')[0] === '', 'a session id that is not 1-128 safe characters was asked');
        check(!file_exists("$tree/pikkumies/local/state/feedback-ask/asked"), 'a marker was written without asking');
        check(feedbackAskRun($tree, $sid, 'tui', 4, ['PIKKUMIES_DEPTH' => '1', 'HERMES_YOLO_MODE' => '0'])[0] === FeedbackAsk::SENTENCE,
            "the user's own tui session at depth 1, yolo '0', was not asked");
        testRmTree($tree);
    },
    'feedback ask: a state directory that cannot be written means silence, not a question on every turn' => function () use ($on) {
        $tree = feedbackAskTree($on);
        @mkdir("$tree/pikkumies/local/state", 0700, true);
        file_put_contents("$tree/pikkumies/local/state/feedback-ask", 'a file where the directory should be');
        check(feedbackAskRun($tree, feedbackAskSession(true))[0] === '', 'asked although the ask could not be recorded');
        testRmTree($tree);
    },
    'feedback ask: the session sample is a fixed fifth of session ids' => function () {
        $n = 0;
        for ($i = 0; $i < 10000; $i++) {
            $n += FeedbackAsk::sampled("session-$i") ? 1 : 0;
        }
        check($n > 1800 && $n < 2200 && FeedbackAsk::sampled('x') === FeedbackAsk::sampled('x'), "sampled $n of 10000");
    },
    'feedback ask: the first start asks once ever; only y or yes turns it on; Enter, other answers and end of input are no' => function () {
        $dir = testTempDir('pk-fboffer');
        $ask = function ($answer, array $config = []) use (&$dir) {
            $shown = '';
            $r = FeedbackAsk::offerOnce($config, function () use ($answer) {
                return $answer;
            }, function ($t) use (&$shown) {
                $shown .= $t;
            }, "$dir/s");
            return [$r, $shown];
        };
        foreach (['y' => true, ' YES ' => true, '' => false, 'yes please' => false, 'n' => false] as $answer => $want) {
            testRmTree($dir);
            $dir = testTempDir('pk-fboffer');
            list($r, $shown) = $ask($answer);
            check($r === $want, json_encode($answer) . ' -> ' . json_encode($r));
            check(strpos($shown, 'The question itself sends nothing') !== false && strpos($shown, '[y/N]') !== false, 'question: ' . $shown);
        }
        list($r, $shown) = $ask('y');
        check($r === null && $shown === '', 'asked a second time: ' . json_encode([$r, $shown]));
        testRmTree($dir);
        $dir = testTempDir('pk-fboffer');
        check($ask(false)[0] === false, 'end of input was not a no');
        testRmTree($dir);
        $dir = testTempDir('pk-fboffer');
        list($r, $shown) = $ask('y', ['feedback' => ['ask' => true]]);
        check($r === null && $shown === '' && is_file("$dir/s/offered"), 'asked although already on, or not marked');
        file_put_contents("$dir/t", 'x');
        check(FeedbackAsk::offerOnce([], function () {
            return 'y';
        }, function () {
        }, "$dir/t") === null, 'asked without a state directory to record it');
        testRmTree($dir);
    },
    'feedback ask: the launcher asks after the terms, on a terminal only, and saves only a yes' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        $tty = strpos($bin, 'if (Launch::isTty() && Launch::isSession($passArgs)) {');
        $terms = strpos($bin, 'Config::acceptTerms();', $tty);
        $offer = strpos($bin, "if (FeedbackAsk::offerOnce(Config::load(), 'promptRead', 'promptWrite') === true) {\n                    Config::set('feedback.ask', true);", $tty);
        $end = strpos($bin, '} catch (Exception $x) {', $tty);
        check($tty !== false && $terms !== false && $offer !== false && $terms < $offer && $offer < $end, 'the first-start question is not inside the terminal-only block after the terms');
    },
];
