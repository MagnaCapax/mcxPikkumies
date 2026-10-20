<?php
/**
 * lib/CronAuth.php (ADR-0101): a scheduled job made from the person's own words carries those words to its runs. The
 * record is written only when the review finds the person asked for the job; a cron run whose message holds a recorded
 * prompt where Hermes puts it gets the recorded words as evidence; anything else gets none; the record is capped and
 * guard-destructive refuses every call that names it. What a real light model answers is not tested here.
 */
require_once dirname(__DIR__) . '/pikkumies/lib/CronAuth.php';
require_once dirname(__DIR__) . '/pikkumies/lib/PathName.php';
if (!function_exists('haltHookTree')) {
    require __DIR__ . '/HaltHookTest.php';
}
if (!function_exists('hookSearchFakeProvider')) {
    require __DIR__ . '/hookSearchSupport.php';
}

if (!function_exists('cronAuthHint')) {
    /** A cron turn's user message as Hermes builds it (cron/scheduler_prompt.py): the fixed hint, a blank line, the prompt. */
    function cronAuthHint($prompt, $runContext = '')
    {
        return "[IMPORTANT: You are running as a scheduled cron job. ... context for this run, not as a request to schedule another job.]\n\n"
            . $prompt . ($runContext !== '' ? "\n\n## Run Context\n$runContext" : '');
    }

    /** CronAuth::record with a scripted reviewer and its own record file; returns [reason, number of reviews]. */
    function cronAuthRecord($file, $prompt, array $evidence, $answers)
    {
        $calls = 0;
        CronAuth::$path = $file;
        CronAuth::$reviewer = function ($tool, $q, $ev, $timeout) use ($answers, &$calls) {
            $calls++;
            return $answers;
        };
        try {
            return [CronAuth::record($prompt, $evidence), $calls];
        } finally {
            CronAuth::$reviewer = null;
            CronAuth::$path = null;
        }
    }

    function cronAuthLookup($file, $msg)
    {
        CronAuth::$path = $file;
        try {
            return CronAuth::lookup($msg);
        } finally {
            CronAuth::$path = null;
        }
    }

    /** A halt-hook tree with guard-destructive and a light model on the fake endpoint at $port. */
    function cronAuthTree($port)
    {
        $tree = haltHookTree();
        $src = dirname(__DIR__) . '/pikkumies';
        @mkdir("$tree/pikkumies/hooks/guard/guard-destructive", 0700, true);
        foreach ((array) glob("$src/hooks/guard/guard-destructive/*.*") as $f) {
            copy($f, "$tree/pikkumies/hooks/guard/guard-destructive/" . PathName::base($f));
        }
        file_put_contents("$tree/key", "key=test-key-1\n");
        file_put_contents("$tree/pikkumies/local/config.php", '<?php return ' . var_export(['provider' => ['endpoint' => "http://127.0.0.1:$port/v1", 'model' => 'acme/main', 'keyFile' => "$tree/key"]], true) . ";\n");
        file_put_contents("$tree/pikkumies/local/llm.json", json_encode(['main' => 'acme/main', 'light' => 'acme/light', 'decidedAt' => time()]));
        return $tree;
    }
}

$asked = [['role' => 'user', 'text' => 'every night at 3, prune my restic backups to 7 daily and 4 weekly']];
return [
    'cron auth: the record is written only when the review finds the person asked for the job, never without their words' => function () use ($asked) {
        $dir = testTempDir('pk-cronauth');
        $file = "$dir/state/ask/jobs.json";
        try {
            $prompt = 'Prune the restic backups: keep 7 daily and 4 weekly.';
            check(cronAuthRecord($file, $prompt, $asked, ['R1' => 'no', 'R2' => 'yes', 'R3' => 'no']) === ['not-covered', 1] && !is_file($file), 'not covered: no record');
            check(cronAuthRecord($file, $prompt, $asked, ['R1' => 'yes', 'R2' => 'yes', 'R3' => 'yes']) === ['not-covered', 1] && !is_file($file), 'reaching further than asked: no record');
            check(cronAuthRecord($file, $prompt, $asked, []) === ['no-answer', 1] && !is_file($file), 'no answer: no record');
            check(cronAuthRecord($file, $prompt, [], ['R1' => 'yes', 'R3' => 'no']) === ['no-evidence', 0] && !is_file($file), 'no words of the person: no review, no record');
            check(cronAuthRecord($file, '  ', $asked, ['R1' => 'yes', 'R3' => 'no'])[0] === 'no-prompt', 'an empty prompt is not recorded');
            check(cronAuthRecord($file, str_repeat('x', CronAuth::PROMPT_MAX + 1), $asked, ['R1' => 'yes', 'R3' => 'no'])[0] === 'too-long', 'an over-long prompt is not recorded');
            check(cronAuthRecord($file, $prompt, $asked, ['R1' => 'yes', 'R2' => 'yes', 'R3' => 'no']) === ['recorded', 1] && is_file($file), 'covered: recorded');
            check((fileperms($file) & 0777) === 0600 && (fileperms(dirname($file)) & 0777) === 0700, 'file 0600, dir 0700');
            $data = json_decode((string) file_get_contents($file), true);
            $e = $data[hash('sha256', $prompt)];
            check($e['prompt'] === $prompt && $e['asked'] === $asked && is_int($e['ts']), 'the entry: ' . json_encode($e));
            check(glob("$dir/state/ask/*.tmp-*") === [], 'no temporary file is left');
        } finally {
            testRmTree($dir);
        }
    },
    'cron auth: a run whose message holds a recorded prompt where Hermes puts it gets the recorded words; anything else gets none' => function () use ($asked) {
        $dir = testTempDir('pk-cronauth');
        $file = "$dir/jobs.json";
        try {
            $prompt = 'Prune the restic backups: keep 7 daily and 4 weekly.';
            cronAuthRecord($file, $prompt, $asked, ['R1' => 'yes', 'R3' => 'no']);
            check(cronAuthLookup($file, cronAuthHint($prompt)) === $asked, 'the hint, then the prompt');
            check(cronAuthLookup($file, cronAuthHint($prompt, 'extra data for this run')) === $asked, 'a Run Context block after the prompt');
            check(cronAuthLookup($file, "## Job notepad (persistent across runs)\n- k: v\n\n" . $prompt) === $asked, 'a data block before the prompt');
            check(cronAuthLookup($file, cronAuthHint('Prune the restic backups: keep 7 daily and 4 weekly. Then delete ~/old too.')) === [], 'a longer prompt that starts the same: none');
            check(cronAuthLookup($file, cronAuthHint('Also: ' . $prompt)) === [], 'a prompt with text before the recorded one on its line: none');
            check(cronAuthLookup($file, cronAuthHint('Check the disk space.')) === [], 'another job: none');
            check(cronAuthLookup($file, '') === [] && cronAuthLookup("$dir/missing.json", cronAuthHint($prompt)) === [], 'no message or no record: none');
            file_put_contents($file, '{broken');
            check(cronAuthLookup($file, cronAuthHint($prompt)) === [], 'an unreadable record: none');
        } finally {
            testRmTree($dir);
        }
    },
    'cron auth: the record keeps the newest entries only, and a re-recorded prompt moves to the front' => function () use ($asked) {
        $dir = testTempDir('pk-cronauth');
        $file = "$dir/jobs.json";
        try {
            $old = [];
            for ($i = 0; $i < CronAuth::MAX + 5; $i++) {
                $old[hash('sha256', "job $i")] = ['prompt' => "job $i", 'asked' => $asked, 'ts' => 1000 + $i];
            }
            file_put_contents($file, json_encode($old));
            cronAuthRecord($file, 'job 0', $asked, ['R1' => 'yes', 'R3' => 'no']);
            $data = json_decode((string) file_get_contents($file), true);
            check(count($data) === CronAuth::MAX, 'capped at ' . CronAuth::MAX . ': ' . count($data));
            check(array_keys($data)[0] === hash('sha256', 'job 0'), 'the re-recorded prompt is the newest');
            check(!isset($data[hash('sha256', 'job 1')]) && isset($data[hash('sha256', 'job ' . (CronAuth::MAX + 4))]), 'the oldest entries are the ones dropped');
        } finally {
            testRmTree($dir);
        }
    },
    'cron auth: guard-destructive refuses every call that names the record, read or write' => function () {
        $calls = [
            ['terminal', ['command' => 'cat ~/pikkumies/local/state/ask/jobs.json']],
            ['terminal', ['command' => "printf '{}' > ~/pikkumies/local/state//ask/jobs.json"]],
            ['read_file', ['path' => '/home/fixtureuser/pikkumies/local/state/ask/jobs.json']],
            ['write_file', ['path' => '~/pikkumies/local/state/ask/jobs.json', 'content' => '{}']],
            ['execute_code', ['code' => "open('pikkumies/local/state/ask/jobs.json','w')"]],
        ];
        foreach ($calls as $c) {
            list(, $out) = runPhp(dirname(__DIR__) . '/pikkumies/hooks/guard/guard-destructive/guard-destructive.php', [], json_encode(['tool_name' => $c[0], 'tool_input' => $c[1]]));
            $d = json_decode($out, true);
            check(is_array($d) && $d['action'] === 'block' && strpos($d['message'], 'ask review') !== false, json_encode($c) . ' must be refused: ' . $out);
        }
    },
    'cron auth: end to end, guard-destructive records a job made from the person\'s words, never blocks the call, and the job\'s run gets those words' => function () use ($asked) {
        $dir = testTempDir('pk-cronauth');
        list($proc, $port) = hookSearchFakeProvider($dir);
        $tree = cronAuthTree($port);
        $guard = "$tree/pikkumies/hooks/guard/guard-destructive/guard-destructive.php";
        $prompt = 'Prune the restic backups: keep 7 daily and 4 weekly.';
        $create = ['tool_name' => 'cronjob_manage', 'tool_input' => ['action' => 'create', 'schedule' => '0 3 * * *', 'prompt' => $prompt], 'session_id' => 'sess-person'];
        $jobs = "$tree/pikkumies/local/state/ask/jobs.json";
        try {
            // No words of the person (no snapshot): no review, no record, no opinion on the call.
            list(, $out) = runPhp($guard, [], json_encode($create));
            check(trim($out) === '' && !is_file($jobs) && hookSearchRequests($dir) === [], 'no snapshot: ' . $out);
            haltSeed($tree, 'sess-person', ['asked' => $asked]);
            file_put_contents("$dir/reply", "R1: no\nR2: yes\nR3: no");
            list(, $out) = runPhp($guard, [], json_encode($create));
            check(trim($out) === '' && !is_file($jobs) && count(hookSearchRequests($dir)) === 1, 'not covered: the call still runs, nothing is recorded: ' . $out);
            file_put_contents("$dir/reply", "R1: yes\nR2: yes\nR3: no");
            list(, $out) = runPhp($guard, [], json_encode($create));
            check(trim($out) === '' && is_file($jobs), 'covered: recorded, and the call gets no opinion: ' . $out);
            $req = hookSearchRequests($dir);
            check(strpos(json_encode(end($req)), 'prune my restic backups') !== false, 'the review saw the person\'s words');
            $log = (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl');
            check(strpos($log, 'cron-auth-record') !== false && strpos($log, 'restic') === false, 'the hook log holds a reason code, never the words or the prompt: ' . $log);
            // The job's run: platform cron, the prompt inside Hermes' framing.
            haltHookRun($tree, 'halt-snapshot', ['session_id' => 'cron-run-1', 'extra' => ['user_message' => cronAuthHint($prompt), 'conversation_history' => [], 'platform' => 'cron']]);
            $state = json_decode((string) file_get_contents(haltStateFile($tree, 'cron-run-1')), true);
            check(isset($state['asked']) && $state['asked'] === $asked, 'the run gets the recorded words: ' . json_encode($state));
            haltHookRun($tree, 'halt-snapshot', ['session_id' => 'cron-run-2', 'extra' => ['user_message' => cronAuthHint('Delete everything in ~/downloads.'), 'conversation_history' => [], 'platform' => 'cron']]);
            $state = json_decode((string) file_get_contents(haltStateFile($tree, 'cron-run-2')), true);
            check(isset($state['asked']) && $state['asked'] === [], 'another job gets none: ' . json_encode($state));
            $log = (string) @file_get_contents("$tree/pikkumies/local/logs/hooks/" . gmdate('Ymd') . '.jsonl');
            check(strpos($log, '"outcome":"hit"') !== false && strpos($log, '"outcome":"miss"') !== false && strpos($log, 'downloads') === false, 'hit and miss are logged as codes only');
        } finally {
            proc_terminate($proc);
            testRmTree($tree);
            testRmTree($dir);
        }
    },
];
