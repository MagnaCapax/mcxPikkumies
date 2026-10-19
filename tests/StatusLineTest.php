<?php
/**
 * The status segment of the `pikkumies` tmux session (ADR-0061): the tmux plan runs `pikkumies status-line
 * --pane=#{pane_id}` before the credits line, `status-line` routes as a subcommand and never as a prompt (a prompt there
 * would start a paid session every minute), bin prints the memories and the pane's counters, and an interactive start
 * zeroes its own pane before Hermes starts.
 */
return [
    'status line: the tmux plan puts the pane segment before the credits line, #{pane_id} left for tmux, paths still doubled, no -g' => function () {
        $cmd = "'php' 'pikkumies'";
        $plan = Launch::tmuxPlan('2', null, false, $cmd, "'php' 'credits.php' line --tmux", "'php' 'pikkumies' status-line");
        check(array_slice($plan, 1, 3) === [
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-right', "#('php' 'pikkumies' status-line --pane=#{pane_id}) #('php' 'credits.php' line --tmux) %H:%M"],
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-right-length', '150'],
            ['tmux', 'set-option', '-t', '=pikkumies:', 'status-interval', '60'],
        ], 'status steps: ' . json_encode($plan));
        foreach ($plan as $step) {
            check(!in_array('-g', $step, true), 'no step may set a global option: ' . json_encode($step));
        }
        $odd = Launch::tmuxPlan('1', ['pm1'], true, $cmd, "'c'", "'/home/a%Hb#c/bin/pikkumies' status-line");
        check($odd[0][5] === "#('/home/a%%Hb##c/bin/pikkumies' status-line --pane=#{pane_id}) #('c') %H:%M", '% and # of the path doubled, the pane format not: ' . $odd[0][5]);
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(strpos($bin, "Launch::tmuxPlan(\$n, \$windows, \$insideTmux, \$self, \$creditsCmd, \$self . ' status-line')") !== false, 'bin must pass its own status-line to the tmux plan');
    },
    'status line: `status-line --pane=%N` is the subcommand; a pane id as its own word would be a prompt, so the plan never writes one' => function () {
        check(Launch::route(['pikkumies', 'status-line', '--pane=%3']) === ['status-line', ['--pane=%3']], 'with a pane');
        check(Launch::route(['pikkumies', 'status-line', '--pane=']) === ['status-line', ['--pane=']] && Launch::route(['pikkumies', 'status-line']) === ['status-line', []], 'an empty pane, or none');
        check(Launch::route(['pikkumies', 'status-line', '%3'])[0] === 'run', 'a bare "%3" is prompt text (ADR-0022): the reason the plan uses --pane=');
    },
    'status line: bin prints the memories and, for a pane that started pikkumies, its counters; any other pane gets the memories only' => function () {
        $tmp = testTempDir('pk-statusline');
        $src = dirname(__DIR__) . '/pikkumies';
        try {
            exec('cp -R ' . escapeshellarg("$src/bin") . ' ' . escapeshellarg("$src/lib") . ' ' . escapeshellarg("$src/config") . ' ' . escapeshellarg($tmp) . '/');
            foreach (['tools/system/credits', 'tools/memory/pikkumies'] as $d) {
                @mkdir("$tmp/$d", 0700, true);
                exec('cp ' . escapeshellarg("$src/$d") . '/*.php ' . escapeshellarg("$tmp/$d") . '/');
            }
            @mkdir("$tmp/memory/lessons/disk", 0700, true);
            file_put_contents("$tmp/memory/lessons/disk/" . gmdate('Ymd') . '-new.md', "# new\n");
            file_put_contents("$tmp/memory/lessons/disk/20200101-old.md", "# old\n");
            @mkdir("$tmp/local", 0700, true);
            SessionCounters::reset("$tmp/local", '%5');
            SessionCounters::update("$tmp/local", '%5', ['tools' => 4, 'added' => 10, 'removed' => 3], ['ctx' => 48211, 'ctxMax' => 1000000]);
            list($code, $out) = runPhp("$tmp/bin/pikkumies", ['status-line', '--pane=%5']);
            check($code === 0 && preg_match('/^Memories: 2 \+1 today (·|\|) Ctx: 48\.2k\/1M 4% \1 Tools: 4 \1 Code: \+10 -3\n$/', $out) === 1, 'the pane\'s line: ' . $code . ' ' . json_encode($out));
            list($code, $out) = runPhp("$tmp/bin/pikkumies", ['status-line', '--pane=%6']);
            check($code === 0 && $out === "Memories: 2 +1 today\n", 'another pane: memories only: ' . json_encode($out));
            check(!is_dir("$tmp/hermes") && !is_file("$tmp/local/config.php"), 'status-line must not install, generate or start anything');
        } finally {
            testRmTree($tmp);
        }
    },
    'status line: an interactive start prints the memories and zeroes its own pane before Hermes starts' => function () {
        $bin = (string) file_get_contents(dirname(__DIR__) . '/pikkumies/bin/pikkumies');
        check(preg_match('/if \(Launch::isTty\(\) && Launch::isSession\(\$passArgs\)\) \{\n\s+require_once [^\n]*CreditsLine\.php\';\n(.*?)\n        \}\n/s', $bin, $m) === 1, 'could not isolate the start banner block');
        check(strpos($m[1], "SessionCounters::reset(Config::localDir(), (string) getenv('TMUX_PANE'));") !== false && strpos($m[1], 'SessionCounters::render(') !== false, 'the start must print the memories and reset its pane: ' . $m[1]);
        check(strpos($bin, $m[0]) < strpos($bin, 'exit(HaltRun::run(hermesBin(), $passArgs));'), 'the reset must come before Hermes starts');
    },
];
