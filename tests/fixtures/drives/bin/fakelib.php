<?php
// Shared by the fake programs of the drives tests: the fixture state and the call log, both in FAKE_DIR.
function fakeState()
{
    return json_decode((string) file_get_contents(getenv('FAKE_DIR') . '/state.json'), true);
}

function fakeSave(array $state)
{
    file_put_contents(getenv('FAKE_DIR') . '/state.json', json_encode($state, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function fakeLog($bin, array $argv)
{
    file_put_contents(getenv('FAKE_DIR') . '/argv.log', json_encode(['bin' => $bin, 'argv' => $argv]) . "\n", FILE_APPEND | LOCK_EX);
}
