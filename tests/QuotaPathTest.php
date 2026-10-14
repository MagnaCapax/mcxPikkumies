<?php
/**
 * lib/Quota.php finds `quota` in PATH, as storage stats' live check does (StorageStats::liveProblem), and runs the one it
 * found. It used to look only at fixed paths, then let the shell pick `quota` from PATH: the two could disagree.
 */
require_once __DIR__ . '/fixtures/storage/harness.php';

return [
    'quota: the live figure comes from the quota found in PATH; storage stats calls it live (no ~/.quota needed)' => function () {
        $head = "Disk quotas for user u (uid 1001):\n     Filesystem   space   quota   limit   grace   files   quota   limit   grace\n";
        $fx = stFixture(['quota_out' => $head . "       /dev/md2    100G   1024G   1280G   5   0   0\n", 'quota_err' => '',
            'quota_code' => 0]);
        try {
            list($code, $out) = stRun($fx, 'storage/storage/storage.php', ['stats'], ['storageLocalDir' => $fx['local']]);
            $r = stLines($out)[0];
            check($code === 0 && $r['quota_source'] === 'quota -s' && $r['live_quota'] === 'ok'
                && $r['quota_free_bytes'] === 924 * 1073741824, 'live quota not used: ' . $out);
            $timeout = implode("\n", stCalls($fx, 'timeout'));
            check(in_array('-s', stCalls($fx, 'quota'), true) && strpos($timeout, $fx['bin'] . '/quota -s') !== false,
                'not the quota from PATH, or not under timeout: ' . json_encode(stLog($fx)));
        } finally {
            stDone($fx);
        }
    },
];
