<?php
/**
 * Edge cases of the shared torrent-client code (tools/torrent-clients/lib) found in review: malformed bencode,
 * the hardlink/cross-seed dedup in DeleteFreed/DeletePlan/DeleteRun, the sort and rate-limit edges in
 * TorrentList/TorrentChange, and the passkey redaction boundaries in TorrentTrackers. Self-contained: requires
 * its own dependencies rather than relying on another test file's load order (tests/run.php loads by filter word).
 * Deliberately never require_once's a sibling *Test.php file: tests/run.php's own loop loads every tests/*Test.php
 * with plain require() (not require_once), so a file this one require_once'd earlier would be re-executed and
 * fatal on "Cannot redeclare" the moment run.php reaches that file's own turn in a full, unfiltered run.
 */
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Torrents.php';
require_once dirname(__DIR__) . '/pikkumies/tools/torrent-clients/lib/Bencode.php';

/** A minimal TorrentClient stub for direct DeletePlan/DeleteRun calls that need no real client behaviour. */
class TorrentsEdgeStubClient implements TorrentClient
{
    private $path;
    private $disposal;

    public function __construct($path, $disposal = 'deleted')
    {
        $this->path = $path;
        $this->disposal = $disposal;
    }

    public function torrents()
    {
        return [];
    }

    public function details($native)
    {
        return [];
    }

    public function files($native)
    {
        return [[$this->path, 100]];
    }

    public function filesOf(array $records)
    {
        $out = [];
        foreach ($records as $r) {
            $out[$r['native']] = [[$this->path, 100]];
        }
        return $out;
    }

    public function add(array $items, array $opt)
    {
        return [];
    }

    public function remove(array $natives, $withData)
    {
        return [];
    }

    public function pause(array $natives)
    {
        return [];
    }

    public function resume(array $natives)
    {
        return [];
    }

    public function stats()
    {
        return [];
    }

    public function traffic()
    {
        return [];
    }

    public function restart()
    {
        return [];
    }

    public function recheck(array $natives)
    {
    }

    public function reannounce(array $natives)
    {
    }

    public function setLimits($down, $up)
    {
        return [];
    }

    public function trackers($native)
    {
        return [];
    }

    public function disposal()
    {
        return $this->disposal;
    }
}

function torrentsEdgeErr(callable $fn)
{
    try {
        $fn();
        return null;
    } catch (CliError $e) {
        return $e->getMessage();
    }
}

return [
    'bencode: a malformed dictionary key, an unterminated integer and an overrun string length are all refused, never read past the end' => function () {
        // "di5ei1ee": the "key" at offset 1 skips cleanly (it is the valid integer token i5e), and what follows it
        // also skips cleanly (i1e) -- so only the explicit key-must-be-a-string guard (Bencode.php:20-23) refuses
        // this; a parser that trusted skip()'s return value alone would accept a bogus '' key (verified empirically:
        // removing the ctype_digit($s[$i]) check in a scratch copy made this return ['' => [4, 7]] instead of []).
        check(Bencode::dict('di5ei1ee', 0) === [], 'a key that is not a length-prefixed string was accepted');
        check(Bencode::skip('i12345', 0) === -1, 'an integer token with no closing e was accepted');
        check(Bencode::skip('10:ab', 0) === -1, 'a string whose declared length overruns the buffer was accepted');
    },
    'DeleteFreed: a symlink is never one of its target\'s hard links; two hard-linked torrents free their size once, to the first id, and only when every holder is deleted' => function () {
        $dir = testTempDir();
        file_put_contents("$dir/real.mkv", str_repeat('x', 500));
        symlink("$dir/real.mkv", "$dir/link.mkv");
        $key = DeletePlan::identity("$dir/real.mkv");
        $inodes = [];
        DeleteFreed::add($inodes, $key, 't1', "$dir/real.mkv");
        DeleteFreed::add($inodes, $key, 't1', "$dir/link.mkv"); // the same torrent's symlinked path
        check($inodes[$key]['paths'] === ["$dir/real.mkv"], 'a symlink was counted as one of the inode\'s own hard links: ' . json_encode($inodes[$key]));
        // defect this catches: dropping the is_link() guard in DeleteFreed::add would double-count a cross-seed's
        // symlink as a real link, under- or over-reporting freed space.
        file_put_contents("$dir/a.bin", str_repeat('y', 700));
        link("$dir/a.bin", "$dir/b.bin");
        $key2 = DeletePlan::identity("$dir/a.bin");
        $inodes2 = [];
        DeleteFreed::add($inodes2, $key2, 't1', "$dir/a.bin");
        DeleteFreed::add($inodes2, $key2, 't2', "$dir/b.bin");
        $always = function () {
            return true;
        };
        check(DeleteFreed::byTorrent($inodes2, ['t1', 't2'], $always) === ['t1' => 700], 'a hard-linked pair, both deleted, was not credited once to the first id');
        check(DeleteFreed::byTorrent($inodes2, ['t1'], $always) === [], 'an inode held by an undeleted sibling torrent was credited anyway');
        testRmTree($dir);
    },
    'DeletePlan::identity treats a symlink as its target and gives a broken symlink its own path key; mismatch accepts the exact copy-pasted label; a trashing client\'s plan always frees 0' => function () {
        $dir = testTempDir();
        file_put_contents("$dir/real.mkv", 'x');
        symlink("$dir/real.mkv", "$dir/link.mkv");
        check(DeletePlan::identity("$dir/link.mkv") === DeletePlan::identity("$dir/real.mkv"), 'a symlink did not share its target\'s identity');
        symlink("$dir/does-not-exist", "$dir/broken.mkv");
        check(DeletePlan::identity("$dir/broken.mkv") === "path:$dir/broken.mkv", 'a broken symlink was not given its own path-based identity: ' . DeletePlan::identity("$dir/broken.mkv"));
        check(DeletePlan::mismatch('41.2GiB:44142232', ['bytes' => 44142232, 'expect' => '41.2GiB:44142232']) === '', 'the exact value `show --files` printed was refused');
        $target = ['id' => str_repeat('a', 40), 'native' => str_repeat('A', 40)];
        $plan = DeletePlan::build(new TorrentsEdgeStubClient("$dir/real.mkv", 'trashed'), [$target], [$target]);
        check($plan['bytes'] > 0 && $plan['freed'] === 0, 'a trashing client\'s plan did not force freed to 0: ' . json_encode($plan));
        // defect this catches: computing freed from DeleteFreed::byTorrent() before the disposal==='trashed' check
        // would promise space back for a client that only moves files to its trash.
        testRmTree($dir);
    },
    'DeleteRun::execute\'s shared-file refusal names every torrent a target shares with, not just the first' => function () {
        $dir = testTempDir();
        file_put_contents("$dir/shared.mkv", str_repeat('s', 100));
        $c = new TorrentsEdgeStubClient("$dir/shared.mkv");
        $all = [
            ['id' => str_repeat('a', 40), 'native' => str_repeat('A', 40), 'name' => 'Target'],
            ['id' => str_repeat('b', 40), 'native' => str_repeat('B', 40), 'name' => 'Sharer One'],
            ['id' => str_repeat('c', 40), 'native' => str_repeat('C', 40), 'name' => 'Sharer Two'],
        ];
        $msg = torrentsEdgeErr(function () use ($c, $all) {
            DeleteRun::execute($c, $all, [$all[0]], '');
        });
        check($msg !== null && strpos($msg, 'Sharer One') !== false && strpos($msg, 'Sharer Two') !== false, '3-way shared refusal dropped a sharer: ' . $msg);
        testRmTree($dir);
    },
    'DeleteRun::outcome: a planned file left on disk after the poll flips disposal to partial and a verified flag to false, on the same record' => function () {
        $dir = testTempDir();
        $gone = "$dir/gone.mkv";
        $kept = "$dir/kept.mkv";
        file_put_contents($gone, 'x');
        file_put_contents($kept, 'y');
        unlink($gone); // the client already deleted this one; $kept stays for the whole ~5s poll (DeleteRun::POLLS)
        $plan = ['files' => [
            ['torrent' => 'id1', 'file' => $gone, 'bytes' => 1, 'links' => 0, 'on_disk' => true, 'shared_with' => []],
            ['torrent' => 'id1', 'file' => $kept, 'bytes' => 1, 'links' => 0, 'on_disk' => true, 'shared_with' => []],
        ], 'inodes' => [], 'bytes' => 2, 'freed' => 0, 'shared' => [], 'unlisted' => [], 'expect' => '2B:2'];
        $r = DeleteRun::outcome($plan, [['id' => 'id1', 'name' => 'N', 'disposal' => 'deleted', 'errors' => [], 'verified' => true]]);
        $rec = $r['records'][0];
        check($rec['disposal'] === 'partial' && $rec['verified'] === false && strpos($rec['errors'][0], 'kept.mkv') !== false,
            'a left-over planned file did not flip disposal to partial and verified to false: ' . json_encode($rec));
        // defect this catches: reading the client's own "deleted"/"verified" fields as final, instead of re-checking
        // the disk, would report a partial delete as fully done and verified.
        testRmTree($dir);
    },
    'Torrents::resolve names all three causes in one mixed-reason batch, not just the first' => function () {
        $msg = torrentsEdgeErr(function () {
            Torrents::resolve([], ['xy', str_repeat('q', 8), str_repeat('9', 40)], 8);
        });
        check($msg !== null && strpos($msg, 'xy: not an id') !== false && strpos($msg, 'qqqqqqqq: not an id') !== false
            && strpos($msg, '9999999999999999999999999999999999999999: no such torrent') !== false,
            'a mixed-cause batch collapsed to one reason: ' . $msg);
    },
    'DeleteRun::finish caps a bad batch\'s listed records at 5 and tallies the rest as "N other(s) done"' => function () {
        $records = [];
        for ($i = 0; $i < 6; $i++) {
            $records[] = ['id' => "id$i", 'name' => "N$i", 'disposal' => 'kept', 'errors' => ["err$i"]];
        }
        $records[] = ['id' => 'ok1', 'name' => 'OK', 'disposal' => 'deleted', 'errors' => []];
        $msg = torrentsEdgeErr(function () use ($records) {
            DeleteRun::finish(['records' => $records, 'status' => ['freed_bytes' => 0]]);
        });
        check($msg !== null && substr_count($msg, 'err') === 5 && strpos($msg, '; 1 other(s) done') !== false,
            'a 7-record batch was not capped at 5 with the remainder tallied: ' . $msg);
    },
    'DeleteRun::outcome\'s "no space freed" note distinguishes trashed, hard-linked-elsewhere and the plain case' => function () {
        $noFiles = ['files' => [], 'inodes' => [], 'bytes' => 0, 'freed' => 0, 'shared' => [], 'unlisted' => [], 'expect' => '0B:0'];
        $trashed = DeleteRun::outcome($noFiles, [['id' => 'x', 'name' => 'X', 'disposal' => 'trashed', 'errors' => []]]);
        check(strpos($trashed['status']['note'], 'moved the files to its trash') !== false, 'trashed note: ' . $trashed['status']['note']);
        $dir = testTempDir();
        file_put_contents("$dir/shared.mkv", 'abc');
        link("$dir/shared.mkv", "$dir/outside-link.mkv"); // a hard link outside this plan's targets
        $key = DeletePlan::identity("$dir/shared.mkv");
        $plan = ['files' => [['torrent' => 'x', 'file' => "$dir/shared.mkv", 'bytes' => 3, 'links' => 2, 'on_disk' => true, 'shared_with' => []]],
            'inodes' => [$key => ['torrents' => ['x'], 'paths' => ["$dir/shared.mkv"], 'links' => 2, 'size' => 3]],
            'bytes' => 3, 'freed' => 0, 'shared' => [], 'unlisted' => [], 'expect' => '3B:3'];
        unlink("$dir/shared.mkv"); // this torrent's own copy is gone; outside-link.mkv still holds the inode
        $linked = DeleteRun::outcome($plan, [['id' => 'x', 'name' => 'X', 'disposal' => 'deleted', 'errors' => []]]);
        check($linked['status']['freed_bytes'] === 0 && strpos($linked['status']['note'], 'other hard links') !== false, 'other-hard-links note: ' . json_encode($linked['status']));
        $kept = ['files' => [['torrent' => 'x', 'file' => "$dir/never-existed.mkv", 'bytes' => 3, 'links' => 1, 'on_disk' => false, 'shared_with' => []]],
            'inodes' => [], 'bytes' => 0, 'freed' => 0, 'shared' => [], 'unlisted' => [], 'expect' => '0B:0'];
        $plain = DeleteRun::outcome($kept, [['id' => 'x', 'name' => 'X', 'disposal' => 'kept', 'errors' => []]]);
        check($plain['status']['note'] === 'no disk space was freed', 'the plain no-note case gained an incorrect suffix: ' . $plain['status']['note']);
        testRmTree($dir);
    },
    'TorrentList::sort keeps a fixed ascending-by-id tie-break regardless of --reverse, and a null numeric field stays last in both directions' => function () {
        $tied = [['id' => 'bbb', 'size' => 10], ['id' => 'aaa', 'size' => 10], ['id' => 'ccc', 'size' => 10]];
        check(array_column(TorrentList::sort($tied, 'size', false), 'id') === ['aaa', 'bbb', 'ccc'], 'tied values were not ascending by id forward');
        check(array_column(TorrentList::sort($tied, 'size', true), 'id') === ['aaa', 'bbb', 'ccc'], 'tied values were not ascending by id under --reverse too');
        // defect this catches: letting --reverse flip the id tie-break too would make `list --sort size --reverse`
        // nondeterministic across PHP versions whose usort() is not stable (pre-8.0).
        $rows = [['id' => 'z1', 'size' => 999, 'completed_at' => null], ['id' => 'a1', 'size' => 10, 'completed_at' => 500],
            ['id' => 'm1', 'size' => 20, 'completed_at' => 200]];
        check(array_column(TorrentList::sort($rows, 'completed_at', false), 'id') === ['a1', 'm1', 'z1'], 'a null field was not last forward');
        check(array_column(TorrentList::sort($rows, 'completed_at', true), 'id') === ['m1', 'a1', 'z1'], 'a null field jumped to the front under --reverse');
    },
    'TorrentChange::rate takes a bare integer as bytes/s, refuses a sub-byte rate distinctly from an explicit 0, and refuses above MAX_RATE' => function () {
        check(TorrentChange::rate(['down' => '1048576'], 'down') === 1048576, 'a bare integer without a unit suffix was refused');
        $tiny = torrentsEdgeErr(function () {
            TorrentChange::rate(['up' => '0.4'], 'up');
        });
        check($tiny !== null && strpos($tiny, 'less than 1 byte/s') !== false, '0.4 (rounds to 0) was accepted as a rate: ' . $tiny);
        check(TorrentChange::rate(['up' => '0'], 'up') === 0, 'an explicit 0 (no limit) was refused: ' . $tiny);
        $big = torrentsEdgeErr(function () {
            TorrentChange::rate(['down' => '2147483648'], 'down'); // MAX_RATE + 1
        });
        check($big !== null && strpos($big, 'more than 2147483647') !== false, 'one byte past MAX_RATE was accepted: ' . $big);
    },
    'TorrentChange::nudge\'s reannounce message prefers the torrent\'s own non-empty message over the tracker fallback' => function () {
        $id = str_repeat('a', 40);
        $row = function () use ($id) {
            return ['id' => $id, 'native' => $id, 'name' => 'T', 'state' => 'error', 'progress' => 1, 'size' => 0, 'done' => 0,
                'ratio' => 0, 'down_rate' => 0, 'up_rate' => 0, 'added_at' => 0, 'label' => '', 'path' => '', 'message' => 'custom torrent-level error'];
        };
        $client = new class ($row) implements TorrentClient {
            private $row;
            public function __construct($row)
            {
                $this->row = $row;
            }
            public function torrents()
            {
                return [call_user_func($this->row)];
            }
            public function details($n)
            {
                return [];
            }
            public function files($n)
            {
                return [];
            }
            public function filesOf(array $r)
            {
                return [];
            }
            public function add(array $i, array $o)
            {
                return [];
            }
            public function remove(array $n, $d)
            {
                return [];
            }
            public function pause(array $n)
            {
                return [];
            }
            public function resume(array $n)
            {
                return [];
            }
            public function stats()
            {
                return [];
            }
            public function traffic()
            {
                return [];
            }
            public function restart()
            {
                return [];
            }
            public function recheck(array $n)
            {
            }
            public function reannounce(array $n)
            {
            }
            public function setLimits($d, $u)
            {
                return [];
            }
            public function trackers($n)
            {
                // if trackerMessage() were consulted despite a non-empty own message, this is what it would show.
                return [['url' => 'https://t.example/announce', 'tier' => 0, 'status' => 'failing', 'message' => 'tracker says something else', 'seeds' => 0, 'leechers' => 0]];
            }
        };
        $out = TorrentChange::nudge($client, [$id], 'reannounce');
        check($out['records'][0]['message'] === 'custom torrent-level error', 'the torrent\'s own message was masked by the tracker fallback: ' . json_encode($out['records'][0]));
        // defect this catches: always calling trackerMessage() instead of checking the torrent's own message first
        // would show the stale tracker status ("tracker says something else") instead of the torrent's real,
        // current error.
    },
    'TorrentTrackers: an empty-but-syntactically-valid host is unknown; a label or a prose token redacts at exactly 16 characters, keeping 15; a scrape count is null only when negative or non-numeric' => function () {
        check(TorrentTrackers::host('http:///path') === 'unknown', 'a syntactically valid URL with an empty host was not "unknown": ' . TorrentTrackers::host('http:///path'));
        $fifteen = 'http://' . str_repeat('a', 14) . '1.example.com';
        $sixteen = 'http://' . str_repeat('a', 15) . '1.example.com';
        check(strpos(TorrentTrackers::host($fifteen), 'REDACTED') === false, 'a 15-character host label was redacted: ' . TorrentTrackers::host($fifteen));
        check(strpos(TorrentTrackers::host($sixteen), 'REDACTED') !== false, 'a 16-character host label was not redacted: ' . TorrentTrackers::host($sixteen));
        check(TorrentTrackers::message('key a1cdefghijklmno here') === 'key a1cdefghijklmno here', 'a 15-character prose token was redacted');
        check(TorrentTrackers::message('key a1cdefghijklmnop here') === 'key REDACTED here', 'a 16-character prose token was not redacted: ' . TorrentTrackers::message('key a1cdefghijklmnop here'));
        check(TorrentTrackers::count(-1) === null && TorrentTrackers::count('abc') === null && TorrentTrackers::count(0) === 0,
            'a negative/non-numeric scrape count was not null, or a real 0 was turned into null');
    },
    'Torrents::resolve caps ambiguous matches at 5, normalizes case and de-duplicates repeats, and refuses a native id that is not 40 hex characters' => function () {
        $seven = [];
        for ($i = 0; $i < 7; $i++) {
            $seven[] = ['id' => '12345678' . str_pad(dechex($i), 2, '0', STR_PAD_LEFT) . str_repeat('0', 30), 'native' => str_repeat('A', 40), 'name' => "T$i"];
        }
        $msg = torrentsEdgeErr(function () use ($seven) {
            Torrents::resolve($seven, ['12345678'], 8);
        });
        check($msg !== null && substr_count($msg, 'T') >= 5 && strpos($msg, 'T5') === false, '7 ambiguous matches were not capped at 5: ' . $msg);
        $one = [['id' => 'abc12345' . str_repeat('0', 32), 'native' => str_repeat('A', 40), 'name' => 'X']];
        check(count(Torrents::resolve($one, ['ABC12345', 'abc12345'], 8)) === 1, 'the same id in two cases was resolved twice');
        $badNative = [['id' => 'aaaaaaaa' . str_repeat('0', 32), 'native' => 'short', 'name' => 'Y']];
        $nativeErr = torrentsEdgeErr(function () use ($badNative) {
            Torrents::resolve($badNative, ['aaaaaaaa'], 8);
        });
        check($nativeErr !== null && strpos($nativeErr, 'not 40 hex characters') !== false, 'a non-40-hex native id from the client was accepted: ' . $nativeErr);
    },
    'Torrents::dir refuses a symlink whose target escapes $HOME even though its own path is inside it' => function () {
        $dir = testTempDir();
        mkdir("$dir/home", 0700, true);
        mkdir("$dir/outside", 0700, true);
        symlink("$dir/outside", "$dir/home/escape");
        $msg = torrentsEdgeErr(function () use ($dir) {
            Torrents::dir("$dir/home/escape", "$dir/home");
        });
        check($msg !== null && strpos($msg, 'not an existing folder inside your home') !== false, 'a symlink escaping $HOME via its target was accepted: ' . $msg);
        testRmTree($dir);
    },
];
