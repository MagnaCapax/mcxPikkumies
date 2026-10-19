<?php
/**
 * tools/system/credits `line` (ADR-0040): the credits line for the start banner and the `pikkumies tmux` status line.
 * The arithmetic and the look are tested on CreditsFormat directly; here the real script runs against a local php -S
 * fixture (tests/fixtures/credits/harness.php). Failures and the caches are in CreditsLineCacheTest.
 */
require_once __DIR__ . '/fixtures/credits/harness.php';

return [
    'credits line: prints one line from usage and model rates; --jsonl gives the record; ASCII under a non-UTF-8 locale or --ascii' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, $out, $err) = creditsLineRun($dir, "$base/v", []);
            check($code === 0 && $err === '', "line failed ($code): $out $err");
            check($out === "💳 4.7M credits ▰▰▰▰▰▰▰▰▰▱ 94% left · ≥1.1M tokens · today 35k\n", 'line: ' . $out);
            check(creditsLineRequests($dir) === ['/v/usage?days=400&grants=10', '/v/models'], 'requests: ' . json_encode(creditsLineRequests($dir)));
            list(, $out) = creditsLineRun($dir, "$base/v", ['--jsonl']);
            $r = json_decode(trim($out), true);
            check($r === ['balance' => 4700000, 'pot' => 5000000, 'pct_left' => 94, 'tokens_min' => 1175000, 'model' => 'default', 'today_credits' => 35000, 'approx' => ['pot' => false]], 'record: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/v", [], 'C');
            check($out === "cr 4.7M credits [#########-] 94% left | >=1.1M tokens | today 35k\n", 'LANG=C must give ASCII: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/v", ['--ascii']);
            check($out === "cr 4.7M credits [#########-] 94% left | >=1.1M tokens | today 35k\n", '--ascii: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/v", ['--tmux']);
            check($out === "4.7M credits #[fg=colour114]▰▰▰▰▰▰▰▰▰▱#[default] 94% left · ≥1.1M tokens · today 35k\n", '--tmux: ' . $out);
            testRmTree("$dir/state");
            list(, $out) = creditsLineRun($dir, "$base/v", [], 'C.UTF-8', 'second');
            check(strpos($out, '≥2.3M tokens') !== false, 'the configured model\'s rates must be used: ' . $out);
        });
    },
    'credits line: a balance below zero after a refund shows 0%, an empty bar and red, from a real API answer' => function () {
        creditsLineWithServer(function ($base, $dir) {
            list($code, $out) = creditsLineRun($dir, "$base/refund", []);
            check($code === 0 && $out === "💳 -250k credits ▱▱▱▱▱▱▱▱▱▱ 0% left · ≥0 tokens · today 0\n", 'refund: ' . $out);
            list(, $out) = creditsLineRun($dir, "$base/refund", ['--tmux']);
            check(strpos($out, '#[fg=colour210]▱▱▱▱▱▱▱▱▱▱#[default] 0% left') !== false, 'refund must be red: ' . $out);
        });
    },
    'credits line: a second call inside 60 s makes no request; an older line asks usage again but keeps the day-old rates' => function () {
        creditsLineWithServer(function ($base, $dir) {
            creditsLineRun($dir, "$base/v", []);
            check(count(creditsLineRequests($dir)) === 2, 'first call: ' . json_encode(creditsLineRequests($dir)));
            list($code, $out) = creditsLineRun($dir, "$base/v", []);
            check($code === 0 && strpos($out, '4.7M credits') !== false, 'cached line: ' . $out);
            check(count(creditsLineRequests($dir)) === 2, 'a second call inside 60 s must make no request: ' . json_encode(creditsLineRequests($dir)));
            creditsLineShift($dir, 'line.json', -61);
            creditsLineRun($dir, "$base/v", []);
            check(array_slice(creditsLineRequests($dir), 2) === ['/v/usage?days=400&grants=10'], 'after 60 s only usage is asked again: ' . json_encode(creditsLineRequests($dir)));
            check((fileperms("$dir/state") & 0777) === 0700, 'state dir must be 0700: ' . decoct(fileperms("$dir/state") & 0777));
            foreach (glob("$dir/state/*") as $f) {
                check((fileperms($f) & 0777) === 0600, basename($f) . ' must be 0600');
                check(strpos((string) file_get_contents($f), 'S3CRET') === false, basename($f) . ' holds the key');
            }
        });
    },
    'credits line: the tmux plan sets the status line on the pikkumies session only, never with -g, and bin wires it and the banner' => function () {
        $cmd = "'php' 'pikkumies'";
        $status = "'php' 'credits.php' line --tmux";
        $plan = Launch::tmuxPlan('2', null, false, $cmd, $status);
        check(count($plan) === 8 && $plan[0][1] === 'new-session' && $plan[6] === ['tmux', 'select-window', '-t', '=pikkumies:pm2'], 'plan: ' . json_encode($plan)); // + status-position, status-style (ADR-0061)
        check(array_slice($plan, 1, 3) === [
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-right', "#($status) %H:%M"],
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-right-length', '90'],
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-interval', '60'],
        ], 'status steps: ' . json_encode($plan));
        foreach ($plan as $step) {
            check(!in_array('-g', $step, true), 'no step may set a global option: ' . json_encode($step));
        }
        check(count(Launch::tmuxPlan('1', ['pm1'], true, $cmd, $status)) === 7, 'an existing session gets the status steps too');
        check(count(Launch::tmuxPlan('1', ['pm1'], true, $cmd)) === 2, 'without a status command the plan is unchanged');
        // tmux expands % (strftime) and # (formats) inside #() as well: a checkout path holding them must still run.
        $odd = Launch::tmuxPlan('1', ['pm1'], true, $cmd, "'/home/a%Hb#c/credits.php' line --tmux");
        check($odd[0][5] === "#('/home/a%%Hb##c/credits.php' line --tmux) %H:%M", '% and # must be doubled: ' . $odd[0][5]);
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, "/tools/system/credits/credits.php') . ' line --tmux'") !== false, 'bin must pass `credits.php line --tmux` to the tmux plan');
        check(preg_match("/if \\(Launch::isTty\\(\\) && Launch::isSession\\(\\\$passArgs\\)\\) \\{\n\s+require_once [^\n]*CreditsLine\\.php';\n(.*?)passthru\\(escapeshellarg\\(hermesBin/s", $bin, $m) === 1, 'the banner must be printed on a terminal session, before Hermes starts');
        check(strpos($m[1], 'CreditsLine::text(') !== false && strpos($m[1], 'exec(') === false, 'the banner is a library call, not a subprocess');
    },
];
