<?php
/**
 * find.php end to end (tools/torrent-clients/find/): fake local units through FindLocal, fake remote
 * callables through FindRemote, driven the same way tests/fixtures/qbittorrent/harness.php drives
 * qbittorrent.php -- a generated wrapper script that sets the test seams, run as its own PHP process.
 */
require_once __DIR__ . '/fixtures/find/helpers.php';

return [
    'find: a unique info-hash hit is the only record, with unique:true and no next step' => function () {
        $hash = str_repeat('a', 40);
        $root = findFixtureRoot(['alpha' => '<?php echo json_encode(["id"=>"' . $hash . '","name"=>"Alpha","size"=>1,"progress"=>1,"state"=>"seeding","path"=>"/x","label"=>null]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";']);
        try {
            list($code, $l, $out) = findRun($root, ['find', $hash]);
            check($code === 0 && count($l) === 2, "exit/shape: $out");
            check($l[0]['service'] === 'local' && $l[0]['client'] === 'alpha' && $l[0]['match'] === 'infohash' && (float) $l[0]['score'] === 1.0, "record: $out");
            check($l[1]['unique'] === true && $l[1]['ambiguous_reason'] === null && $l[1]['next'] === null, "status: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: an absent client ("not installed") never appears as a candidate or an issue' => function () {
        $root = findFixtureRoot(['gone' => '<?php echo json_encode(["ok"=>false,"error"=>"gone is not installed (test)","hint"=>"n/a"]),"\n";']);
        try {
            list($code, $l, $out) = findRun($root, ['find', 'anything']);
            check($code === 0 && count($l) === 1 && !isset($l[0]['issues']) && $l[0]['count'] === 0, "an absent client must leave no trace at all: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: a client that answers but cannot be used shows up in issues, not as a candidate' => function () {
        $root = findFixtureRoot(['stuck' => '<?php echo json_encode(["ok"=>false,"error"=>"stuck is not running (test)","hint"=>"n/a"]),"\n";']);
        try {
            list(, $l, $out) = findRun($root, ['find', 'anything']);
            check($l[0]['issues'] === ['local/stuck' => 'stuck is not running (test)'], "issues: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: the status line always names name/path/label/message as untrusted, and a candidate carries message through' => function () {
        $hash = str_repeat('f', 40);
        $root = findFixtureRoot(['alpha' => '<?php echo json_encode(["id"=>"' . $hash . '","name"=>"Alpha","size"=>1,"progress"=>1,"state"=>"seeding","path"=>"/x","label"=>"L","message"=>"tracker says hi"]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";']);
        try {
            list(, $l, $out) = findRun($root, ['find', $hash]);
            check($l[0]['message'] === 'tracker says hi', "message must pass through to the candidate: $out");
            check($l[1]['untrusted'] === ['name', 'path', 'label', 'message'], "untrusted field list: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: (security review F7) more than 10 broken clients are capped in issues, each message cut to 400 bytes' => function () {
        $units = [];
        for ($i = 0; $i < 12; $i++) {
            $name = "broken$i";
            $msg = $i === 0 ? str_repeat('X', 2000) : "broken$i is not running (test)"; // one deliberately oversized message
            $units[$name] = '<?php echo json_encode(["ok"=>false,"error"=>' . var_export($msg, true) . ',"hint"=>"n/a"]),"\n";';
        }
        $root = findFixtureRoot($units);
        try {
            list(, $l, $out) = findRun($root, ['find', 'anything']);
            check(count($l[0]['issues']) === 11, "10 kept + 1 summary note, got " . count($l[0]['issues']) . ": $out"); // 12 issues, cap 10, +1 "N more" note
            check(isset($l[0]['issues']['...']) && strpos($l[0]['issues']['...'], '2 more') !== false, "omitted-count note: $out");
            foreach ($l[0]['issues'] as $k => $v) {
                check(strlen($v) <= 400, "issue '$k' exceeds the 400-byte cap (" . strlen($v) . " bytes): $out");
            }
            check(strlen($out) < 16384, "the whole status line must stay well inside the 16 KB output budget: " . strlen($out) . " bytes");
        } finally {
            testRmTree($root);
        }
    },
    'find: (security review F7) more than 20 clients past their timeout are capped in busy, with a summary note' => function () {
        $units = [];
        for ($i = 0; $i < 22; $i++) {
            $units["slow$i"] = '<?php sleep(5); echo json_encode(["ok"=>true,"count"=>0]),"\n";';
        }
        $root = findFixtureRoot($units);
        try {
            $t0 = microtime(true);
            list(, $l, $out) = findRun($root, ['find', 'anything'], '', 1);
            check(microtime(true) - $t0 < 10, "22 concurrent slow clients must not make find.php wait anywhere near 22x the per-client time: $out");
            check(count($l[0]['busy']) === 21, "20 kept + 1 summary note, got " . count($l[0]['busy']) . ": $out");
            check(strpos(end($l[0]['busy']), '2 more') !== false, "omitted-count note: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: a client past the timeout is reported busy, and the call still answers quickly' => function () {
        $root = findFixtureRoot(['slow' => '<?php sleep(10); echo json_encode(["ok"=>true,"count"=>0]),"\n";']);
        try {
            $t0 = microtime(true);
            list(, $l, $out) = findRun($root, ['find', 'anything'], '', 1);
            check(microtime(true) - $t0 < 6, "find.php must not wait out the slow client's full sleep: $out");
            check($l[0]['busy'] === ['slow'], "busy: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: several weak matches are not unique, and next tells the agent to compare before asking' => function () {
        $root = findFixtureRoot(['a' => '<?php echo json_encode(["id"=>str_repeat("1",40),"name"=>"Debian 13 netinst","size"=>1,"progress"=>1,"state"=>"seeding","path"=>"/a","label"=>null]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";',
            'b' => '<?php echo json_encode(["id"=>str_repeat("2",40),"name"=>"Debian 12 netinst","size"=>2,"progress"=>1,"state"=>"seeding","path"=>"/b","label"=>null]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";']);
        try {
            list($code, $l, $out) = findRun($root, ['find', 'debian']);
            check($code === 0 && count($l) === 3, "two candidates + status: $out");
            check($l[2]['unique'] === false && strpos($l[2]['next'], 'compare') === 0, "status: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: --service and --all together are refused before anything runs' => function () {
        $root = findFixtureRoot([]);
        try {
            list($code, $l, $out) = findRun($root, ['find', 'x', '--service', 'hdd1', '--all']);
            check($code === 1 && $l[0]['ok'] === false && strpos($l[0]['error'], 'cannot both') !== false, "mutual exclusivity: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: --all is refused with a clear reason when the Services list is not available' => function () {
        $root = findFixtureRoot([]);
        try {
            list($code, $l, $out) = findRun($root, ['find', 'x', '--all']);
            check($code === 1 && strpos($l[0]['error'], 'Services') !== false, "no Services: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: --service reaches a remote candidate through the injected fanout/get/jsonl, merged with local' => function () {
        $hash = str_repeat('9', 40);
        $root = findFixtureRoot(['alpha' => '<?php echo json_encode(["id"=>"' . $hash . '","name"=>"Something Else","size"=>1,"progress"=>1,"state"=>"seeding","path"=>"/x","label"=>null]),"\n"; echo json_encode(["ok"=>true,"count"=>1]),"\n";']);
        $extra = 'function fGet($n) { return ["nick"=>$n,"clients"=>["rtorrent"=>[]]]; }
function fJsonl($n, array $argv) { return [["id"=>' . var_export($hash, true) . ',"name"=>"Something Else","size"=>1,"progress"=>1,"state"=>"seeding","path"=>"/r","label"=>null],["ok"=>true,"count"=>1]]; }
function fFanout(array $nicks, callable $fn, $p) { $o = []; foreach ($nicks as $n) { $o[$n] = $fn($n); } return $o; }
$findGet = "fGet"; $findJsonl = "fJsonl"; $findFanout = "fFanout";';
        try {
            list($code, $l, $out) = findRun($root, ['find', $hash, '--service', 'hdd1'], $extra);
            check($code === 0 && count($l) === 3, "one local + one remote candidate + status: $out");
            $services = array_column(array_slice($l, 0, 2), 'service');
            check(in_array('local', $services, true) && in_array('hdd1', $services, true), "both services must be present: $out");
            check($l[2]['unique'] === false && strpos($l[2]['ambiguous_reason'], '2 places') !== false, "the same hash in two places is ambiguous, not unique: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: --service with no nick is refused, not silently treated as --all' => function () {
        $root = findFixtureRoot([]);
        try {
            list($code, $l, $out) = findRun($root, ['find', 'x', '--service']);
            check($code === 1 && strpos($l[0]['error'], '--service needs') === 0, "bare --service: $out");
        } finally {
            testRmTree($root);
        }
    },
    'find: a missing or blank query is refused before any client is contacted' => function () {
        $root = findFixtureRoot(['alpha' => '<?php check_never_runs();']);
        try {
            list($code, $l, $out) = findRun($root, ['find']);
            check($code === 1 && $l[0]['ok'] === false, "no query must be refused as this tool's own error: $out");
            list($code2) = findRun($root, ['find', '   ']);
            check($code2 === 1, "a blank query must be refused: $out");
        } finally {
            testRmTree($root);
        }
    },
];
