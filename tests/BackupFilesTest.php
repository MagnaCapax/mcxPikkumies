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
            // Verbatim `borg list --json-lines` of borg 1.2.4 (TZ=UTC0) on data with a hard link and a symlink; borg 1.4.0 printed
            // the same keys and values but the times (live run 2026-10-14). The second name is "-", mode "h...", size 0, source set.
            $files = array_map(function ($l) {
                return json_decode($l, true);
            }, file(__DIR__ . '/fixtures/backup/borg-1.2.4-list-json-lines-hardlink.jsonl', FILE_IGNORE_NEW_LINES));
            $fx = bkFixture('borg', ['files' => $files]);
            try {
                list($code, $l, $out) = bkRun($fx, 'borg', ['show', 'box-1', '--files']);
                $st = array_pop($l);
                check($code === 0 && array_column($l, 'type') === ['dir', 'dir', 'file', 'file', 'file', 'symlink'], 'types: ' . $out);
                check($l[4]['bytes'] === null && $l[4]['hardlink'] === true && $l[4]['link_to'] === '/w/home/u/data/sub/big.bin'
                    && $l[2]['bytes'] === 3000000 && !isset($l[2]['hardlink']) && !isset($l[5]['hardlink']), 'hard link: ' . $out);
                $fileBytes = array_sum(array_column(array_filter($l, function ($r) {
                    return $r['type'] === 'file';
                }), 'bytes'));
                // 3000006 is what borg 1.2.4 itself gave as the archive's original_size in the same run
                check($fileBytes === 3000006 && $l[0]['bytes'] === null && strpos($st['hardlink_note'], 'link_to') !== false,
                    'bytes counted twice or note missing: ' . $out);
                check($l[3]['modified_at'] === 1790000000, 'mtime: ' . $out); // the file was touched to 2026-09-21T14:13:20Z
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
