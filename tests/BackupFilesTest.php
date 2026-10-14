<?php
/**
 * tools/backup `show --files` paging, borg's file records (types, hard links) and the restic backup id, shaped from the
 * live smoke run of 2026-10-14 against restic 0.14.0 / 0.19.1 and borg 1.2.4 / 1.4.0.
 */
require_once __DIR__ . '/fixtures/backup/harness.php';

/** Five entries in each tool's own format (restic ls nodes, borg --json-lines items). */
function bfFiles($tool)
{
    $out = [];
    foreach (['a', 'b', 'c', 'd', 'e'] as $i => $n) {
        $out[] = $tool === 'borg' ? ['type' => '-', 'mode' => '-rw-r--r--', 'path' => "home/u/data/$n.txt", 'size' => $i + 1,
            'mtime' => '2026-10-01T02:00:00.000000']
            : ['name' => "$n.txt", 'type' => 'file', 'path' => "/home/u/data/$n.txt", 'size' => $i + 1, 'mtime' => '2026-10-01T02:00:00Z'];
    }
    return $out;
}

return [
    'backup show --files: --limit 0 lists everything, --offset pages on with next_offset, the last page is not truncated (restic, borg)'
        => function () {
            foreach (['restic' => 'aaaaaaaa', 'borg' => 'box-1'] as $tool => $id) {
                $fx = bkFixture($tool, ['files' => bfFiles($tool)]);
                try {
                    $files = function (array $more) use ($fx, $tool, $id) {
                        list($code, $l, $out) = bkRun($fx, $tool, array_merge(['show', $id, '--files'], $more));
                        check($code === 0, "$tool " . implode(' ', $more) . ": $out");
                        $st = array_pop($l);
                        return [array_column($l, 'path'), $st];
                    };
                    list($p, $st) = $files(['--limit', '0']);
                    check(count($p) === 5 && $st['listed_all'] === true && empty($st['truncated']),
                        "$tool --limit 0: " . json_encode([$p, $st]));
                    list($p, $st) = $files(['--limit', '2']);
                    check($p === ['/home/u/data/a.txt', '/home/u/data/b.txt'] && $st['truncated'] === true && $st['next_offset'] === 2,
                        "$tool --limit 2: " . json_encode([$p, $st]));
                    list($p, $st) = $files(['--limit', '2', '--offset', '2']);
                    check($p === ['/home/u/data/c.txt', '/home/u/data/d.txt'] && $st['truncated'] === true && $st['next_offset'] === 4,
                        "$tool --limit 2 --offset 2: " . json_encode([$p, $st]));
                    list($p, $st) = $files(['--limit', '2', '--offset', '4']);
                    check($p === ['/home/u/data/e.txt'] && empty($st['truncated']) && $st['listed_all'] === true,
                        "$tool last page: " . json_encode([$p, $st]));
                } finally {
                    stDone($fx);
                }
            }
        },
    'borg show --files: a hard link\'s second name has bytes null and link_to (never 0, never counted twice); types are restic\'s words'
        => function () {
            // borg 1.2.4 and 1.4.0 list the second name as "-" with mode "h...", size 0 and source = the first name (smoke 2026-10-14).
            $t = '2026-10-14T12:19:30.000000';
            $files = [['type' => 'd', 'mode' => 'drwxr-xr-x', 'path' => 'home/u/data/sub', 'size' => 0, 'mtime' => $t],
                ['type' => '-', 'mode' => '-rw-r--r--', 'path' => 'home/u/data/sub/big-hardlink.bin', 'size' => 3000000, 'source' => '',
                    'mtime' => $t],
                ['type' => '-', 'mode' => 'hrw-r--r--', 'path' => 'home/u/data/big.bin', 'size' => 0,
                    'source' => 'home/u/data/sub/big-hardlink.bin', 'mtime' => $t],
                ['type' => 'l', 'mode' => 'lrwxrwxrwx', 'path' => 'home/u/data/link', 'size' => 0, 'source' => 'sub',
                    'mtime' => $t]];
            $fx = bkFixture('borg', ['files' => $files]);
            try {
                list($code, $l, $out) = bkRun($fx, 'borg', ['show', 'box-1', '--files']);
                $st = array_pop($l);
                check($code === 0 && array_column($l, 'type') === ['dir', 'file', 'file', 'symlink'], 'types: ' . $out);
                check($l[2]['bytes'] === null && $l[2]['hardlink'] === true && $l[2]['link_to'] === '/home/u/data/sub/big-hardlink.bin'
                    && $l[1]['bytes'] === 3000000 && !isset($l[1]['hardlink']) && !isset($l[3]['hardlink']), 'hard link: ' . $out);
                check(array_sum(array_filter(array_column($l, 'bytes'))) === 3000000 && $l[0]['bytes'] === null
                    && strpos($st['hardlink_note'], 'link_to') !== false, 'bytes counted twice or note missing: ' . $out);
            } finally {
                stDone($fx);
            }
        },
    'restic backup: id is the short id whether restic prints the short (0.14.0) or the full id (0.19.1)' => function () {
        $full = 'b1bbba8e4f1706e8d44c359c04f744b79e9bb0709fd5a30746c39033151e2157'; // the 0.19.1 summary of the smoke run
        foreach ([$full => ['b1bbba8e', $full], '19b70ce8' => ['19b70ce8', null]] as $printed => $want) {
            $fx = bkFixture('restic', ['backup_id' => $printed]);
            try {
                list($code, $l, $out) = bkRun($fx, 'restic', ['backup', $fx['home'] . '/data']);
                check($code === 0 && $l[0]['id'] === $want[0] && $l[0]['snapshot_id'] === $want[1], "printed $printed: $out");
            } finally {
                stDone($fx);
            }
        }
    },
];
