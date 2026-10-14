<?php
/** Helpers for the rclone tests (tests/StorageRclone*Test.php): a fixture home and a runner for tools/storage/rclone. */
require_once __DIR__ . '/harness.php';

const RC_SECRET = 'obscuredButStillSecret-9QzX';

/** A home with data, a plain rclone config holding a secret, and the fake's scripted answers. */
function rcFixture(array $over = [], array $fakes = ST_FAKES)
{
    $fx = stFixture([], $fakes);
    mkdir($fx['home'] . '/data/tv', 0700, true);
    mkdir($fx['home'] . '/.config/rclone', 0700, true);
    file_put_contents($fx['home'] . '/data/tv/new.mkv', str_repeat('n', 7000));
    file_put_contents($fx['home'] . '/data/tv/changed.mkv', str_repeat('c', 3000));
    $conf = $fx['home'] . '/.config/rclone/rclone.conf';
    file_put_contents($conf, "[gdrive]\ntype = drive\ntoken = {\"access_token\":\"" . RC_SECRET . "\"}\n\n[box]\ntype = sftp\npass = "
        . RC_SECRET . "\n");
    $state = $over + ['config' => $conf, 'remotes' => ['gdrive' => 'drive', 'box' => 'sftp'],
        'check' => ['= same.mkv', '+ new.mkv', '* changed.mkv', '- old1.mkv', '- old2.mkv'],
        'after' => ['sync' => ['= same.mkv', '= new.mkv', '= changed.mkv'], 'copy' => ['= same.mkv', '= new.mkv', '* changed.mkv',
            '- old1.mkv', '- old2.mkv']],
        'lsf' => ['gdrive:tv' => [['2026-10-01 10:00:00', '-1', 'Show/'], ['2026-10-02 11:00:00', '1234', 'a b.mkv'],
            ['2026-10-03 12:00:00', '99', 'c.nfo']]],
        'size' => ['gdrive:tv' => ['count' => 3, 'bytes' => 1333, 'sizeless' => 0], $fx['home'] . '/data/tv' => ['count' => 3,
            'bytes' => 10000, 'sizeless' => 0]],
        'size_after_move' => ['count' => 0, 'bytes' => 0, 'sizeless' => 0]];
    file_put_contents($fx['dir'] . '/state.json', json_encode($state, JSON_UNESCAPED_SLASHES));
    return $fx;
}

function rcRun(array $fx, array $args, $family = 'generic')
{
    list($code, $out) = stRun($fx, 'storage/rclone/rclone.php', $args, ['rcloneMountinfo' => $fx['mountinfo'],
        'rcloneProcRoot' => $fx['proc'], 'rcloneFamily' => $family]);
    return [$code, stLines($out), $out];
}
