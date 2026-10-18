<?php
/**
 * Shared by the fake programs in this directory (tests only; no Python). Each fake logs its argv to
 * $FAKE_DIR/argv.log (one JSON line per call) and reads its scripted answers from
 * $FAKE_DIR/state.json, the same pattern as tests/fixtures/storage/bin/fakelib.php.
 */
function fmLog(array $argv)
{
    file_put_contents(getenv('FAKE_DIR') . '/argv.log', json_encode(['bin' => basename($argv[0]), 'argv' => array_slice($argv, 1)],
        JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
}

function fmState()
{
    $s = json_decode((string) @file_get_contents(getenv('FAKE_DIR') . '/state.json'), true);
    return is_array($s) ? $s : [];
}

/** Writes an empty placeholder file; real content does not matter to the code under test. */
function fmTouch($path)
{
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, 'fake');
}
