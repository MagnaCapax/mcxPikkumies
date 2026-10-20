<?php
/** torrent-clients/move: plan, run (copy, add, recheck, resume) and resume after a stop, against tests/moveSupport.php's fake boxes. */
require_once __DIR__ . '/moveSupport.php';

return [
    'move: plan and run copy a batch from rTorrent there to qBittorrent here, seeding on both, the source untouched' => function () {
        $w = mvWorld('pk-move-happy');
        try {
            $a = mvTorrent($w, 'Debian Pack', ['Debian Pack/netinst.iso' => str_repeat('d', 5000), 'Debian Pack/sums/SHA256SUMS' => 'abc']);
            $b = mvTorrent($w, 'hybrid.iso', ['hybrid.iso' => str_repeat('h', 3000)], 'data/linux', ['meta version' => 2]);
            list($r, $st) = mvRig($w);
            $plan = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$a, 'hybrid'], ['from' => 'hdd1']);
            $id = $plan['status']['move'];
            check($plan['status']['expect'] === '8003B:8003' && count($plan['records']) === 2, 'plan: ' . json_encode($plan));
            check($plan['records'][1]['target_dir'] === "{$w['home']}/data/linux", 'the default folder mirrors the source folder under this home');
            check(!mvVerbs($w, 'remove') && !mvVerbs($w, 'add') && !mvLists($w) && count($w['rsync']) === 2, 'plan only probes (an empty list per source folder), changes nothing');
            $out = (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($id, []);
            check(strpos($out['status']['result'], 'copied and seeding on both') === 0, 'run: ' . json_encode($out['status']));
            check(file_get_contents("{$w['home']}/data/Debian Pack/sums/SHA256SUMS") === 'abc' && filesize("{$w['home']}/data/linux/hybrid.iso") === 3000, 'files copied');
            $hyb = MoveMeta::parse($w['source'][$b]['raw']);
            check($hyb['v2id'] !== null && $w['target']['qbittorrent'][$hyb['v2id']]['state'] === 'seeding', 'qBittorrent lists the hybrid under its cut v2 id and seeds it');
            check($w['target']['qbittorrent'][$a]['state'] === 'seeding', 'the v1 torrent seeds here under the same id');
            check(count($w['source']) === 2 && !mvVerbs($w, 'remove') && is_file("{$w['src']}/data/Debian Pack/netinst.iso"), 'source untouched without --free-source');
            foreach ($w['rsync'] as $argv) {
                check(in_array('-rt', $argv, true) && !in_array('-a', $argv, true) && in_array('--partial', $argv, true) && in_array('--files-from=-', $argv, true) && in_array('--from0', $argv, true), 'rsync flags');
                check(!preg_grep('/^--(delete|remove)/', $argv) && !preg_grep('/Debian|hybrid|netinst/', $argv), 'never --delete, no names in argv: ' . json_encode($argv));
                check(in_array('fixtureuserx@src.example:data/', $argv, true) || in_array('fixtureuserx@src.example:data/linux/', $argv, true), 'the remote side sees only the torrent folder');
            }
            $steps = array_count_values(array_column($st->logLines($id), 'step'));
            check($steps['copy'] === 2 && $steps['resume'] === 2 && $steps['recheck-poll'] >= 2, 'each step logged: ' . json_encode($steps));
            $again = (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($id, []);
            check(count(mvLists($w)) === 2 && $again['records'][0]['next'] === 'free-source', 'a second run finds every step done and copies nothing');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move: an interrupted copy stops the run and the next run continues it from the cut file to seeding' => function () {
        $w = mvWorld('pk-move-resume');
        try {
            $a = mvTorrent($w, 'Big', ['Big/one.bin' => str_repeat('1', 4000), 'Big/two.bin' => str_repeat('2', 4000)]);
            list($r, $st) = mvRig($w);
            $id = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$a], ['from' => 'hdd1'])['status']['move'];
            $w['rsync_fail'] = 2;
            $err = mvError(function () use ($r, $st, $w, $id) {
                (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($id, []);
            });
            check(strpos($err, 'copy stopped: rsync exit 30') === 0, "stopped: $err");
            check(filesize("{$w['home']}/data/.pikkumies-move-$id-0/Big/two.bin") === 2000 && !file_exists("{$w['home']}/data/Big") && empty($st->load($id)['items'][0]['done']['copy']), 'the cut file stays in the staging folder, nothing placed, copy not done');
            check(!mvVerbs($w, 'add') && !isset($w['target']['qbittorrent'][$a]), 'nothing added after a failed copy');
            $out = (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($id, []);
            check($w['target']['qbittorrent'][$a]['state'] === 'seeding' && filesize("{$w['home']}/data/Big/two.bin") === 4000, 'finished: ' . json_encode($out['status']));
            check($st->load($id)['items'][0]['done']['export'] !== null && count(mvLists($w)) === 2, 'the second run started at the copy');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move: a recheck below 100 % stops, keeps the source and leaves it paused here; the next copy uses checksums' => function () {
        $w = mvWorld('pk-move-recheck');
        try {
            $a = mvTorrent($w, 'Bad', ['Bad/x.bin' => str_repeat('x', 3000)]);
            list($r, $st) = mvRig($w);
            $id = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$a], ['from' => 'hdd1'])['status']['move'];
            $w['corrupt'] = true;
            $err = mvError(function () use ($r, $st, $w, $id, $a) {
                (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($id, ['free-source' => true, 'ids' => $a, 'expect' => '3000B:3000', 'intent' => 'move it and delete the old one']);
            });
            check(strpos($err, 'recheck ended at 50 %') === 0, "stopped: $err");
            check($w['target']['qbittorrent'][$a]['state'] === 'paused' && !mvVerbs($w, 'resume'), 'never resumed: a short copy is not filled from the swarm');
            check(isset($w['source'][$a]) && !mvVerbs($w, 'remove') && !is_file("{$w['t']}/intents.jsonl"), 'the source keeps its copy, no intent recorded');
            $item = $st->load($id)['items'][0];
            check(!empty($item['checksum']) && empty($item['done']['copy']) && !empty($item['done']['add']), 'the next run copies again with checksums');
            $w['corrupt'] = false;
            (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($id, []);
            check(in_array('-c', end($w['rsync']), true) && $w['target']['qbittorrent'][$a]['state'] === 'seeding', 'second copy with -c, then seeding');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move: plan refuses an ambiguous name, an incomplete torrent and a full box, and writes no move' => function () {
        $w = mvWorld('pk-move-plan');
        try {
            $a = mvTorrent($w, 'Show S01', ['Show S01' => 'aaaa']);
            mvTorrent($w, 'Show S02', ['Show S02' => 'bbbb']);
            list($r, $st) = mvRig($w);
            $p = new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp");
            check(strpos(mvError(function () use ($p) {
                $p->plan(['Show'], ['from' => 'hdd1']);
            }), 'nothing planned: Show matches 2 torrents') === 0, 'ambiguous name refused');
            $w['source'][$a]['progress'] = 0.5;
            check(strpos(mvError(function () use ($p, $a) {
                $p->plan([$a], ['from' => 'hdd1']);
            }), 'is not complete') !== false, 'incomplete refused');
            $w['source'][$a]['progress'] = 1;
            check(strpos(mvError(function () use ($p, $a) {
                $p->plan([$a], ['from' => 'hdd1', 'dir' => '/etc']);
            }), 'not a folder inside your home') !== false, '--dir outside the home refused');
            Quota::$live = function () {
                return "Disk quotas for user fixtureuserx (uid 1000):\n     Filesystem  space   quota   limit   grace   files   quota   limit   grace\n      /dev/md0      2K      2K      3K               1       0       0\n";
            };
            $err = mvError(function () use ($p, $a) {
                $p->plan([$a], ['from' => 'hdd1']);
            });
            check(strpos($err, 'nothing planned: 4 bytes need 5 free here, and 0 are free') === 0, "quota check: $err");
            check(!$st->all(), 'no move saved');
            $to = $p->plan([$a], ['to' => 'hdd2']);
            check($to['status']['planned'] === false && strpos($to['records'][0]['command'], 'move.php plan') !== false, 'plan --to another box prints its command');
        } finally {
            Quota::$live = null;
            testRmTree($w['t']);
        }
    },
    'move: the .torrent meta refuses a path that climbs out of its folder and skips BEP 47 padding files' => function () {
        $bad = mvBenc(['info' => ['name' => 'x', 'piece length' => 1, 'pieces' => '', 'files' => [['length' => 1, 'path' => ['..', 'etc']]]]]);
        check(strpos(mvError(function () use ($bad) {
            MoveMeta::parse($bad);
        }), 'unsafe path') !== false, '.. refused');
        $pad = MoveMeta::parse(mvBenc(['info' => ['name' => 'x', 'piece length' => 1, 'pieces' => '', 'files' => [
            ['length' => 3, 'path' => ['a']], ['length' => 9, 'path' => ['.pad', '9'], 'attr' => 'p']]]]));
        check($pad['files'] === [['x/a', 3]] && $pad['bytes'] === 3, 'padding left out: ' . json_encode($pad['files']));
        check(MoveCopy::listing([['x/a b', 1], ["x/n\nl", 1]]) === "x/a b\0x/n\nl\0", 'list entries');
    },
    'move: export through a real unit process pages the .torrent at the 16 KiB budget and MoveSource joins and checks it' => function () {
        $w = mvWorld('pk-move-export');
        try {
            $files = [];
            for ($i = 0; $i < 400; $i++) {
                $files["Many/file-$i-" . str_repeat('n', 60) . '.bin'] = 'x';
            }
            $id = mvTorrent($w, 'Many', $files);
            $raw = $w['source'][$id]['raw'];
            check(strlen($raw) > 30000, 'the fixture needs several pages: ' . strlen($raw));
            mkdir("{$w['t']}/units/fakec", 0700, true);
            file_put_contents("{$w['t']}/raw.torrent", $raw);
            $lib = dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib';
            $stub = 'function details($n) { return []; } function files($n) { return []; } function filesOf(array $r) { return []; }'
                . ' function add(array $i, array $o) { return []; } function remove(array $n, $d) { return []; } function pause(array $n) { return []; }'
                . ' function resume(array $n) { return []; } function stats() { return []; } function traffic() { return []; } function restart() { return []; }'
                . ' function recheck(array $n) { } function reannounce(array $n) { } function setLimits($d, $u) { return []; } function trackers($n) { return []; }';
            file_put_contents("{$w['t']}/units/fakec/fakec.php", "<?php\nrequire " . var_export("$lib/Torrents.php", true) . ";\nrequire "
                . var_export("$lib/TorrentExport.php", true) . ";\nclass FakeExportClient implements TorrentClient {\n"
                . 'function torrents() { return [["id" => ' . var_export($id, true) . ', "native" => ' . var_export(strtoupper($id), true)
                . ', "name" => "Many"]]; } ' . $stub . "\n}\n"
                . 'Torrents::run($argv, function () { return new FakeExportClient(); }, "usage", ["export" => function ($c, $args, $opt) {'
                . ' return TorrentExport::verb($c, $args, function () { return [file_get_contents(' . var_export("{$w['t']}/raw.torrent", true)
                . '), "fixture"]; }, "fakec"); }]);' . "\n");
            list(, $out) = runPhp("{$w['t']}/units/fakec/fakec.php", ['export', $id, 'base64']);
            $lines = cliLines($out);
            check(count($lines) === 4 && end($lines)['truncated'] === true, 'one answer holds the head and two parts: ' . substr($out, 0, 300));
            list($got, $meta) = (new MoveSource(new MoveRunner("{$w['t']}/units")))->export('local', 'fakec', $id);
            check($got === $raw && count($meta['files']) === 400 && $meta['v1'] === $id, 'joined from ' . ceil(strlen(base64_encode($raw)) / TorrentExport::CHUNK) . ' parts');
        } finally {
            testRmTree($w['t']);
        }
    },
];
