<?php
/** tools/storage/rclone against a fake rclone (tests/fixtures/storage/bin/rclone) that logs every argv. */
require_once __DIR__ . '/fixtures/storage/rclone.php';

return [
    'rclone list: names and types only, never config values; every call says --ask-password=false; an encrypted config stops before any remote call' => function () {
        $fx = rcFixture();
        try {
            list($code, $l, $out) = rcRun($fx, ['list']);
            check($code === 0 && $l[0] === ['remote' => 'gdrive:', 'type' => 'drive', 'description' => ''] && $l[1]['remote'] === 'box:',
                'remotes: ' . $out);
            check(strpos($out, RC_SECRET) === false, 'a config value reached the output');
            check(in_array('listremotes --long --ask-password=false', stCalls($fx, 'rclone'), true), 'listremotes call: '
                . json_encode(stCalls($fx, 'rclone')));
            file_put_contents($fx['home'] . '/.config/rclone/rclone.conf',
                "# Encrypted rclone configuration File\n\nRCLONE_ENCRYPT_V0:\nZm9vYmFy" . RC_SECRET . "\n");
            stClearLog($fx);
            foreach ([['list'], ['show', 'gdrive:tv'], ['sync', $fx['home'] . '/data/tv', 'gdrive:tv', '--expect', '3']] as $args) {
                list($code, $l, $out) = rcRun($fx, $args);
                check($code === 1 && strpos(end($l)['error'], 'encrypted') !== false && strpos(end($l)['hint'],
                    'never typed into the chat') !== false, 'encrypted config not refused: ' . $out);
            }
            check(stCalls($fx, 'rclone') === array_fill(0, 3, 'config file --ask-password=false'),
                'a remote was contacted with an encrypted config: ' . json_encode(stCalls($fx, 'rclone')));
            list($code, $l) = rcRun($fx, ['stats']);
            check($code === 0 && $l[0]['config_encrypted'] === true && $l[0]['version'] === '1.69.1', 'stats must still report: '
                . json_encode($l));
        } finally {
            stDone($fx);
        }
    },
    'rclone check: deletions first, counts and the exact expect values; show is bounded; places are checked' => function () {
        $fx = rcFixture();
        try {
            $src = $fx['home'] . '/data/tv';
            list($code, $l, $out) = rcRun($fx, ['check', $src, 'gdrive:tv']);
            $st = end($l);
            check($code === 0 && $l[0]['change'] === 'extra_on_dst' && $l[2]['change'] === 'replace' && $l[3]['change'] === 'copy',
                'order: ' . $out);
            check($st['to_copy'] === 1 && $st['to_replace'] === 1 && $st['extra_on_dst'] === 2 && $st['expect_sync'] === 3
                && $st['expect_move'] === 3 && $st['copy_bytes'] === 10000, 'counts: ' . $out);
            check(stCalls($fx, 'rclone')[2] === "sync --dry-run --retries 1 --combined - --ask-password=false -- $src gdrive:tv",
                'check argv: ' . json_encode(stCalls($fx, 'rclone')));
            list(, $l) = rcRun($fx, ['check', $src, 'gdrive:tv', '--one-way']);
            check(end($l)['expect_sync'] === null && end($l)['extra_on_dst'] === null, 'one-way must not offer a sync value');
            list($code, $l) = rcRun($fx, ['show', 'gdrive:tv', '--limit', '2', '--size']);
            check($code === 0 && count($l) === 3 && $l[0] === ['name' => 'Show', 'is_dir' => true, 'bytes' => null,
                'modified' => '2026-10-01 10:00:00'] && $l[1]['name'] === 'a b.mkv'
                && end($l)['listed_all'] === false && end($l)['total_bytes'] === 1333 && end($l)['remote_free_bytes'] === 549755813888,
                'show: ' . json_encode($l));
            foreach ([[':sftp,host=x,pass=y:/a', 'gdrive:tv'], ['/etc', 'gdrive:x'], ['relative/path', 'gdrive:x'], ['--config=/x',
                'gdrive:x']] as $bad) {
                stClearLog($fx);
                list($code) = rcRun($fx, array_merge(['check'], $bad));
                check($code !== 0 && !in_array('check', array_map(function ($c) {
                    return explode(' ', $c)[0];
                }, stCalls($fx, 'rclone')), true), 'refused place reached rclone: ' . json_encode($bad));
            }
        } finally {
            stDone($fx);
        }
    },
    'rclone sync and move: never without the matching --expect (no sync or move call at all); sync passes --max-delete and reads back' => function () {
        $fx = rcFixture();
        try {
            $src = $fx['home'] . '/data/tv';
            foreach ([['sync', $src, 'gdrive:tv'], ['sync', $src, 'gdrive:tv', '--expect', '2'], ['sync', $src, 'gdrive:tv', '--expect',
                'all'], ['move', $src, 'gdrive:tv', '--expect', '9']] as $args) {
                list($code, $l, $out) = rcRun($fx, $args);
                $verbs = array_map(function ($c) {
                    return strpos($c, 'sync --dry-run ') === 0 ? 'plan' : explode(' ', $c)[0]; // the plan is a dry-run sync
                }, stCalls($fx, 'rclone'));
                check($code === 1 && strpos(end($l)['error'], 'nothing changed') === 0 && !in_array('sync', $verbs, true)
                    && !in_array('move', $verbs, true), 'ran without a matching --expect: ' . $out);
            }
            stClearLog($fx);
            list($code, $l, $out) = rcRun($fx, ['sync', $src, 'gdrive:tv', '--expect', '3']);
            check($code === 0 && in_array("sync --max-delete 2 --retries 1 --combined - --ask-password=false -- $src gdrive:tv",
                stCalls($fx, 'rclone'), true),
                'sync argv: ' . json_encode(stCalls($fx, 'rclone')));
            check($l[0]['deleted'] === 2 && $l[0]['replaced'] === 1 && end($l)['verified'] === true, 'sync result: ' . $out);
        } finally {
            stDone($fx);
        }
        $fx = rcFixture();
        try {
            $src = $fx['home'] . '/data/tv';
            list($code, $l, $out) = rcRun($fx, ['move', $src, 'gdrive:tv', '--expect', '3']);
            check($code === 0 && in_array("move --ask-password=false -- $src gdrive:tv", stCalls($fx, 'rclone'), true)
                && $l[0]['left_in_src'] === 0 && end($l)['verified'] === true, 'move: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'rclone copy: --ignore-existing unless --replace, and it reports what it left alone' => function () {
        $fx = rcFixture();
        try {
            $src = $fx['home'] . '/data/tv';
            list($code, $l, $out) = rcRun($fx, ['copy', $src, 'gdrive:tv']);
            check($code === 0 && in_array("copy --ignore-existing --ask-password=false -- $src gdrive:tv", stCalls($fx, 'rclone'), true),
                'copy argv: ' . json_encode(stCalls($fx, 'rclone')));
            check($l[0]['differs_on_dst'] === 1 && strpos(end($l)['note'], '--replace') !== false, 'copy result: ' . $out);
            stClearLog($fx);
            rcRun($fx, ['copy', $src, 'gdrive:tv', '--replace']);
            check(in_array("copy --ask-password=false -- $src gdrive:tv", stCalls($fx, 'rclone'), true), '--replace argv: '
                . json_encode(stCalls($fx, 'rclone')));
        } finally {
            stDone($fx);
        }
    },
];
