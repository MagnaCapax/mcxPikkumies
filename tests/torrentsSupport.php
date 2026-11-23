<?php
/**
 * The fake torrent client the torrent-client verb tests share (TorrentsTest, TorrentsVerbsTest, TorrentsVerbsEdgeTest,
 * TorrentsWithDataTest): torrentsFixture() writes it with a JSON state file and some data files, and torrentsCalls,
 * torrentsEdit and torrentsRun read, change and drive it. Each test file requires this one, so a targeted run of any
 * of them has the helpers and the Torrents classes without the others.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';

function torrentsFixture()
{
    $dir = sys_get_temp_dir() . '/pk-tor-' . getmypid() . '-' . mt_rand();
    @mkdir("$dir/data/A", 0700, true);
    @mkdir("$dir/data/B", 0700, true);
    file_put_contents("$dir/data/A/a.mkv", str_repeat('a', 3000));
    file_put_contents("$dir/data/B/b.mkv", str_repeat('b', 5000));
    link("$dir/data/B/b.mkv", "$dir/data/b-hardlink.mkv");
    $t = function ($id, $name, $path, $files) {
        return ['id' => $id, 'native' => strtoupper($id), 'name' => $name, 'state' => 'seeding', 'progress' => 1, 'size' => 0, 'done' => 0,
            'ratio' => 1.0, 'down_rate' => 0, 'up_rate' => 0, 'added_at' => 0, 'label' => '', 'path' => $path, 'message' => '', 'files' => $files];
    };
    $state = ['calls' => [], 'keep' => [], 'torrents' => [
        $t(str_repeat('a', 40), 'Alpha', "$dir/data/A", ["$dir/data/A/a.mkv"]),
        $t(str_repeat('b', 40), 'Beta', "$dir/data/B", ["$dir/data/B/b.mkv"]),
        $t('ab' . str_repeat('c', 38), 'Alpha twin', "$dir/data/A", ["$dir/data/A/a.mkv"]),
    ]];
    file_put_contents("$dir/state.json", json_encode($state));
    $lib = dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';
    // The fake deletes a torrent's files on remove with data, except the paths listed in "keep" (a client that failed to).
    file_put_contents("$dir/fake.php", '<?php
require ' . var_export($lib, true) . ';
class Fake implements TorrentClient {
    public $f; public $s;
    function __construct($f) { $this->f = $f; $this->s = json_decode(file_get_contents($f), true); }
    function save($call) { $this->s["calls"][] = $call; file_put_contents($this->f, json_encode($this->s)); }
    function torrents() { return array_map(function ($t) { unset($t["files"]); return $t; }, $this->s["torrents"]); }
    function find($n) { foreach ($this->s["torrents"] as $t) { if ($t["native"] === $n) { return $t; } } throw new Exception("unknown $n"); }
    function details($n) { return ["trackers" => 1]; }
    function files($n) { return array_map(function ($p) { return [$p, (int) @filesize($p)]; }, $this->find($n)["files"]); }
    function filesOf(array $rs) { $o = []; // "unlisted": a torrent the client cannot list the files of
        foreach ($rs as $r) { $o[$r["native"]] = empty($this->find($r["native"])["unlisted"]) ? $this->files($r["native"]) : null; } return $o; }
    function add(array $items, array $opt) { $this->save(["add", $items, $opt]); return [["id" => "x", "name" => "x", "result" => "added"]]; }
    function remove(array $ns, $data) { $this->save(["remove", $ns, $data]); $out = [];
        foreach ($ns as $n) { $t = $this->find($n);
            if ($data) { foreach ($t["files"] as $p) { if (!in_array($p, $this->s["keep"], true)) { @unlink($p); } } }
            $out[] = ["id" => $t["id"], "name" => $t["name"], "disposal" => $data ? "deleted" : "kept", "errors" => []]; } return $out; }
    function pause(array $ns) { $this->save(["pause", $ns]); return []; }
    function resume(array $ns) { $this->save(["resume", $ns]); return []; }
    function stats() { return ["version" => "fake"]; }
    function traffic() { $none = ["down_limit" => 0, "up_limit" => 0];
        return ["down_rate" => 0] + (isset($this->s["limits"]) ? $this->s["limits"] : $none); }
    function restart() { return ["answered" => true]; }
    function recheck(array $ns) { $this->save(["recheck", $ns]); }
    function reannounce(array $ns) { $this->save(["reannounce", $ns]); }
    function setLimits($d, $u) { $l = $this->traffic(); // whole KiB/s as qBittorrent keeps them (sessionimpl-5.0.0.cpp:3366-3371)
        foreach (["down_limit" => $d, "up_limit" => $u] as $k => $v) {
            if ($v !== null) { $l[$k] = $v <= 0 ? 0 : ($v <= 1024 ? 1024 : intdiv($v, 1024) * 1024); } }
        $this->s["limits"] = ["down_limit" => $l["down_limit"], "up_limit" => $l["up_limit"]];
        $this->save(["limit", $d, $u]); return ["persists" => false]; }
    function trackers($n) { return [
        ["url" => "https://t.example:8443/a1b2c3d4e5f6a7b8c9d0/announce?passkey=f00dfeedf00dfeedf00d", "tier" => 0, "status" => "working",
            "message" => "invalid passkey 0123abcd4567ef89aa, contact staff", "seeds" => 3, "leechers" => -1],
        ["url" => "udp://T2.example:6969/announce/abcdefghijklmnopqrstuvwxyz", "status" => "bogus"]]; }
}
Torrents::run($argv, function () { return new Fake(' . var_export("$dir/state.json", true) . '); }, "usage: fake.php <verb>");
');
    return $dir;
}

function torrentsCalls($dir)
{
    return json_decode(file_get_contents("$dir/state.json"), true)['calls'];
}

/** Changes the fake's state with $fn(array &$state). */
function torrentsEdit($dir, callable $fn)
{
    $s = json_decode(file_get_contents("$dir/state.json"), true);
    $fn($s);
    file_put_contents("$dir/state.json", json_encode($s));
}

/** Runs the fake with HOME set to the fixture (the --dir rule needs it). */
function torrentsRun($dir, array $args)
{
    $old = getenv('HOME');
    putenv("HOME=$dir");
    try {
        return runPhp("$dir/fake.php", $args);
    } finally {
        putenv($old === false ? 'HOME' : "HOME=$old");
    }
}
