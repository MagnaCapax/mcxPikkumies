<?php
/** torrent-clients/move: freeing the source (intent, --expect, live re-checks), odd file names through real rsync, and `export`. */
require_once __DIR__ . '/moveSupport.php';

if (!function_exists('mvDone')) {
    /** A world with one torrent planned and run to seeding here; returns [$w-key values via reference, move id]. */
    function mvDone(array &$w, array $files = null)
    {
        $files = $files !== null ? $files : ['Pack/a.bin' => str_repeat('a', 3000), 'Pack/b.bin' => 'bb'];
        $id = mvTorrent($w, explode('/', key($files))[0], $files);
        list($r, $st) = mvRig($w);
        $move = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1'])['status']['move'];
        (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, []);
        return [$r, $st, $move, $id];
    }

    function mvFree(MoveRunner $r, MoveState $st, array $w, $move, array $opt)
    {
        return (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, $opt);
    }
}

return [
    'move free: --free-source with the expect value and the user\'s words removes on the source after the live checks' => function () {
        $w = mvWorld('pk-movefree-ok');
        try {
            list($r, $st, $move, $id) = mvDone($w);
            $out = mvFree($r, $st, $w, $move, ['free-source' => true, 'ids' => $id, 'expect' => '3002B:3002', 'intent' => 'move Pack here and delete it from hdd1']);
            check($out['status']['freed'] === true && $out['status']['verified'] === true, 'freed: ' . json_encode($out['status']));
            $rm = mvVerbs($w, 'remove');
            check(count($rm) === 1 && $rm[0] === ['rtorrent', 'remove', $id, '--with-data', '--expect', '3002B:3002'], 'one remove through the source unit: ' . json_encode($rm));
            check(!isset($w['source'][$id]) && !is_file("{$w['src']}/data/Pack/a.bin") && filesize("{$w['home']}/data/Pack/a.bin") === 3000, 'gone there, here intact');
            $intent = json_decode(trim(file_get_contents("{$w['t']}/intents.jsonl")), true);
            check($intent['move'] === $move && $intent['ids'] === [$id] && $intent['sizes'] === [$id => 3002] && $intent['intent'] === 'move Pack here and delete it from hdd1', 'intent: ' . json_encode($intent));
            $shows = array_keys(array_filter($w['calls'], function ($c) {
                return $c[0] === 'here-qbittorrent' && $c[1] === 'show';
            }));
            $rmAt = array_keys(array_filter($w['calls'], function ($c) {
                return $c[1] === 'remove';
            }));
            check(end($shows) === $rmAt[0] - 1, 'the last call before the delete is the live check here');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move free: refused before anything runs without --intent, without --expect, or with --expect alone' => function () {
        $w = mvWorld('pk-movefree-args');
        try {
            $id = mvTorrent($w, 'One', ['One' => 'x']);
            list($r, $st) = mvRig($w);
            $move = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1'])['status']['move'];
            $calls = count($w['calls']);
            foreach ([['free-source' => true, 'expect' => '1B:1'], ['free-source' => true, 'intent' => 'delete it'], ['expect' => '1B:1'],
                ['free-source' => true, 'expect' => '1B:1', 'intent' => '   ']] as $opt) {
                check(strpos(mvError(function () use ($r, $st, $w, $move, $opt) {
                    mvFree($r, $st, $w, $move, $opt);
                }), 'nothing done: freeing the source needs all of') === 0, 'refused: ' . json_encode($opt));
            }
            check(count($w['calls']) === $calls && !mvLists($w), 'nothing was called on either box');
            $held = $st->lock($move);
            check(strpos(mvError(function () use ($r, $st, $w, $move) {
                mvFree($r, $st, $w, $move, []);
            }), "move $move is running already") === 0 && !mvLists($w), 'a second run of a running move is refused');
            fclose($held);
            check(strpos(mvError(function () use ($st) {
                $st->lock('../../x');
            }), 'not a move id') === 0, 'a lock name is a move id');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move free: refused when the live target is not seeding, though the move state says every step is done or was edited' => function () {
        $w = mvWorld('pk-movefree-live');
        try {
            list($r, $st, $move, $id) = mvDone($w);
            $opt = ['free-source' => true, 'ids' => $id, 'expect' => '3002B:3002', 'intent' => 'delete it from hdd1'];
            $w['target']['qbittorrent'][$id]['state'] = 'paused'; // stopped here after the move
            check(strpos(mvError(function () use ($r, $st, $w, $move, $opt) {
                mvFree($r, $st, $w, $move, $opt);
            }), 'source not freed: Pack is not seeding complete here') === 0, 'paused target refused');
            // The state is edited to point at another torrent that does seed here: the id comes from the live source export.
            $other = str_repeat('e', 40);
            $w['target']['qbittorrent'][$other] = ['id' => $other, 'state' => 'seeding', 'progress' => 1, 'dir' => $w['home'], 'files' => []];
            unset($w['target']['qbittorrent'][$id]);
            $m = $st->load($move);
            $m['items'][0]['target_id'] = $other;
            $m['items'][0]['done']['resume'] = 'verified';
            $st->save($m);
            check(strpos(mvError(function () use ($r, $st, $w, $move, $opt) {
                mvFree($r, $st, $w, $move, $opt);
            }), "source not freed: Pack is not seeding complete here under $id") === 0, 'edited state refused');
            $w['target']['qbittorrent'][$id] = ['id' => $id, 'state' => 'seeding', 'progress' => 1, 'dir' => "{$w['home']}/data", 'files' => []];
            unlink("{$w['home']}/data/Pack/b.bin");
            check(strpos(mvError(function () use ($r, $st, $w, $move, $opt) {
                mvFree($r, $st, $w, $move, $opt);
            }), '(1 files missing or of another size)') !== false, 'a file gone here refuses too');
            check(!mvVerbs($w, 'remove') && isset($w['source'][$id]) && is_file("{$w['src']}/data/Pack/b.bin"), 'the source was never touched');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move free: a changed total is refused; a key without --allow-delete changes nothing and prints the command' => function () {
        $w = mvWorld('pk-movefree-key');
        try {
            list($r, $st, $move, $id) = mvDone($w);
            check(strpos(mvError(function () use ($r, $st, $w, $move, $id) {
                mvFree($r, $st, $w, $move, ['free-source' => true, 'ids' => $id, 'expect' => '9B:9', 'intent' => 'delete it from hdd1']);
            }), 'source not freed: its files now total 3002B:3002, not 9B:9') === 0, 'changed total refused');
            $w['allow_delete'] = false;
            $out = mvFree($r, $st, $w, $move, ['free-source' => true, 'ids' => $id, 'expect' => '3002B:3002', 'intent' => 'delete it from hdd1']);
            check($out['status']['freed'] === false && $out['records'][0]['command'] === "php ~/pikkumies/tools/torrent-clients/rtorrent/rtorrent.php remove $id --with-data --expect 3002B:3002",
                'command for the source box: ' . json_encode($out));
            check(!mvVerbs($w, 'remove') && isset($w['source'][$id]) && empty($st->load($move)['freed_at']), 'nothing removed, not marked freed');
        } finally {
            testRmTree($w['t']);
        }
    },
    'move: file names with spaces, a newline and quotes go through the real rsync on stdin, never in its argv' => function () {
        if (ToolProc::which('rsync') === null) {
            skip('rsync is not installed');
        }
        $w = mvWorld('pk-movefree-names');
        try {
            $files = ["It's \"odd\" pack/a b.txt" => 'one', "It's \"odd\" pack/sub dir/new\nline.txt" => 'two two', "It's \"odd\" pack/-rf" => 'x'];
            $id = mvTorrent($w, "It's \"odd\" pack", $files, 'data/in');
            list($r, $st) = mvRig($w);
            $seen = [];
            $real = mvRealRsync($w);
            $r->rsyncFn = function ($argv, $list) use (&$seen, $real) {
                $seen = $argv;
                return $real($argv, $list);
            };
            $move = (new MovePlan($r, $st, $w['home'], "{$w['t']}/tmp"))->plan([$id], ['from' => 'hdd1'])['status']['move'];
            (new MoveRun($r, $st, $w['home'], "{$w['t']}/tmp"))->run($move, []);
            foreach ($files as $rel => $data) {
                check(@file_get_contents("{$w['home']}/data/in/$rel") === $data, "copied: $rel");
            }
            check(!preg_grep('/odd|line|a b|-rf/', $seen) && $w['target']['qbittorrent'][$id]['state'] === 'seeding', 'no name in argv: ' . json_encode($seen));
        } finally {
            testRmTree($w['t']);
        }
    },
    'move export: plain export saves a 0600 file and prints no bytes, passkey or tracker; the base64 form is for move; a file of another torrent is refused' => function () {
        $w = mvWorld('pk-movefree-export');
        try {
            $id = mvTorrent($w, 'E', ['E' => str_repeat('e', 100)], 'data', ['private' => 1]);
            $raw = $w['source'][$id]['raw'] . '';
            $fake = new class($id) implements TorrentClient {
                public $id;
                public function __construct($id) { $this->id = $id; }
                public function torrents() { return [['id' => $this->id, 'native' => strtoupper($this->id), 'name' => 'E']]; }
                public function details($n) { return []; }
                public function files($n) { return []; }
                public function filesOf(array $r) { return []; }
                public function add(array $i, array $o) { return []; }
                public function remove(array $n, $d) { return []; }
                public function pause(array $n) { return []; }
                public function resume(array $n) { return []; }
                public function stats() { return []; }
                public function traffic() { return []; }
                public function restart() { return []; }
                public function recheck(array $n) { }
                public function reannounce(array $n) { }
                public function setLimits($d, $u) { return []; }
                public function trackers($n) { return []; }
            };
            $read = function ($c, $t) use ($raw) {
                return [$raw, 'fixture'];
            };
            TorrentExport::$dir = "{$w['t']}/exports";
            $plain = TorrentExport::verb($fake, [$id], $read, 'rtorrent'); // the agent-facing form
            $p = $plain['records'][0];
            check($p['file'] === "{$w['t']}/exports/$id.torrent" && file_get_contents($p['file']) === $raw && (fileperms($p['file']) & 0777) === 0600, 'saved 0600: ' . json_encode($p));
            $printed = json_encode($plain);
            check(strpos($printed, 'b64') === false && strpos($printed, substr(base64_encode($raw), 8, 40)) === false
                && strpos($printed, 'abcdef0123456789abcd') === false && strpos($printed, 'tracker.example') === false, 'no base64, passkey or tracker in the output: ' . $printed);
            $out = TorrentExport::verb($fake, [$id, 'base64'], $read, 'rtorrent'); // move's form
            $head = $out['records'][0];
            check($head['info_hash'] === $id && $head['private'] === true && $head['trackers'] === true && $head['parts'] === count($out['records']) - 1, 'head: ' . json_encode($head));
            check(base64_decode(implode('', array_column(array_slice($out['records'], 1), 'b64'))) === $raw, 'the parts join to the file');
            check(strpos(json_encode($out), 'tracker.example') === false, 'no tracker URL in clear');
            $other = mvBenc(['info' => ['name' => 'F', 'length' => 1, 'piece length' => 1, 'pieces' => '']]);
            check(strpos(mvError(function () use ($fake, $id, $other) {
                TorrentExport::verb($fake, [$id], function () use ($other) {
                    return [$other, 'stale file'];
                }, 'rtorrent');
            }), 'not exported: the saved .torrent (stale file) hashes to') === 0, 'a file of another torrent is refused');
            file_put_contents("{$w['home']}/.rtorrent.rc", "# x\nsession.path.set = ~/sess\n");
            check(RtorrentExport::fromRc($w['home']) === "{$w['home']}/sess", 'rc session folder');
        } finally {
            TorrentExport::$dir = null;
            testRmTree($w['t']);
        }
    },
];
