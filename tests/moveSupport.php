<?php
/**
 * A fake pair of boxes for the move tests (MoveTest, MoveFreeTest): a source service "hdd1" whose client units answer
 * through MoveRunner's remoteFn (show, list, export, remove, with --offset paging three records a page), and this box's
 * clients through localFn (stats, show, add, recheck, resume) with their data on disk. rsync is a PHP copy of the
 * NUL-separated list (or the real rsync, run locally, for the names test). Everything lives under one temp dir.
 */
$moveDir = dirname(__DIR__) . '/pikkumies/tools/torrent-clients';
require_once "$moveDir/lib/Torrents.php";
require_once "$moveDir/lib/TorrentExport.php";
require_once "$moveDir/rtorrent/Locate.php";
require_once "$moveDir/rtorrent/Export.php";
foreach (['MoveMeta', 'MoveState', 'MoveProc', 'MoveRunner', 'MoveSource', 'MoveCopy', 'MoveRoots', 'MovePlace', 'MovePlan', 'MoveTarget', 'MoveStepCopy', 'MoveRun', 'MoveFree'] as $moveClass) {
    require_once "$moveDir/move/$moveClass.php";
}

/** Bencode for fixtures (ksort: BEP 3 dictionaries are sorted). */
function mvBenc($v)
{
    if (is_int($v)) {
        return "i{$v}e";
    }
    if (is_string($v)) {
        return strlen($v) . ':' . $v;
    }
    if (array_keys($v) === range(0, count($v) - 1)) {
        return 'l' . implode('', array_map('mvBenc', $v)) . 'e';
    }
    ksort($v, SORT_STRING);
    $s = 'd';
    foreach ($v as $k => $x) {
        $s .= mvBenc((string) $k) . mvBenc($x);
    }
    return $s . 'e';
}

/** A torrent on the source: files [rel path in the folder => contents]; writes them under $home/$folder. */
function mvTorrent(array &$w, $name, array $files, $folder = 'data', array $extra = [])
{
    $info = ['name' => $name, 'piece length' => 16384, 'pieces' => str_repeat("\0", 20)] + $extra;
    if (count($files) === 1 && key($files) === $name) {
        $info['length'] = strlen(current($files));
    } else {
        $info['files'] = [];
        foreach ($files as $rel => $data) {
            $info['files'][] = ['length' => strlen($data), 'path' => explode('/', substr($rel, strlen($name) + 1))];
        }
    }
    $raw = mvBenc(['announce' => 'https://tracker.example/abcdef0123456789abcd/announce', 'info' => $info]);
    $h = TorrentExport::hashes($raw);
    $abs = [];
    foreach ($files as $rel => $data) {
        $p = "{$w['src']}/$folder/$rel";
        @mkdir(dirname($p), 0700, true);
        file_put_contents($p, $data);
        $abs[] = [$p, strlen($data)];
    }
    $w['source'][$h['v1']] = ['id' => $h['v1'], 'name' => $name, 'size' => array_sum(array_column($abs, 1)), 'done' => array_sum(array_column($abs, 1)),
        'progress' => 1, 'state' => 'seeding', 'label' => 'linux', 'private' => !empty($extra['private']), 'path' => "{$w['src']}/$folder",
        'files' => $abs, 'raw' => $raw];
    return $h['v1'];
}

function mvWorld($prefix)
{
    $t = testTempDir($prefix);
    foreach (['src', 'home/data', 'moves'] as $d) {
        mkdir("$t/$d", 0700, true);
    }
    return ['t' => $t, 'src' => "$t/src", 'home' => "$t/home", 'source' => [], 'target' => [], 'calls' => [], 'running' => ['qbittorrent'],
        'allow_delete' => true, 'corrupt' => false, 'rsync_fail' => 0, 'rsync' => [], 'lists' => [], 'source_client' => 'rtorrent'];
}

/** Answer lines as a unit prints them; $records paged three a page when --offset is given. */
function mvOut(array $records, array $status, array $args)
{
    $i = array_search('--offset', $args, true);
    $off = $i === false ? 0 : (int) $args[$i + 1];
    $page = array_slice($records, $off, $i === false ? null : 3);
    if ($i !== false && $off + 3 < count($records)) {
        $status += ['truncated' => true, 'next_offset' => $off + 3];
    }
    $out = '';
    foreach ($page as $r) {
        $out .= json_encode($r) . "\n";
    }
    return ['rc' => 0, 'stdout' => $out . json_encode(['ok' => true] + $status) . "\n", 'stderr' => ''];
}

function mvFail($error)
{
    return ['rc' => 1, 'stdout' => json_encode(['ok' => false, 'error' => $error, 'hint' => '']) . "\n", 'stderr' => ''];
}

function mvPublic(array $t)
{
    return array_diff_key($t, ['files' => 1, 'raw' => 1]);
}

/** The source box's client unit, as serve would answer `tool torrent-clients/<c> <verb> ...`. */
function mvSource(array &$w, array $argv)
{
    $client = substr($argv[1], strlen('torrent-clients/'));
    $verb = $argv[2];
    $args = array_slice($argv, 3);
    $w['calls'][] = array_merge([$client], array_slice($argv, 2));
    if ($client !== $w['source_client']) {
        return mvFail("$client is not installed");
    }
    $ids = array_values(array_filter($args, function ($a) {
        return preg_match('/^[0-9a-f]{8,40}$/', $a) === 1;
    }));
    $pick = function ($q) use ($w) {
        return array_values(array_filter($w['source'], function ($t) use ($q) {
            return strpos($t['id'], $q) === 0;
        }));
    };
    if ($verb === 'list') {
        $i = array_search('--search', $args, true);
        $rows = array_values(array_filter($w['source'], function ($t) use ($i, $args) {
            return $i === false || stripos($t['name'], $args[$i + 1]) !== false;
        }));
        return mvOut(array_map('mvPublic', $rows), [], $args);
    }
    foreach ($ids as $q) {
        if (count($pick($q)) !== 1) {
            return mvFail(($verb === 'remove' ? 'nothing changed' : 'not found') . ": $q: no such torrent");
        }
    }
    if ($verb === 'export') {
        $t = $pick($ids[0])[0];
        $parts = str_split(base64_encode($t['raw']), 40);
        $recs = [['id' => $t['id'], 'name' => $t['name'], 'bytes' => strlen($t['raw']), 'sha256' => hash('sha256', $t['raw']), 'parts' => count($parts)]];
        foreach ($parts as $n => $p) {
            $recs[] = ['part' => $n, 'b64' => $p];
        }
        return mvOut($recs, [], $args);
    }
    $bytes = 0;
    $recs = [];
    foreach ($ids as $q) {
        $t = $pick($q)[0];
        $recs[] = mvPublic($t);
        foreach ($t['files'] as $f) {
            $recs[] = ['torrent' => $t['id'], 'file' => $f[0], 'bytes' => $f[1]];
            $bytes += $f[1];
        }
    }
    $expect = "{$bytes}B:$bytes";
    if ($verb === 'show') {
        return mvOut(in_array('--files', $args, true) ? $recs : [mvPublic($pick($ids[0])[0])], ['expect' => $expect, 'refused' => []], $args);
    }
    if ($verb === 'remove') {
        $e = array_search('--expect', $args, true);
        if (!in_array('--with-data', $args, true) || $e === false || $args[$e + 1] !== $expect) {
            return mvFail('nothing changed: --expect is not the value show printed');
        }
        foreach ($ids as $q) {
            foreach ($pick($q)[0]['files'] as $f) {
                unlink($f[0]);
            }
            unset($w['source'][$pick($q)[0]['id']]);
        }
        return mvOut([['disposal' => 'deleted']], [], $args);
    }
    return mvFail("verb $verb not faked");
}

/** This box's client unit (`php <c>.php <verb> ...`). */
function mvLocal(array &$w, array $argv)
{
    $client = basename(dirname($argv[1]));
    $verb = $argv[2];
    $args = array_slice($argv, 3);
    $w['calls'][] = array_merge(["here-$client"], array_slice($argv, 2));
    if (!in_array($client, $w['running'], true)) {
        return mvFail("$client is not running");
    }
    $mine = &$w['target'][$client];
    if ($verb === 'stats') {
        return mvOut([['version' => 'fake']], [], $args);
    }
    if ($verb === 'add') {
        $meta = MoveMeta::parse(file_get_contents($args[0]));
        $id = MoveMeta::idFor($meta, $client);
        $mine[$id] = ['id' => $id, 'state' => 'paused', 'progress' => 0, 'dir' => $args[array_search('--dir', $args, true) + 1], 'files' => $meta['files']];
        return mvOut([['id' => $id, 'result' => 'added']], ['verified' => true], $args);
    }
    $id = $args[0];
    if (!isset($mine[$id])) {
        return mvFail("not found: $id: no such torrent");
    }
    $t = &$mine[$id];
    if ($verb === 'recheck') {
        $t['state'] = 'checking';
    } elseif ($verb === 'resume') {
        $t['state'] = $t['progress'] >= 1 ? 'seeding' : 'downloading';
    } elseif ($verb === 'show' && $t['state'] === 'checking') {
        $t['state'] = 'paused';
        $t['progress'] = !$w['corrupt'] && !MoveCopy::check($t['dir'], $t['files']) ? 1 : 0.5;
        return mvOut([['id' => $id, 'state' => 'checking', 'progress' => 0.2]], [], $args);
    }
    return mvOut([['id' => $id, 'state' => $t['state'], 'progress' => $t['progress']]], [], $args);
}

/** The PHP stand-in for rsync: copies each `<root>/./<rel>` of the list from the source home; fails after N files. */
function mvRsync(array &$w, array $argv, $list)
{
    $w['rsync'][] = $argv;
    $w['lists'][] = file_get_contents($list);
    $dest = rtrim(end($argv), '/');
    $root = rtrim(explode(':', $argv[count($argv) - 2], 2)[1], '/'); // host:<folder>/
    $n = 0;
    foreach (array_filter(explode("\0", file_get_contents($list)), 'strlen') as $rel) {
        $data = file_get_contents("{$w['src']}/$root/$rel");
        @mkdir(dirname("$dest/$rel"), 0777, true);
        if ($w['rsync_fail'] > 0 && ++$n >= $w['rsync_fail']) {
            file_put_contents("$dest/$rel", substr($data, 0, (int) (strlen($data) / 2))); // --partial keeps the cut file
            $w['rsync_fail'] = 0;
            return ['code' => 30, 'err' => 'timeout in data send/receive'];
        }
        file_put_contents("$dest/$rel", $data);
    }
    return ['code' => 0, 'err' => ''];
}

/** The real rsync, run locally: the same argv with `-e <ssh>` dropped and `host:<folder>/` read from the source home. */
function mvRealRsync(array $w)
{
    return function ($argv, $list) use ($w) {
        $i = array_search('-e', $argv, true);
        $src = $w['src'] . '/' . explode(':', $argv[$i + 2], 2)[1];
        return (new MoveRunner('/nonexistent'))->rsync(array_merge(array_slice($argv, 0, $i), [$src, end($argv)]), $list);
    };
}

/** The non-empty copy lists rsync got (a plan's probe sends an empty one). */
function mvLists(array $w)
{
    return array_values(array_filter($w['lists'], 'strlen'));
}

/** [MoveRunner, MoveState] wired to the fake world $w (by reference). */
function mvRig(array &$w)
{
    Quota::$live = function () {
        return null;
    };
    $r = new MoveRunner(dirname(__DIR__) . '/pikkumies/tools/torrent-clients');
    $r->remoteFn = function ($nick, $argv) use (&$w) {
        return $nick === 'hdd1' ? mvSource($w, $argv) : mvFail("no service $nick");
    };
    $r->localFn = function ($argv) use (&$w) {
        return mvLocal($w, $argv);
    };
    $r->serviceFn = function ($nick) use (&$w) {
        return ['nick' => $nick, 'home' => $w['src'], 'clients' => ['rtorrent' => []], 'allow_delete' => $w['allow_delete']];
    };
    $r->sshFn = function () {
        return [['ssh', '-F', '/dev/null', '-o', 'BatchMode=yes', '-i', '/x/key'], 'fixtureuserx@src.example'];
    };
    $r->rsyncFn = function ($argv, $list) use (&$w) {
        return mvRsync($w, $argv, $list);
    };
    $r->sleepFn = function () {
    };
    $clock = 1000;
    $r->nowFn = function () use (&$clock) {
        return $clock += 10;
    };
    return [$r, new MoveState("{$w['t']}/moves")];
}

/** The error message of $fn, or '' when it did not throw a CliError. */
function mvError(callable $fn)
{
    try {
        $fn();
    } catch (CliError $e) {
        return $e->getMessage();
    }
    return '';
}

function mvVerbs(array $w, $verb)
{
    return array_values(array_filter($w['calls'], function ($c) use ($verb) {
        return $c[1] === $verb;
    }));
}
