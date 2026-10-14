<?php
/**
 * Shared by the fake programs in this directory (tests only; no Python). Each fake logs its argv,
 * working directory and the environment names a test cares about to $FAKE_DIR/argv.log (one JSON
 * line per call), and reads its scripted answers from $FAKE_DIR/state.json. Values of password
 * variables are never logged, only whether they are set.
 */
function fakeLog(array $argv, array $extra = [])
{
    $env = [];
    foreach (['RESTIC_REPOSITORY', 'RESTIC_PASSWORD_FILE', 'BORG_REPO', 'BORG_PASSCOMMAND', 'TZ'] as $k) {
        if (getenv($k) !== false) {
            $env[$k] = getenv($k);
        }
    }
    foreach (['RESTIC_PASSWORD', 'RESTIC_PASSWORD_COMMAND', 'BORG_PASSPHRASE'] as $k) {
        $env[$k . '_set'] = getenv($k) !== false;
    }
    $line = ['bin' => basename($argv[0]), 'argv' => array_slice($argv, 1), 'cwd' => getcwd(), 'env' => $env] + $extra;
    file_put_contents(getenv('FAKE_DIR') . '/argv.log', json_encode($line, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function fakeState()
{
    $s = json_decode((string) @file_get_contents(getenv('FAKE_DIR') . '/state.json'), true);
    return is_array($s) ? $s : [];
}

function fakeSave(array $s)
{
    file_put_contents(getenv('FAKE_DIR') . '/state.json', json_encode($s, JSON_UNESCAPED_SLASHES));
}

/** Runs another program (by name through PATH, or by path) with these arguments and exits with its code. */
function fakeExec(array $argv)
{
    passthru(implode(' ', array_map('escapeshellarg', $argv)), $code);
    exit($code);
}

/** The real program $name from the PATH the test started with (for du and find). */
function fakeReal($name)
{
    foreach (explode(':', (string) getenv('FAKE_REAL_PATH')) as $dir) {
        if ($dir !== '' && is_executable("$dir/$name")) {
            return "$dir/$name";
        }
    }
    fwrite(STDERR, "fake: no real $name found\n");
    exit(127);
}

/** argv split as the units build it: leading words, then options, then the paths after `--`. */
function fakeSplit(array $argv)
{
    $a = array_slice($argv, 1);
    $words = [];
    while ($a && $a[0] !== '' && $a[0][0] !== '-') {
        $words[] = array_shift($a);
    }
    $dd = array_search('--', $a, true);
    return [$words, $dd === false ? $a : array_slice($a, 0, $dd), $dd === false ? [] : array_slice($a, $dd + 1)];
}
