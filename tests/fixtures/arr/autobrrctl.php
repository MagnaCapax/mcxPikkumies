<?php
/*
 * A fake autobrrctl for tests/ArrOtherLoginTest.php, copied (with a #! line) to ~/.bin/autobrr/autobrrctl of the fixture
 * home. As cmd/autobrrctl/main.go:173-235: `--config <dir> change-password <user>`, the password read from stdin when
 * stdin is not a terminal (:401-430), log.Fatalf (exit 1) when the user does not exist. It records its argv in ctl.log
 * (the password must never be there) and changes abPassword in the fake autobrr's state. Knob: ctlFail.
 */
$dir = dirname(__DIR__, 3); // __DIR__ is <dir>/home/.bin/autobrr
$file = "$dir/state.json";
$s = json_decode((string) file_get_contents($file), true);
file_put_contents("$dir/ctl.log", json_encode(array_slice($argv, 1)) . "\n", FILE_APPEND);
$line = rtrim((string) fgets(STDIN), "\n");
if (count($argv) !== 5 || $argv[1] !== '--config' || $argv[3] !== 'change-password' || !empty($s['ctlFail'])
    || !isset($s['abUser']) || $argv[4] !== $s['abUser'] || $line === '') {
    fwrite(STDERR, "failed to get user: no rows in result set\n");
    exit(1);
}
$s['abPassword'] = $line;
$s['abConfigDir'] = $argv[2];
file_put_contents($file, json_encode($s));
fwrite(STDERR, "successfully updated password for user \"{$argv[4]}\"\n");
