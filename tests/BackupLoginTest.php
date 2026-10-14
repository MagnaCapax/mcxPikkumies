<?php
/**
 * tools/backup login at a real pseudo-terminal (review 2026-10-14 findings 2 and 5): a login the repository does
 * not accept never touches the stored one, an accepted one keeps a different old password, and a repository URL
 * with a password never reaches output. The oracle is the files on disk and the fake's own password check.
 */
require_once __DIR__ . '/fixtures/backup/harness.php';

function blStored(array $fx, $tool)
{
    $repo = json_decode((string) @file_get_contents($fx['local'] . "/backup/$tool.json"), true);
    return [(string) @file_get_contents($fx['secrets'] . "/$tool"), isset($repo['repo']) ? $repo['repo'] : null, glob($fx['secrets']
        . "/$tool.*")];
}

return [
    'backup login: a mistyped password for a working repository leaves the stored password and repository as they were (restic, borg)' => function () {
        foreach (['restic', 'borg'] as $tool) {
            $fx = bkFixture($tool);
            try {
                list($code, $l, $out) = bkLogin($fx, $tool, 'sftp:me@box:other', 'typo-not-it');
                list($secret, $repo, $extra) = blStored($fx, $tool);
                check($code === 1 && strpos(end($l)['error'], 'refused') !== false, "$tool login: " . $out);
                check($secret === BK_ORACLE && $repo === 'sftp:me@box:repo' && $extra === [], "$tool: the stored login changed: "
                    . json_encode([$repo, $extra]));
                list($code) = bkRun($fx, $tool, ['list']);
                check($code === 0, "$tool: the stored login no longer works");
            } finally {
                stDone($fx);
            }
        }
    },
    'backup login: a login that cannot be checked (network) never replaces a stored one; an accepted one keeps the old password beside it' => function () {
        $fx = bkFixture('restic', ['cat_err' => 'Fatal: unable to open config file: ssh: connect to host box port 22: Connection refused']);
        try {
            list($code, $l, $out) = bkLogin($fx, 'restic', 'sftp:me@box:other', BK_ORACLE);
            check($code === 1 && strpos(end($l)['error'], 'unchanged') !== false && blStored($fx, 'restic')[1] === 'sftp:me@box:repo',
                'unchecked: ' . $out);
        } finally {
            stDone($fx);
        }
        $fx = bkFixture('restic', ['secret' => 'New-Repo-Pass-2']);
        try {
            list($code, $l, $out) = bkLogin($fx, 'restic', 'sftp:me@box:other', 'New-Repo-Pass-2');
            list($secret, $repo, $extra) = blStored($fx, 'restic');
            check($code === 0 && end($l)['verified'] === true && $secret === 'New-Repo-Pass-2' && $repo === 'sftp:me@box:other',
                'accepted: ' . $out);
            check(count($extra) === 1 && strpos($extra[0], '.prev-') !== false && file_get_contents($extra[0]) === BK_ORACLE
                && substr(sprintf('%o', fileperms($extra[0])), -3) === '600', 'the old password was not kept: ' . json_encode($extra));
        } finally {
            stDone($fx);
        }
    },
    'restic: a REST repository URL with a password never reaches output, also with an unencoded @ in the password' => function () {
        foreach (['rest:https://alice:Pw-Or4cle-1@host:8000/repo', 'rest:https://alice:Pw%40Or4cle-1@host:8000/',
            'rest:https://alice:Pw@Or4cle-1@host:8000/r'] as $url) {
            $fx = bkFixture('restic', ['secret' => 'S3cret-typed']);
            try {
                list($code, , $out) = bkLogin($fx, 'restic', $url, 'S3cret-typed');
                check($code === 0 && strpos($out, 'Or4cle') === false && strpos($out, 'alice:REDACTED@host') !== false,
                    "login with $url: " . $out);
                list($code, $l, $out) = bkRun($fx, 'restic', ['stats']);
                check($code === 0 && strpos($out, 'Or4cle') === false && strpos($l[0]['repo'], 'host:8000') !== false,
                    "stats with $url: " . $out);
                file_put_contents($fx['dir'] . '/state.json',
                    json_encode(stState($fx) + ['all_err' => "Fatal: unable to open config file at $url: 500"]));
                list($code, $l, $out) = bkRun($fx, 'restic', ['list']);
                check($code === 1 && strpos($out, 'Or4cle') === false && strpos(end($l)['error'], 'host:8000') !== false,
                    "an error echoing $url: " . $out);
            } finally {
                stDone($fx);
            }
        }
    },
];
