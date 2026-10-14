<?php
/**
 * The shared extra verbs (tools/torrent-clients/lib: TorrentList, TorrentChange, TorrentTrackers) through the fake client of
 * TorrentsTest.php, whose helpers this file uses (tests/run.php loads TorrentsTest.php first: name order).
 */
return [
    'torrents: list --sort orders before the limit, nulls last, --reverse flips; bad field or bare --reverse refused' => function () {
        $dir = torrentsFixture();
        torrentsEdit($dir, function (&$s) {
            foreach ([[3000, 1700000300, 'Alpha'], [5000, null, 'beta'], [100, 1700000100, 'Gamma']] as $i => $v) {
                list($s['torrents'][$i]['size'], $s['torrents'][$i]['completed_at'], $s['torrents'][$i]['name']) = $v;
            }
        });
        $names = function ($out) {
            return array_column(array_filter(cliLines($out), function ($r) {
                return isset($r['id']);
            }), 'name');
        };
        list($code, $out) = runPhp("$dir/fake.php", ['list', '--sort', 'size', '--limit', '2']);
        check($code === 0 && $names($out) === ['beta', 'Alpha'] && cliLines($out)[2]['total'] === 3, 'biggest first, then the limit: ' .
            $out);
        list(, $out) = runPhp("$dir/fake.php", ['list', '--sort', 'completed_at']);
        check($names($out) === ['Alpha', 'Gamma', 'beta'], 'newest first, null last: ' . $out);
        list(, $out) = runPhp("$dir/fake.php", ['list', '--sort', 'completed_at', '--reverse']);
        check($names($out) === ['Gamma', 'Alpha', 'beta'], 'reverse keeps null last: ' . $out);
        list(, $out) = runPhp("$dir/fake.php", ['list', '--sort', 'NAME']);
        check($names($out) === ['Alpha', 'beta', 'Gamma'], 'names A to Z, case-insensitive: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['list', '--sort', 'peers']);
        check($code === 1 && strpos($out, 'completed_at') !== false, 'an unknown field must list the allowed ones: ' . $out);
        list($code) = runPhp("$dir/fake.php", ['list', '--reverse']);
        check($code === 1, '--reverse without --sort accepted');
        list($code) = runPhp("$dir/fake.php", ['list', '--reverse=no', '--sort', 'size']);
        check($code === 2, '--reverse=no accepted as a flag');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: recheck and reannounce send exactly the named ids, never a name or all; one bad id changes nothing' => function () {
        $dir = torrentsFixture();
        torrentsEdit($dir, function (&$s) {
            $s['torrents'][0]['done'] = 3000;
        });
        list($code, $out) = runPhp("$dir/fake.php", ['recheck', 'aaaaaaaa', str_repeat('b', 40)]);
        $l = cliLines($out);
        check($code === 0 && torrentsCalls($dir) === [['recheck', [str_repeat('A', 40), str_repeat('B', 40)]]], 'recheck ids: ' . $out);
        check($l[0]['done_before'] === 3000 && $l[0]['result'] === 'recheck requested' && $l[0]['state'] === 'seeding', 'recheck record: ' .
            $out);
        foreach ([['recheck', 'Alpha'], ['recheck', 'ab'], ['reannounce', 'aaaaaaaa', 'nope1234'], ['reannounce'], ['recheck',
            'all']] as $args) {
            list($code) = runPhp("$dir/fake.php", $args);
            check($code === 1 && count(torrentsCalls($dir)) === 1, implode(' ', $args) . ' reached the client');
        }
        list($code, $out) = runPhp("$dir/fake.php", ['reannounce', 'abcccccc']);
        check($code === 0 && torrentsCalls($dir)[1] === ['reannounce', ['AB' . str_repeat('C', 38)]] &&
            cliLines($out)[0]['result'] === 'announce requested', 'reannounce: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: limit reads, sets with units, reads back with was and asked; a bad rate changes nothing' => function () {
        $dir = torrentsFixture();
        list($code, $out) = runPhp("$dir/fake.php", ['limit']);
        check($code === 0 && cliLines($out)[0] === ['down_limit' => 0, 'up_limit' => 0] && torrentsCalls($dir) === [], 'read: ' . $out);
        list($code, $out) = runPhp("$dir/fake.php", ['limit', '--down', '10MiB/s', '--up=1000']);
        $l = cliLines($out);
        check($code === 0 && torrentsCalls($dir) === [['limit', 10485760, 1000]], 'units: ' . json_encode(torrentsCalls($dir)));
        check($l[0]['down_limit'] === 10485760 && $l[0]['up_limit'] === 1024 && $l[0]['was'] === ['down_limit' => 0, 'up_limit' => 0]
            && $l[0]['asked'] === ['down_limit' => 10485760, 'up_limit' => 1000] && $l[1]['verified'] === true, 'read-back within a KiB: ' .
                $out);
        list($code, $out) = runPhp("$dir/fake.php", ['limit', '--up', '0']);
        check($code === 0 && cliLines($out)[0]['up_limit'] === 0 && cliLines($out)[0]['down_limit'] === 10485760,
            '0 = no limit, down left alone: ' . $out);
        foreach ([['--down', '10MB/s'], ['--down', '-1'], ['--up'], ['--down', 'fast'], ['--down', '3GiB/s'], ['--dwn', '1']] as $bad) {
            list($code) = runPhp("$dir/fake.php", array_merge(['limit'], $bad));
            check($code !== 0 && count(torrentsCalls($dir)) === 2, implode(' ', $bad) . ' was accepted');
        }
        list(, $out) = runPhp("$dir/fake.php", ['limit', '--down', '512KiB']);
        check(cliLines($out)[0]['down_limit'] === 524288, 'KiB without /s: ' . $out);
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrents: show --trackers prints scheme, host and port only: no passkey in a path, a query or a host label' => function () {
        $dir = torrentsFixture();
        list($code, $out) = runPhp("$dir/fake.php", ['show', 'aaaaaaaa', '--trackers']);
        $l = cliLines($out);
        check($code === 0 && count($l) === 4 && $l[1]['torrent'] === str_repeat('a', 40), 'records: ' . $out);
        check($l[1]['tracker'] === 'https://t.example:8443' && $l[1]['status'] === 'working' && $l[1]['seeds'] === 3 &&
            $l[1]['leechers'] === null, 'tracker record: ' . $out);
        check($l[2]['tracker'] === 'udp://t2.example:6969',
            'host form (lower case, an all-letter key in the path dropped with the path): ' . $out);
        check($l[2]['status'] === null && $l[2]['tier'] === null, 'an unknown status word must become null: ' . $out);
        foreach (['f00dfeed', 'a1b2c3d4e5', 'abcdefghijklmnop', 'passkey=', 'announce'] as $secret) {
            check(stripos($out, $secret) === false, "printed $secret: $out");
        }
        check(TorrentTrackers::host('http://user:pw@x.example/a?b') === 'http://x.example' &&
            TorrentTrackers::host('not a url') === 'unknown'
            && TorrentTrackers::host('https://ab12cd34ef56ab78cd90.tracker.example/announce') === 'https://REDACTED.tracker.example',
                'host(): user part, junk, key in a label');
        exec('rm -rf ' . escapeshellarg($dir));
    },
    'torrent units: the guard hook gives no answer to the non-deleting USAGE examples; remove --with-data still asks' => function () {
        $hook = dirname(__DIR__) . '/pikkumies/hooks/pre_tool_call/guard-destructive.php';
        $ask = function ($cmd) use ($hook) {
            list(, $out) = runPhp($hook, [], json_encode(['tool_name' => 'terminal', 'tool_input' => ['command' => $cmd]]));
            return trim($out);
        };
        $n = 0;
        foreach (['rtorrent', 'qbittorrent', 'deluge'] as $unit) {
            foreach (explode("\n", (string) file_get_contents(dirname(__DIR__) .
                "/pikkumies/tools/torrent-clients/$unit/USAGE.md")) as $line) {
                if (preg_match('/^php \$[A-Z] (\S+)(.*?)(\s+#.*)?$/', $line, $m)) {
                    $cmd = "php ~/pikkumies/tools/torrent-clients/$unit/$unit.php " . $m[1] . $m[2];
                    $answer = $ask($cmd);
                    if ($m[1] === 'remove' && strpos($m[2], '--with-data') !== false) {
                        check(strpos($answer, '"approve"') !== false, "the delete was not asked: $cmd => $answer");
                        continue;
                    }
                    check($answer === '', "the guard answered for: $cmd => $answer");
                    $n += in_array($m[1], ['recheck', 'reannounce', 'limit'], true) || strpos($m[2], '--trackers') !== false ||
                        strpos($m[2], '--sort') !== false;
                }
            }
        }
        check($n === 21, "expected 7 new-verb examples per unit, found $n");
    },
];
