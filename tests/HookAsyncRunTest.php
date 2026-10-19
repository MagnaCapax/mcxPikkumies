<?php
/** lib/HookRun.php (ADR-0067): one stamp, cursor and state shared by the worker and a synchronous caller; the one-shot marker. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'async run: beginRun takes the run once (a second caller gets false), endRun moves the cursor and keeps the state; due() only looks' => function () {
        $tree = asyncTree(['review/check' => ['wake' => 'reply']]);
        try {
            $out = asyncPhp($tree, '$l = Config::localDir(); $u = HookInterval::on([])["review/check"]; $h = sha1("r1");'
                . ' HookSpool::append($l, "r1", [["kind" => "reply", "text" => "x"]], ["review/check" => $u]);'
                . ' $due1 = HookAsync::due($l, $u, $h); $due2 = HookAsync::due($l, $u, $h);'
                . ' $a = HookRun::beginRun($l, $u, $h); $b = HookRun::beginRun($l, $u, $h); $dueAfter = HookAsync::due($l, $u, $h);'
                . ' HookRun::endRun($l, $u, $h, $a, 1, ["shown" => ["x"]]); $c = HookRun::beginRun($l, $u, $h);'
                . ' echo json_encode([$due1, $due2, $a !== false, $b, $dueAfter, HookRun::cursor($l, $u, $h), HookRun::state($l, $u, $h), $c !== false, HookRun::firstRun($l, $u, $h)]);');
            check($out === '[true,true,true,false,false,1,{"shown":["x"]},true,false]', 'run record: ' . $out);
        } finally {
            testRmTree($tree);
        }
    },
    'async run: a one-shot run gives every Hermes process it starts PIKKUMIES_ONESHOT=1' => function () {
        $tree = asyncTree([]);
        try {
            $fake = "$tree/fake-hermes";
            file_put_contents($fake, '#!' . PHP_BINARY . "\n<?php file_put_contents(" . var_export("$tree/env.txt", true) . ', (string) getenv("PIKKUMIES_ONESHOT")); echo "plain answer\n";' . "\n");
            chmod($fake, 0755);
            $out = asyncPhp($tree, 'echo getenv("PIKKUMIES_ONESHOT") === false ? "unset" : "set"; HaltRun::run(' . var_export($fake, true) . ', ["chat", "--query=hi"]);');
            check(strpos($out, 'unset') === 0 && strpos($out, 'plain answer') !== false, 'run: ' . $out);
            check((string) @file_get_contents("$tree/env.txt") === '1', 'Hermes did not get the one-shot marker: ' . @file_get_contents("$tree/env.txt"));
        } finally {
            testRmTree($tree);
        }
    },
];
