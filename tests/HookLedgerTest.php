<?php
/** lib/HookLedger.php (ADR-0071): the daily cap on background checks' model calls, reserved before each review. */
require_once __DIR__ . '/asyncSupport.php';

return [
    'ledger: calls are reserved against the day\'s cap, a whole review at once; a new day starts again; the setting overrides the default' => function () {
        $tree = asyncTree([]);
        try {
            $out = asyncPhp($tree, '$l = Config::localDir(); $r = [];'
                . ' $r[] = HookLedger::spend($l, "u", 24, 10); $r[] = HookLedger::spend($l, "u", 24, 10); $r[] = HookLedger::spend($l, "u", 24, 10);'
                . ' $r[] = HookLedger::spend($l, "u", 24, 4); $r[] = HookLedger::spend($l, "u", 24, 1); $r[] = HookLedger::used($l, "u"); $r[] = HookLedger::spend($l, "other", 24, 1);'
                . ' file_put_contents(HookLedger::file($l), json_encode(["day" => "20000101", "calls" => ["u" => 99]])); $r[] = HookLedger::spend($l, "u", 24, 1);'
                . ' $r[] = HookLedger::cap(["hooks" => ["dailyCalls" => ["u" => 5]]], "u", 24); $r[] = HookLedger::cap([], "u", 24); $r[] = HookLedger::spend($l, "u", 0, 1);'
                . ' echo json_encode($r);');
            check($out === '[true,true,false,true,false,24,true,true,5,24,false]', 'ledger: ' . $out);
            check((fileperms("$tree/pikkumies/local/state/async/ledger.json") & 0777) === 0600, 'ledger mode');
        } finally {
            testRmTree($tree);
        }
    },
    'ledger: parallel callers never spend past the cap' => function () {
        $tree = asyncTree([]);
        try {
            file_put_contents("$tree/spend.php", '<?php foreach (glob(' . var_export("$tree/pikkumies/lib/*.php", true) . ') as $l) { require_once $l; }'
                . ' $n = 0; for ($i = 0; $i < 10; $i++) { $n += HookLedger::spend(Config::localDir(), "u", 24, 1) ? 1 : 0; } echo $n;');
            $procs = [];
            for ($i = 0; $i < 6; $i++) {
                $procs[$i] = proc_open(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("$tree/spend.php"), [1 => ['pipe', 'w']], $pipes[$i]);
            }
            $total = 0;
            foreach ($procs as $i => $p) {
                $total += (int) stream_get_contents($pipes[$i][1]);
                proc_close($p);
            }
            $used = json_decode(file_get_contents("$tree/pikkumies/local/state/async/ledger.json"), true)['calls']['u'];
            check($total === 24 && $used === 24, "spent $total, recorded $used of 24");
        } finally {
            testRmTree($tree);
        }
    },
];
