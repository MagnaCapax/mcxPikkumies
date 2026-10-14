<?php
/**
 * tools/storage/rclone: the plan and the change use one comparison rule, and sync stops at the planned count
 * (review 2026-10-14 findings 1, 3 and 12). The oracle is the fake rclone's own record of what the real sync
 * applied with sync's rule (state "applied"), never the unit's arithmetic.
 */
require_once __DIR__ . '/fixtures/storage/harness.php';

/** A home with one source folder; $state is the fake's script (see tests/fixtures/storage/bin/rclone). */
function rpFixture(array $state)
{
    $fx = stFixture([]);
    mkdir($fx['home'] . '/data/tv', 0700, true);
    mkdir($fx['home'] . '/.config/rclone', 0700, true);
    $conf = $fx['home'] . '/.config/rclone/rclone.conf';
    file_put_contents($conf, "[crypt]\ntype = crypt\n");
    file_put_contents($fx['dir'] . '/state.json', json_encode($state + ['config' => $conf, 'remotes' => ['crypt' => 'crypt']]));
    return $fx;
}

function rpRun(array $fx, array $args)
{
    list($code, $out) = stRun($fx, 'storage/rclone/rclone.php', $args, ['rcloneMountinfo' => $fx['mountinfo'],
        'rcloneProcRoot' => $fx['proc']]);
    return [$code, stLines($out), $out];
}

function rpVerbs(array $fx)
{
    return array_map(function ($c) {
        return implode(' ', array_slice(explode(' ', $c), 0, 2));
    }, stCalls($fx, 'rclone'));
}

return [
    'rclone sync to a remote with no common hash: the expect value from check covers every file the real sync replaces' => function () {
        // check (size+hash, no common hash: size only) calls a.mkv identical; sync (size+modtime) replaces it.
        $fx = rpFixture(['check' => ['= a.mkv', '= b.mkv', '- old.mkv'], 'rule' => ['* a.mkv', '= b.mkv', '- old.mkv'],
            'after' => ['sync' => ['= a.mkv', '= b.mkv']]]);
        try {
            $src = $fx['home'] . '/data/tv';
            list($code, $l, $out) = rpRun($fx, ['check', $src, 'crypt:tv']);
            $expect = end($l)['expect_sync'];
            check($code === 0 && is_int($expect), 'check: ' . $out);
            list($code, $l, $out) = rpRun($fx, ['sync', $src, 'crypt:tv', '--expect', (string) $expect]);
            $applied = stState($fx)['applied'];
            check($code === 0 && count($applied) <= $expect, "approved $expect, the sync deleted or replaced " . json_encode($applied)
                . ': ' . $out);
            check(count($applied) === 2 && $l[0]['replaced'] === 1 && $l[0]['deleted'] === 1, 'sync record: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'rclone sync: stopped when the run finds one more replace than the plan the user approved' => function () {
        $fx = rpFixture(['check' => ['* a.mkv', '= b.mkv'], 'rule' => ['* a.mkv', '= b.mkv'], 'run' => ['* a.mkv', '* b.mkv']]);
        try {
            $src = $fx['home'] . '/data/tv';
            list($code, $l, $out) = rpRun($fx, ['sync', $src, 'crypt:tv', '--expect', '1']);
            check($code === 1 && strpos(end($l)['error'], 'stopped') !== false && in_array('sync --max-delete', rpVerbs($fx), true),
                'sync: ' . $out);
        } finally {
            stDone($fx);
        }
    },
    'rclone plan: read at nice 19, idle I/O and a time limit; no full-hash check inside sync or move; the transfer has no time limit' => function () {
        $fx = rpFixture(['rule' => ['* a.mkv', '+ new.mkv'], 'after' => ['sync' => ['= a.mkv', '= new.mkv']]]);
        try {
            $src = $fx['home'] . '/data/tv';
            rpRun($fx, ['check', $src, 'crypt:tv']);
            $nice = stCalls($fx, 'nice');
            check(count($nice) === 1 && strpos($nice[0],
                '-n 19 ionice -c3 timeout 3600 rclone sync --dry-run --retries 1 --combined -') === 0, 'plan: ' . json_encode($nice));
            stClearLog($fx);
            list($code, , $out) = rpRun($fx, ['sync', $src, 'crypt:tv', '--expect', '1']);
            $verbs = rpVerbs($fx);
            check($code === 0 && !in_array('check --combined', $verbs, true) && count(array_keys($verbs, 'sync --dry-run', true)) === 2,
                'calls: ' . json_encode($verbs) . $out);
            check(in_array("-n 19 ionice -c3 rclone sync --max-delete 0 --retries 1 --combined - --ask-password=false -- $src crypt:tv",
                stCalls($fx, 'nice'), true),
                'transfer: ' . json_encode(stCalls($fx, 'nice')));
        } finally {
            stDone($fx);
        }
    },
    'rclone older than 1.66: check still previews (by hash) but offers no expect value, and sync and move refuse without running' => function () {
        $fx = rpFixture(['version' => '1.60.1', 'check' => ['= a.mkv', '- old.mkv']]);
        try {
            $src = $fx['home'] . '/data/tv';
            list($code, $l, $out) = rpRun($fx, ['check', $src, 'crypt:tv']);
            check($code === 0 && end($l)['expect_sync'] === null && end($l)['expect_move'] === null && strpos(end($l)['note'],
                '1.66') !== false
                && in_array('check --combined', rpVerbs($fx), true), 'check: ' . $out);
            foreach (['sync', 'move'] as $verb) {
                stClearLog($fx);
                list($code, $l, $out) = rpRun($fx, [$verb, $src, 'crypt:tv', '--expect', '1']);
                check($code === 1 && strpos(end($l)['error'], 'nothing changed') === 0 && !array_intersect(['sync --max-delete',
                    'move --ask-password=false'], rpVerbs($fx))
                    && !in_array($verb, array_map(function ($v) {
                        return explode(' ', $v)[0];
                    }, rpVerbs($fx)), true), "$verb on 1.60: " . $out);
            }
        } finally {
            stDone($fx);
        }
    },
    'rclone: a config rclone cannot decrypt is the encrypted-config error, never rclone\'s "set RCLONE_CONFIG_PASS" text' => function () {
        $fx = rpFixture(['decrypt_fail' => true, 'check' => []]);
        try {
            list($code, $l, $out) = rpRun($fx, ['list']);
            check($code === 1 && strpos(end($l)['error'], 'encrypted') !== false && strpos($out, 'RCLONE_CONFIG_PASS') === false
                && strpos(end($l)['hint'], 'never typed into the chat') !== false, 'list: ' . $out);
        } finally {
            stDone($fx);
        }
    },
];
