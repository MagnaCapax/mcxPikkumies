<?php
/**
 * When Pikkumies may ask about feedback (ADR-0056 amendments 2026-10-19 and 2026-10-20, ADR-0097): off by default; when
 * on, a run (PIKKUMIES_RUN, lib/SessionCounters.php) is eligible only after 45 minutes AND 500,000 tokens, in the
 * person's own session (depth 1 or less; --yolo, the default run mode, does not matter), with the shared budget open
 * and the run's one ask unused; the 2026-10-19 `asked` marker still holds for 14 days; the ask is claimed before it is
 * spoken, so a state directory that cannot be written is silence. The mid-session line goes to a fixed fifth of eligible runs, on cli/tui only, and
 * loads no network code. The first start's one question is asked once, Enter is no. The real hook runs in a throwaway
 * tree, so the checkout's local/ is never used.
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

    /** A run in $local that started $minutes ago and has used $tokens. */
    function feedbackAskRunFile($local, $run, $minutes = 50, $tokens = 600000, $now = null)
    {
        $now = $now === null ? time() : $now;
        check(SessionCounters::resetRun($local, $run, $now - $minutes * 60) === true, 'resetRun');
        check(SessionCounters::updateRun($local, $run, ['tokens' => $tokens]) === true, 'updateRun');
    }

    /** Runs the hook once under PIKKUMIES_RUN=$run; returns [context or '', the files it loaded]. */
    function feedbackAskRun($tree, $run, $platform = 'cli', array $env = [])
    {
        $payload = json_encode(['hook_event_name' => 'pre_llm_call', 'session_id' => 's-1',
            'extra' => ['platform' => $platform, 'conversation_history' => [['role' => 'user', 'content' => 'q']], 'user_message' => 'hi']]);
        list(, $out) = testWithEnv($env + ['HERMES_YOLO_MODE' => null, 'PIKKUMIES_DEPTH' => null, 'PIKKUMIES_RUN' => $run], function () use ($tree, $payload) {
            return runPhp("$tree/wrap.php", [], $payload);
        });
        $parts = explode("\nINCLUDED ", $out);
        $d = json_decode(trim($parts[0]), true);
        return [is_array($d) && isset($d['context']) ? $d['context'] : (trim($parts[0]) === '' ? '' : 'UNPARSED: ' . $parts[0]),
            isset($parts[1]) ? (array) json_decode($parts[1], true) : []];
    }

    /** The first run id that is (or is not) in the sampled fifth; a sample that is all or nothing fails here. */
    function feedbackAskSampledRun($sampled, $skip = 0)
    {
        for ($i = 0; $i < 1000; $i++) {
            $run = sprintf('%016x', $i + 0xabc000);
            if (FeedbackAsk::sampled($run) === $sampled && $skip-- <= 0) {
                return $run;
            }
        }
        throw new Exception('no run id among 1000 is ' . ($sampled ? '' : 'not ') . 'sampled: the sample is broken');
    }

    /** $fn in the person's own shell: no depth, no yolo. */
    function feedbackAskOwnShell(callable $fn, array $env = [])
    {
        return testWithEnv($env + ['PIKKUMIES_DEPTH' => null, 'HERMES_YOLO_MODE' => null], $fn);
    }
}

$on = ['feedback' => ['ask' => true]];
return [
    'feedback ask: off by default, so a fresh install never asks; the setting is asked to turn on and free to turn off' => function () {
        check(Config::defaults()['feedback'] === ['ask' => false], 'feedback.ask must default to off: ' . json_encode(Config::defaults()['feedback']));
        check(ConfigureTiers::rule('feedback.ask') === ['bool', 'approve', 'free'], 'tier rule: ' . json_encode(ConfigureTiers::rule('feedback.ask')));
        check(ConfigureTiers::classify('feedback.ask', false, true) === 'approve' && ConfigureTiers::classify('feedback.ask', true, false) === 'free', 'on must be asked, off free');
        check(strpos(ConfigureTiers::why('feedback.ask', true), 'a rating is sent only when you press its number') !== false, 'the asked change must say when a rating is sent');
        $tree = feedbackAskTree([]);
        $run = feedbackAskSampledRun(true);
        feedbackAskRunFile("$tree/pikkumies/local", $run);
        check(feedbackAskRun($tree, $run)[0] === '', 'asked with the setting unset');
        check(!file_exists("$tree/pikkumies/local/state/feedback-ask/next"), 'a budget was claimed while off');
        testRmTree($tree);
    },
    'feedback ask: a run is eligible only after 45 minutes AND 500,000 tokens, and only with a run file' => function () use ($on) {
        $local = testTempDir('pk-fbelig');
        $now = 1792500000;
        $run = 'a1b2c3d4e5f60718';
        feedbackAskOwnShell(function () use ($local, $now, $run, $on) {
            check(!FeedbackAsk::eligible($on, $run, $now, $local), 'eligible without a run file');
            foreach ([[44, 900000, false], [45, 499999, false], [45, 500000, true], [300, 5000000, true], [10, 5000000, false]] as $c) {
                feedbackAskRunFile($local, $run, $c[0], $c[1], $now);
                check(FeedbackAsk::eligible($on, $run, $now, $local) === $c[2], "{$c[0]} min, {$c[1]} tokens: " . json_encode(!$c[2]));
            }
            foreach (['', 'A1B2C3D4E5F60718', 'a1b2c3d4e5f6071', "a1b2c3d4e5f60718\n", '../a1b2c3d4e5f6'] as $bad) {
                check(!FeedbackAsk::eligible($on, $bad, $now, $local) && FeedbackAsk::runId($bad) === '', json_encode($bad) . ' was taken as a run id');
            }
            check(!FeedbackAsk::eligible(['feedback' => ['ask' => false]], $run, $now, $local), 'eligible with the setting off');
        });
        feedbackAskRunFile($local, $run, 50, 600000, $now);
        check(!feedbackAskOwnShell(function () use ($local, $now, $run, $on) {
            return FeedbackAsk::eligible($on, $run, $now, $local);
        }, ['PIKKUMIES_DEPTH' => '2']), 'eligible at depth 2');
        check(feedbackAskOwnShell(function () use ($local, $now, $run, $on) {
            return FeedbackAsk::eligible($on, $run, $now, $local);
        }, ['PIKKUMIES_DEPTH' => '1', 'HERMES_YOLO_MODE' => '1']), 'the person\'s own run under --yolo (the default run mode) must be eligible');
        check(feedbackAskOwnShell(function () use ($local, $now, $run, $on) {
            return FeedbackAsk::eligible($on, $run, $now, $local);
        }, ['PIKKUMIES_DEPTH' => '1']), 'the person\'s own run at depth 1 must be eligible');
        testRmTree($local);
    },
    'feedback ask: the shared budget and the run\'s one ask; the old asked marker still holds for 14 days' => function () use ($on) {
        $local = testTempDir('pk-fbbudget');
        $now = 1792500000;
        $run = 'b1b2c3d4e5f60718';
        $dir = "$local/state/feedback-ask";
        feedbackAskOwnShell(function () use ($local, $now, $run, $on, $dir) {
            feedbackAskRunFile($local, $run, 60, 700000, $now);
            @mkdir($dir, 0700, true);
            touch("$dir/asked", $now - 13 * 86400);
            check(!FeedbackAsk::eligible($on, $run, $now, $local), 'asked within 14 days of the old marker');
            touch("$dir/asked", $now - 15 * 86400);
            check(FeedbackAsk::eligible($on, $run, $now, $local), 'the old marker held past 14 days');
            touch("$dir/next", $now + 60);
            check(!FeedbackAsk::eligible($on, $run, $now, $local), 'asked before the budget\'s next time');
            touch("$dir/next", $now);
            check(FeedbackAsk::claim($now, $run, 14, $local) === true, 'claim');
            clearstatcache();
            check(filemtime("$dir/next") === $now + 14 * 86400 && SessionCounters::readRun($local, $run)['asked'] === 1, 'the claim must set next and the run\'s asked');
            check(FeedbackAsk::claim($now, $run, 14, $local) === false, 'claimed twice');
            unlink("$dir/next");
            check(!FeedbackAsk::eligible($on, $run, $now, $local) && FeedbackAsk::claim($now, $run, 14, $local) === false, 'a run asked once was asked again');
            $other = 'c1b2c3d4e5f60718';
            feedbackAskRunFile($local, $other, 60, 700000, $now);
            check(FeedbackAsk::claim($now, $other, 30, $local) === true && FeedbackAsk::snooze($now, 14, $local), 'another run claims the open budget');
            clearstatcache();
            check(filemtime("$dir/next") === $now + 14 * 86400, 'snooze must move next');
        });
        testRmTree($local);
    },
    'feedback ask: on, a sampled eligible terminal run gets the one sentence once; next is 14 days out' => function () use ($on) {
        $tree = feedbackAskTree($on);
        $local = "$tree/pikkumies/local";
        $run = feedbackAskSampledRun(true);
        feedbackAskRunFile($local, $run);
        list($ctx, $files) = feedbackAskRun($tree, $run);
        check($ctx === FeedbackAsk::SENTENCE, 'context: ' . $ctx);
        check($files && !preg_grep('#/(Http|Provider|Llm\w*|FeedbackSend)\.php$#', $files), 'the hook loaded network code: ' . json_encode($files));
        clearstatcache();
        $next = @filemtime("$local/state/feedback-ask/next");
        check($next !== false && abs($next - (time() + 14 * 86400)) < 60, 'next is not 14 days out: ' . json_encode($next));
        check(SessionCounters::readRun($local, $run)['asked'] === 1, 'the run was not marked asked');
        check(feedbackAskRun($tree, $run)[0] === '', 'asked twice');
        $second = feedbackAskSampledRun(true, 1);
        feedbackAskRunFile($local, $second);
        check(feedbackAskRun($tree, $second)[0] === '', 'another run was asked within the budget');
        testRmTree($tree);
    },
    'feedback ask: never in cron, gateways, delegated agents, agents started by agents, below the thresholds, without a run or outside the sample' => function () use ($on) {
        $tree = feedbackAskTree($on);
        $local = "$tree/pikkumies/local";
        $run = feedbackAskSampledRun(true);
        feedbackAskRunFile($local, $run);
        foreach (['cron', 'telegram', 'subagent', ''] as $p) {
            check(feedbackAskRun($tree, $run, $p)[0] === '', "asked on platform '$p'");
        }
        check(feedbackAskRun($tree, $run, 'cli', ['PIKKUMIES_DEPTH' => '2'])[0] === '', 'asked at depth 2');
        check(feedbackAskRun($tree, null)[0] === '' && feedbackAskRun($tree, 'nothex!')[0] === '', 'asked without a run id');
        $unsampled = feedbackAskSampledRun(false);
        feedbackAskRunFile($local, $unsampled);
        check(feedbackAskRun($tree, $unsampled)[0] === '', 'an unsampled run was asked');
        feedbackAskRunFile($local, $run, 44, 900000);
        check(feedbackAskRun($tree, $run)[0] === '', 'asked at 44 minutes');
        check(!file_exists("$local/state/feedback-ask/next"), 'a budget was claimed without asking');
        feedbackAskRunFile($local, $run);
        check(feedbackAskRun($tree, $run, 'tui', ['PIKKUMIES_DEPTH' => '1', 'HERMES_YOLO_MODE' => '0'])[0] === FeedbackAsk::SENTENCE,
            "the user's own tui run at depth 1, yolo '0', was not asked");
        unlink("$local/state/feedback-ask/next");
        feedbackAskRunFile($local, $run);
        check(feedbackAskRun($tree, $run, 'cli', ['PIKKUMIES_DEPTH' => '1', 'HERMES_YOLO_MODE' => '1'])[0] === FeedbackAsk::SENTENCE,
            "the user's own cli run at depth 1 under --yolo (HERMES_YOLO_MODE=1, the default run mode) was not asked");
        testRmTree($tree);
    },
    'feedback ask: a state directory that cannot be written means silence, not a question on every turn' => function () use ($on) {
        $tree = feedbackAskTree($on);
        $run = feedbackAskSampledRun(true);
        feedbackAskRunFile("$tree/pikkumies/local", $run);
        file_put_contents("$tree/pikkumies/local/state/feedback-ask", 'a file where the directory should be');
        check(feedbackAskRun($tree, $run)[0] === '', 'asked although the ask could not be recorded');
        check(SessionCounters::readRun("$tree/pikkumies/local", $run)['asked'] === 0, 'the run was marked asked although nothing was asked');
        testRmTree($tree);
    },
    'feedback ask: the run sample is a fixed fifth of run ids' => function () {
        $n = 0;
        for ($i = 0; $i < 10000; $i++) {
            $n += FeedbackAsk::sampled(sprintf('%016x', $i * 7919)) ? 1 : 0;
        }
        check($n > 1800 && $n < 2200 && FeedbackAsk::sampled('0123456789abcdef') === FeedbackAsk::sampled('0123456789abcdef'), "sampled $n of 10000");
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
            check(strpos($shown, 'Asking sends nothing; a rating is sent only when you press its number') !== false && strpos($shown, '[y/N]') !== false, 'question: ' . $shown);
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
